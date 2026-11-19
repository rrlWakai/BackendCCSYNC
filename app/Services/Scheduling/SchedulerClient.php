<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use App\Support\SchedulerResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class SchedulerClient
{
    private const SOLVE_PATH = '/api/v1/solve';

    private const LIVE_PATH = '/health/live';

    private const READY_PATH = '/health/ready';

    private readonly ?string $baseUrl;

    public function __construct(
        ?string $url,
        private readonly ?string $apiKey,
        private readonly int $connectTimeout,
        private readonly int $timeout,
    ) {
        $url = is_string($url) ? trim($url) : '';
        $this->baseUrl = $url === '' ? null : rtrim($url, '/');
    }

    public function solve(array $payload): SchedulerResponse
    {
        $configurationError = $this->configurationError(withApiKey: true);
        if ($configurationError !== null) {
            return $configurationError;
        }

        $correlationId = $this->correlationId($payload);

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->retry(0)
                ->post($this->baseUrl.self::SOLVE_PATH, $payload);
        } catch (ConnectionException|RequestException) {
            Log::warning('scheduler.solve.connection_failed', [
                'correlation_id' => $correlationId,
            ]);

            return SchedulerResponse::connectionFailed('Unable to reach the scheduling service.');
        }

        if ($response->successful()) {
            $body = $response->json();

            if (! is_array($body)) {
                Log::warning('scheduler.solve.invalid_json', [
                    'correlation_id' => $correlationId,
                ]);

                return SchedulerResponse::invalidResponse(
                    'Scheduling service returned a non-JSON response.'
                );
            }

            $validationErrors = SchedulerResponseValidator::validate($body, $payload);
            if ($validationErrors !== []) {
                Log::warning('scheduler.solve.invalid_response', [
                    'correlation_id' => $correlationId,
                    'error_count' => count($validationErrors),
                ]);

                return SchedulerResponse::invalidResponse(
                    'Scheduling service returned a response that does not satisfy Scheduling Contract v1.0.',
                    $validationErrors,
                );
            }

            return SchedulerResponse::success($body);
        }

        return $this->mapFailure($response, 'solve', $correlationId);
    }

    public function live(): SchedulerResponse
    {
        $configurationError = $this->configurationError(withApiKey: false);
        if ($configurationError !== null) {
            return $configurationError;
        }

        try {
            $response = Http::withHeaders(['Accept' => 'application/json'])
                ->timeout($this->connectTimeout)
                ->connectTimeout($this->connectTimeout)
                ->retry(0)
                ->get($this->baseUrl.self::LIVE_PATH);
        } catch (ConnectionException|RequestException) {
            Log::warning('scheduler.health.live_connection_failed');

            return SchedulerResponse::connectionFailed('Unable to reach the scheduling service.');
        }

        if (! $response->successful()) {
            return $this->mapFailure($response, 'health.live', null);
        }

        $body = $response->json();
        if (! is_array($body) || ($body['status'] ?? null) !== 'alive') {
            return SchedulerResponse::invalidResponse(
                'Scheduling service liveness response is not valid.'
            );
        }

        return SchedulerResponse::success([
            'status' => 'alive',
        ]);
    }

    public function ready(): SchedulerResponse
    {
        $configurationError = $this->configurationError(withApiKey: true);
        if ($configurationError !== null) {
            return $configurationError;
        }

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'X-CCSync-Key' => $this->apiKey,
            ])
                ->timeout($this->connectTimeout)
                ->connectTimeout($this->connectTimeout)
                ->retry(0)
                ->get($this->baseUrl.self::READY_PATH);
        } catch (ConnectionException|RequestException) {
            Log::warning('scheduler.health.ready_connection_failed');

            return SchedulerResponse::connectionFailed('Unable to reach the scheduling service.');
        }

        if (! $response->successful()) {
            return $this->mapFailure($response, 'health.ready', null);
        }

        $body = $response->json();
        if (! is_array($body) || ($body['status'] ?? null) !== 'ready' || ! is_array($body['checks'] ?? null)) {
            return SchedulerResponse::invalidResponse(
                'Scheduling service readiness response is not valid.'
            );
        }

        return SchedulerResponse::success([
            'status' => 'ready',
            'checks' => $body['checks'],
        ]);
    }

    public function healthCheck(): SchedulerResponse
    {
        return $this->ready();
    }

    private function headers(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-CCSync-Key' => $this->apiKey,
        ];
    }

    private function configurationError(bool $withApiKey): ?SchedulerResponse
    {
        if ($this->baseUrl === null) {
            return SchedulerResponse::serviceUnavailable('SCHEDULER_URL is not configured.');
        }

        if ($withApiKey && ($this->apiKey === null || trim($this->apiKey) === '')) {
            return SchedulerResponse::serviceUnavailable('SCHEDULER_API_KEY is not configured.');
        }

        return null;
    }

    private function correlationId(array $payload): ?string
    {
        $metadata = $payload['metadata'] ?? null;

        return is_array($metadata) && is_string($metadata['correlation_id'] ?? null)
            ? $metadata['correlation_id']
            : null;
    }

    private function mapFailure(
        \Illuminate\Http\Client\Response $response,
        string $operation,
        ?string $correlationId,
    ): SchedulerResponse {
        $status = $response->status();
        $body = $response->json();
        $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];
        $code = is_string($error['code'] ?? null) ? $error['code'] : null;
        $message = is_string($error['message'] ?? null) ? $error['message'] : null;

        Log::warning('scheduler.http_failure', [
            'operation' => $operation,
            'status' => $status,
            'error_code' => $code,
            'correlation_id' => $correlationId,
        ]);

        if ($status === 401 || $status === 403) {
            return SchedulerResponse::authenticationFailed(
                'The scheduling service rejected the service credentials.'
            );
        }

        if ($status === 408 || $status === 504) {
            return SchedulerResponse::timeout('The scheduling service request timed out.');
        }

        if ($status === 422) {
            $details = is_array($error['details'] ?? null) ? $error['details'] : [];

            return SchedulerResponse::invalidContract(
                'The scheduling request does not satisfy Scheduling Contract v1.0.',
                $details,
            );
        }

        if ($status === 400 || $status === 413 || $status === 415) {
            return SchedulerResponse::invalidRequest(
                'The scheduling service rejected the request format.',
                $status,
            );
        }

        if ($status === 503) {
            return SchedulerResponse::serviceUnavailable(
                'The scheduling service is unavailable or not ready.'
            );
        }

        return SchedulerResponse::serviceError(
            $message ?? 'The scheduling service returned an unexpected error.',
            $status,
        );
    }
}
