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

        $this->reportRecentLogErrors(storage_path('logs/laravel.log'));

        return self::SUCCESS;
    }

    private function reportRecentLogErrors(string $logPath): void
    {
        if (! is_file($logPath)) {
            return;
        }

        $handle = fopen($logPath, 'rb');

        if ($handle === false) {
            return;
        }

        $chunkSize = 8192;
        $buffer = '';

        fseek($handle, 0, SEEK_END);
        $position = ftell($handle);

        while ($position > 0 && strlen($buffer) < 512000) {
            $readSize = min($chunkSize, $position);
            $position -= $readSize;
            fseek($handle, $position);
            $buffer = fread($handle, $readSize).$buffer;
        }

        fclose($handle);

        $errorLines = collect(preg_split('/\R/', $buffer))
            ->filter(fn (string $line): bool => preg_match('/^\[\d{4}-\d{2}-\d{2} .+\.(ERROR|CRITICAL|EMERGENCY|ALERT):/', $line) === 1)
            ->take(-3)
            ->values();

        $this->newLine();

        if ($errorLines->isEmpty()) {
            $this->info('Recent log errors: none found.');

            return;
        }

        $this->info('Recent log errors:');

        $errorLines->each(function (string $line): void {
            $message = preg_replace('/^\[\d{4}-\d{2}-\d{2} .+\.(ERROR|CRITICAL|EMERGENCY|ALERT): /', '', $line) ?? $line;
            $message = preg_split('/\s+\{/', $message, 2)[0] ?? $message;

            $this->line('- '.trim($message));
        });
    }
}
