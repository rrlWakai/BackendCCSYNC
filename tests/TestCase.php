<?php

namespace Tests;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function assertQueryFails(callable $callback, string $message = 'Expected the database query to fail.'): void
    {
        try {
            $callback();
        } catch (QueryException) {
            $this->assertTrue(true);

            return;
        }

        $this->fail($message);
    }
}
