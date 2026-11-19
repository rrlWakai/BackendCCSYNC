<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use Illuminate\Support\Arr;

final class SnapshotHasher
{
    /**
     * Compute the canonical snapshot hash for a contract request.
     *
     * The contract excludes contract_version and metadata, sorts object keys
     * recursively, and serialises compact UTF-8 JSON.
     *
     * @param  array<string, mixed>  $request
     */
    public static function hash(array $request): string
    {
        $snapshot = Arr::except($request, ['contract_version', 'metadata']);

        return hash('sha256', self::canonicalJson($snapshot));
    }

    public static function canonicalJson(mixed $value): string
    {
        return json_encode(
            self::normalise($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    private static function normalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::normalise(...), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = self::normalise($item);
        }

        return $value;
    }
}
