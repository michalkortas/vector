<?php

namespace Database\Seeders;

use App\Planning\Jobs\SeedDemoData;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        SeedDemoData::dispatchSync((string) config('demo.scenario', SeedDemoData::MEDICAL));
    }
}
