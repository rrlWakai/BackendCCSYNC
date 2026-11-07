<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseConnectionTest extends TestCase
{
    public function test_laravel_connects_to_the_configured_mysql_database(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame(config('database.connections.mysql.database'), DB::connection()->getDatabaseName());

        $version = DB::selectOne('SELECT VERSION() AS version')->version;

        $this->assertNotSame('', $version);
    }
}
