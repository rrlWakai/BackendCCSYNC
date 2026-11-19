<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

final class SchedulingRequestBuildResult
{
    /**
     * @param  array<string, mixed>|null  $payload
     * @param  list<array<string, mixed>>  $issues
     */
    private function __construct(
        private readonly ?array $payload,
        private readonly array $issues,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function success(array $payload): self
    {
        return new self($payload, []);
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     */
    public static function failure(array $issues): self
    {
        return new self(null, $issues);
    }

    public function isSuccessful(): bool
    {
        return $this->payload !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function issues(): array
    {
        return $this->issues;
    }
}
