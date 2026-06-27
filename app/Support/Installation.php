<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\File;
use RuntimeException;

class Installation
{
    private static bool $forceUninstalledForTesting = false;

    public static function markerPath(): string
    {
        return storage_path('app/installed');
    }

    public static function forceUninstalledForTesting(bool $force = true): void
    {
        if (! app()->runningUnitTests()) {
            return;
        }

        self::$forceUninstalledForTesting = $force;
    }

    public static function isInstalled(): bool
    {
        if (app()->runningUnitTests()) {
            if (File::exists(self::markerPath())) {
                return true;
            }

            return ! self::$forceUninstalledForTesting;
        }

        if (File::exists(self::markerPath())) {
            return true;
        }

        return self::detectLegacyInstallation();
    }

    /**
     * Keep installation state consistent on each web request.
     */
    public static function syncMarker(): void
    {
        if (app()->runningUnitTests() || app()->runningInConsole()) {
            return;
        }

        if (File::exists(self::markerPath())) {
            if (! self::envFlagIsTrue()) {
                self::writeInstalledFlagToEnvironment(true);
            }

            return;
        }

        if (self::detectLegacyInstallation()) {
            return;
        }

        if (self::envFlagIsTrue()) {
            self::resetInstalledFlagInEnvironment();
        }
    }

    public static function markInstalled(): void
    {
        File::ensureDirectoryExists(storage_path('app'));

        if (File::put(self::markerPath(), now()->toIso8601String()) === false) {
            throw new RuntimeException(
                'Could not write the installation marker. Make sure storage/app is writable.',
            );
        }

        config(['app.installed' => true]);
    }

    private static function detectLegacyInstallation(): bool
    {
        try {
            if (User::query()->exists()) {
                self::markInstalled();
                self::persistInstalledFlagInEnvironment();

                return true;
            }
        } catch (\Throwable) {
            // Database is not configured yet.
        }

        return false;
    }

    private static function persistInstalledFlagInEnvironment(): void
    {
        if (app()->runningUnitTests() || self::envFlagIsTrue()) {
            return;
        }

        if (self::shouldDeferEnvWrite()) {
            app()->terminating(fn () => self::writeInstalledFlagToEnvironment(true));

            return;
        }

        self::writeInstalledFlagToEnvironment(true);
    }

    private static function resetInstalledFlagInEnvironment(): void
    {
        config(['app.installed' => false]);

        if (self::shouldDeferEnvWrite()) {
            app()->terminating(fn () => self::writeInstalledFlagToEnvironment(false));

            return;
        }

        self::writeInstalledFlagToEnvironment(false);
    }

    private static function writeInstalledFlagToEnvironment(bool $installed): void
    {
        app(EnvFile::class)->update([
            'APP_INSTALLED' => $installed ? 'true' : 'false',
        ]);

        $configCachePath = base_path('bootstrap/cache/config.php');

        if (File::exists($configCachePath)) {
            File::delete($configCachePath);
        }
    }

    private static function envFlagIsTrue(): bool
    {
        return filter_var(env('APP_INSTALLED', false), FILTER_VALIDATE_BOOLEAN);
    }

    private static function shouldDeferEnvWrite(): bool
    {
        return PHP_SAPI === 'cli-server';
    }
}
