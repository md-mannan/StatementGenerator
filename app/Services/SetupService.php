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

        Installation::markInstalled();

        if (! app()->runningUnitTests()) {
            $this->scheduleEnvironmentPersistence($envValues);
        }

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
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) $data['db_host'],
            'DB_PORT' => (string) $data['db_port'],
            'DB_DATABASE' => (string) $data['db_database'],
            'DB_USERNAME' => (string) $data['db_username'],
            'DB_PASSWORD' => (string) ($data['db_password'] ?? ''),
        ];

        if (str_starts_with($appUrl, 'https://')) {
            $values['SESSION_SECURE_COOKIE'] = 'true';
        }

        return $values;
    }

    /**
     * Write .env after the HTTP response is sent so php artisan serve does not
     * reset the connection mid-install.
     *
     * @param  array<string, string|null>  $envValues
     */
    private function scheduleEnvironmentPersistence(array $envValues): void
    {
        app()->terminating(function () use ($envValues): void {
            $this->persistEnvironment([
                ...$envValues,
                'APP_INSTALLED' => 'true',
            ]);
        });
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
        config([
            'app.name' => (string) $data['app_name'],
            'app.url' => rtrim((string) $data['app_url'], '/'),
            'app.installed' => true,
            'database.connections.mysql.host' => (string) $data['db_host'],
            'database.connections.mysql.port' => (string) $data['db_port'],
            'database.connections.mysql.database' => (string) $data['db_database'],
            'database.connections.mysql.username' => (string) $data['db_username'],
            'database.connections.mysql.password' => (string) ($data['db_password'] ?? ''),
        ]);
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
