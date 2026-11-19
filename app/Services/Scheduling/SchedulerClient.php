<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use App\Support\SchedulerResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

final class SchedulerClient
{
    public function __construct(
        private readonly ?string $url,
        private readonly ?string $apiKey,
        private readonly int $connectTimeout,
        private readonly int $timeout,
    ) {}

    /**
     * Send a validated SolveRequest payload to the Python scheduling engine.
     *
     * @param  array<string, mixed>  $payload
     */
    public function solve(array $payload): SchedulerResponse
    {
        if ($this->url === null || $this->url === '') {
            return SchedulerResponse::serviceUnavailable(
                'SCHEDULER_URL is not configured.'
            );
        }

        if ($this->apiKey === null || $this->apiKey === '') {
            return SchedulerResponse::serviceUnavailable(
                'SCHEDULER_API_KEY is not configured.'
            );
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-CCSync-Key' => $this->apiKey,
        ];

        try {
            $response = Http::withHeaders($headers)
                ->timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->retry(0)
                ->post($this->url.'/api/v1/solve', $payload);
        } catch (ConnectionException $exception) {
            return SchedulerResponse::serviceUnavailable(
                'Unable to reach the scheduling service: '.$exception->getMessage()
            );
        } catch (RequestException $exception) {
            return SchedulerResponse::serviceUnavailable(
                'Request to scheduling service failed: '.$exception->getMessage()
            );
        }

        if ($response->successful()) {
            $body = $response->json();

            if (! is_array($body)) {
                return SchedulerResponse::invalidResponse(
                    'Scheduling service returned a non-JSON response.'
                );
            }

            $validationErrors = SchedulerResponseValidator::validate($body, $payload);
            if ($validationErrors !== []) {
                return SchedulerResponse::invalidResponse(
                    'Scheduling service returned an invalid response: '
                    .implode(', ', array_slice($validationErrors, 0, 5))
                );
            }

            return SchedulerResponse::success($body);
        }

        $status = $response->status();
        $body = $response->json();

        if (! is_array($body)) {
            $body = ['message' => $response->body()];
        }

        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $message = (string) ($error['message'] ?? $body['message'] ?? 'Scheduling service returned an unexpected error.');
        $details = is_array($error['details'] ?? null)
            ? $error['details']
            : (is_array($body['details'] ?? null) ? $body['details'] : []);

        return match ($status) {
            401, 403 => SchedulerResponse::authenticationFailed($message),
            408, 504 => SchedulerResponse::timeout($message),
            422 => SchedulerResponse::invalidContract($message, $details),
            503 => SchedulerResponse::serviceUnavailable($message),
            default => SchedulerResponse::serviceError($message, $status),
        };
    }

    /**
     * Validate that the Python service is reachable and ready.
     */
    public function healthCheck(): SchedulerResponse
    {
        if ($this->url === null || $this->url === '') {
            return SchedulerResponse::serviceUnavailable('SCHEDULER_URL is not configured.');
        }

        if ($this->apiKey === null || $this->apiKey === '') {
            return SchedulerResponse::serviceUnavailable('SCHEDULER_API_KEY is not configured.');
        }

        try {
            $response = Http::withHeaders(['X-CCSync-Key' => $this->apiKey])
                ->timeout($this->connectTimeout)
                ->connectTimeout($this->connectTimeout)
                ->get($this->url.'/health/ready');
        } catch (ConnectionException $exception) {
            return SchedulerResponse::serviceUnavailable(
                'Unable to reach the scheduling service: '.$exception->getMessage()
            );
        }

        if ($response->successful()) {
            return SchedulerResponse::success($response->json() ?? []);
        }

        return SchedulerResponse::serviceUnavailable(
            'Scheduling service reported: '.$response->body()
        );
    }
}
