<?php

namespace Database\Seeders\Concerns;

use RuntimeException;

trait RefuseProtectedDatabase
{
    protected function refuseProtectedDatabase(): void
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

          if ($database === 'ccsync_db' && ! env('ALLOW_PROTECTED_DB_SEEDING', false)) {
            throw new RuntimeException(
                'Refusing to run seeders against the protected ccsync_db database.',
            );
        }
    }
}
