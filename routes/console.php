<?php

use App\Planning\Jobs\SeedDemoData;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('demo:seed {scenario=medical : medical albo vehicles}', function (): int {
    $scenario = (string) $this->argument('scenario');

    try {
        SeedDemoData::dispatchSync($scenario);
    } catch (InvalidArgumentException $exception) {
        $this->error($exception->getMessage());

        return Command::FAILURE;
    }

    $this->info("Załadowano dane demo: {$scenario}.");

    return Command::SUCCESS;
})->purpose('Ładuje wybrany scenariusz danych demonstracyjnych');
