<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Nothing: organizations, members and roles are the platform's
     * (platform/catalog.json is imported there). Demo data goes into a
     * workspace with `php artisan projects:demo <slug>` (AsaasDemoSeeder).
     */
    public function run(): void {}
}
