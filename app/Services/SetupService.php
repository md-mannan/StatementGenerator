<?php

namespace App\Services;

use App\Models\User;
use App\Support\EnvFile;
use App\Support\Installation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PDO;
use PDOException;

class SetupService
{
    public function __construct(
        private readonly EnvFile $envFile,
        private readonly SetupRequirementsChecker $requirementsChecker,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function testDatabaseConnection(array $data): void
    {
        $this->assertRequirementsMet();

        if (app()->runningUnitTests()) {
            DB::connection()->getPdo();

            return;
        }

        $this->connect(
            host: (string) $data['db_host'],
            port: (string) $data['db_port'],
            database: (string) $data['db_database'],
            username: (string) $data['db_username'],
            password: (string) ($data['db_password'] ?? ''),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function install(array $data): User
    {
        $this->assertRequirementsMet();

        $this->testDatabaseConnection($data);

        $envValues = $this->buildEnvValues($data);

        $this->applyRuntimeConfiguration($data);
        $this->purgeDatabaseConnection();

        try {
            Artisan::call('migrate', ['--force' => true]);
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages([
                'db_database' => 'Migrations failed: '.$exception->getMessage(),
            ]);
        }

        if (User::query()->where('email', $data['email'])->exists()) {
            $this->finalizeInstallation($envValues);

            throw ValidationException::withMessages([
                'email' => 'An account with this email already exists. Sign in instead.',
            ]);
        }

        $user = User::query()->create([
            'name' => (string) $data['name'],
            'email' => (string) $data['email'],
            'password' => Hash::make((string) $data['password']),
            'email_verified_at' => now(),
        ]);

        $this->finalizeInstallation($envValues);

        Auth::login($user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    private function buildEnvValues(array $data): array
    {
        $appUrl = rtrim((string) $data['app_url'], '/');

        $values = [
            'APP_NAME' => (string) $data['app_name'],
            'APP_URL' => $appUrl,
            'VITE_APP_NAME' => (string) $data['app_name'],
            'SESSION_SECURE_COOKIE' => '',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) $data['db_host'],
            'DB_PORT' => (string) $data['db_port'],
            'DB_DATABASE' => (string) $data['db_database'],
            'DB_USERNAME' => (string) $data['db_username'],
            'DB_PASSWORD' => (string) ($data['db_password'] ?? ''),
        ];

        return $values;
    }

    /**
     * @param  array<string, string|null>  $envValues
     */
    private function finalizeInstallation(array $envValues): void
    {
        Installation::markInstalled();

        if (app()->runningUnitTests()) {
            return;
        }

        $this->persistEnvironmentAfterInstall($envValues);
    }

    /**
     * @param  array<string, string|null>  $envValues
     */
    private function persistEnvironmentAfterInstall(array $envValues): void
    {
        $values = [
            ...$envValues,
            'APP_INSTALLED' => 'true',
        ];

        if ($this->shouldDeferEnvWrite()) {
            app()->terminating(fn () => $this->persistEnvironment($values));

            return;
        }

        $this->persistEnvironment($values);
    }

    private function shouldDeferEnvWrite(): bool
    {
        return PHP_SAPI === 'cli-server';
    }

    /**
     * @param  array<string, string|null>  $envValues
     */
    private function persistEnvironment(array $envValues): void
    {
        $this->envFile->update($envValues);

        $configCachePath = base_path('bootstrap/cache/config.php');

        if (File::exists($configCachePath)) {
            File::delete($configCachePath);
        }

        if (blank(config('app.key'))) {
            Artisan::call('key:generate', ['--force' => true]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyRuntimeConfiguration(array $data): void
    {
        $appUrl = rtrim((string) $data['app_url'], '/');

        $runtimeConfig = [
            'app.name' => (string) $data['app_name'],
            'app.url' => $appUrl,
            'app.installed' => true,
            'database.connections.mysql.host' => (string) $data['db_host'],
            'database.connections.mysql.port' => (string) $data['db_port'],
            'database.connections.mysql.database' => (string) $data['db_database'],
            'database.connections.mysql.username' => (string) $data['db_username'],
            'database.connections.mysql.password' => (string) ($data['db_password'] ?? ''),
        ];

        config($runtimeConfig);
    }

    private function assertRequirementsMet(): void
    {
        if (! $this->requirementsChecker->check()['ready']) {
            throw ValidationException::withMessages([
                'requirements' => 'Server requirements are not met. Fix the issues listed on step one.',
            ]);
        }
    }

    private function connect(
        string $host,
        string $port,
        string $database,
        string $username,
        string $password,
    ): void {
        try {
            $connection = new PDO(
                "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 5,
                ],
            );

            $connection->query('SELECT 1');
        } catch (PDOException $exception) {
            throw ValidationException::withMessages([
                'db_password' => 'Could not connect to the database. Check your host, username, and password.',
                'db_database' => 'Could not connect to the database. Check your credentials and try again.',
                'db_connection' => $exception->getMessage(),
            ]);
        }
    }

    private function purgeDatabaseConnection(): void
    {
        DB::purge('mysql');
        DB::disconnect('mysql');
    }
}
