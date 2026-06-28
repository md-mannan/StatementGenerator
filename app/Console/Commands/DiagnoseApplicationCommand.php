<?php

namespace App\Console\Commands;

use App\Models\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DiagnoseApplicationCommand extends Command
{
    protected $signature = 'app:diagnose';

    protected $description = 'Check database tables and basic queries used by the clients page';

    public function handle(): int
    {
        $this->info('PHP version: '.PHP_VERSION);
        $this->info('Database driver: '.DB::connection()->getDriverName());

        foreach (['users', 'clients', 'branches', 'statement_entries'] as $table) {
            $exists = Schema::hasTable($table);
            $count = $exists ? DB::table($table)->count() : 0;
            $this->line(sprintf('- %s: %s (%d rows)', $table, $exists ? 'OK' : 'MISSING', $count));
        }

        $manifestPath = public_path('build/manifest.json');
        $this->line(sprintf(
            '- public/build/manifest.json: %s',
            is_file($manifestPath) ? 'OK' : 'MISSING'
        ));

        try {
            $clientCount = Client::query()->withCount('branches')->count();
            $this->info("Client query OK ({$clientCount} clients).");
        } catch (\Throwable $exception) {
            $this->error('Client query failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $logPath = storage_path('logs/laravel.log');

        if (is_file($logPath)) {
            $this->newLine();
            $this->info('Last 5 log lines:');

            $lines = collect(preg_split('/\R/', (string) tail($logPath, 5) ?: ''))
                ->filter(fn (string $line): bool => $line !== '');

            $lines->each(fn (string $line) => $this->line($line));
        }

        return self::SUCCESS;
    }
}
