<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Product data belongs to workspaces and arrives through provisioning
     * (App\Platform\SetUpWorkspace), not seeds.
     */
    public function run(): void {}
}
