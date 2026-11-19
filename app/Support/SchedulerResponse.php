<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Arr;

final class SchedulerResponse
{
    private function __construct(
        private readonly bool $ok,
        private readonly string $message,
        private readonly ?array $body = null,
        private readonly ?string $errorCode = null,
        private readonly ?int $httpStatus = null,
    ) {
    }

    public static function success(array $body): self
    {
        return new self(
            ok: true,
            message: 'Scheduling service responded successfully.',
            body: $body,
        );
    }

    public static function serviceUnavailable(string $message): self
    {
        return new self(
            ok: false,
            message: $message,
            errorCode: 'scheduler_service_unavailable',
            httpStatus: 503,
        );
    }

    public static function connectionFailed(string $message): self
    {
        return new self(
            ok: false,
            message: $message,
            errorCode: 'scheduler_unreachable',
            httpStatus: 503,
        );
    }

    public static function authenticationFailed(string $message): self
    {
        return new self(
            ok: false,
            message: $message,
            errorCode: 'scheduler_authentication_failed',
            httpStatus: 401,
        );
    }

    public static function timeout(string $message): self
    {
        return new self(
            ok: false,
            message: $message,
            errorCode: 'scheduler_timeout',
            httpStatus: 504,
        );
    }

    public static function invalidContract(string $message, array $details = []): self
    {
        return new self(
            ok: false,
            message: $message,
            errorCode: 'invalid_contract',
            httpStatus: 422,
            body: ['details' => $details],
        );
    }

    public static function invalidRequest(string $message, int $status): self
    {
        return new self(
            ok: false,
            message: $message,
            errorCode: 'scheduler_invalid_request',
            httpStatus: $status >= 400 && $status < 500 ? $status : 422,
        );
    }

    public static function invalidResponse(string $message, array $details = []): self
    {
        return new self(
            ok: false,
            message: $message,
            errorCode: 'invalid_response',
            httpStatus: 502,
            body: ['details' => $details],
        );
    }

    public static function serviceError(string $message, int $status): self
    {
        return new self(
            ok: false,
            message: $message,
            errorCode: 'scheduler_error',
            httpStatus: $status,
        );
    }

    public function isOk(): bool
    {
        return $this->ok;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getBody(): ?array
    {
        return $this->body;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return Arr::except(get_object_vars($this), ['body']);
    }
}