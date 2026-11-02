<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Safe to run repeatedly (idempotent). Creates NO user accounts: use `php artisan agriscan:create-admin`. */
    public function run(): void
    {
        $this->call([ReferenceSeeder::class, AdvisorySeeder::class]);
    }
}
