<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * SitePulse does not create a default or predictable user account during seeding.
     * Initial owner provisioning must happen explicitly through the dedicated CLI flow.
     */
    public function run(): void
    {
        // Intentionally left empty to prevent predictable credentials in any environment.
    }
}
