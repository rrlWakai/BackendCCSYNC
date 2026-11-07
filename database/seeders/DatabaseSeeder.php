<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\RefuseProtectedDatabase;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use RefuseProtectedDatabase;

    public function run(): void
    {
        $this->refuseProtectedDatabase();

        $this->call([
            RoleSeeder::class,
            ReferenceDataSeeder::class,
            DevelopmentAccountSeeder::class,
        ]);
    }
}
