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

        if (config('app.installed') === true) {
            self::ensureMarkerExists();

            return true;
        }

        if (filter_var(env('APP_INSTALLED', false), FILTER_VALIDATE_BOOLEAN)) {
            self::ensureMarkerExists();

            return true;
        }

        return self::detectLegacyInstallation();
    }

    /**
     * Ensure storage/app/installed exists whenever the app is already installed.
     * Runs automatically on each web request until the marker file is present.
     */
    public static function syncMarker(): void
    {
        if (app()->runningUnitTests() || app()->runningInConsole()) {
            return;
        }

        if (File::exists(self::markerPath())) {
            return;
        }

        if (config('app.installed') === true) {
            self::ensureMarkerExists();

            return;
        }

        self::detectLegacyInstallation();
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

    /**
     * Create the marker file when the app is already marked installed in .env
     * or the database, but storage/app/installed is missing.
     */
    private static function ensureMarkerExists(): void
    {
        if (File::exists(self::markerPath())) {
            return;
        }

        try {
            self::markInstalled();
        } catch (RuntimeException) {
            // Config says installed; do not block requests if the marker cannot be written.
        }
    }

    private static function detectLegacyInstallation(): bool
    {
        try {
            if (User::query()->exists()) {
                self::markInstalled();
                self::scheduleInstalledFlagInEnvironment();

                return true;
            }
        } catch (\Throwable) {
            // Database is not configured yet.
        }

        return false;
    }

    private static function scheduleInstalledFlagInEnvironment(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        if (self::shouldDeferEnvWrite()) {
            app()->terminating(function (): void {
                self::persistInstalledFlagInEnvironment();
            });

            return;
        }

        self::persistInstalledFlagInEnvironment();
    }

    private static function persistInstalledFlagInEnvironment(): void
    {
        if (filter_var(env('APP_INSTALLED', false), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        app(EnvFile::class)->update(['APP_INSTALLED' => 'true']);

        $configCachePath = base_path('bootstrap/cache/config.php');

        if (File::exists($configCachePath)) {
            File::delete($configCachePath);
        }
    }

    private static function shouldDeferEnvWrite(): bool
    {
        return PHP_SAPI === 'cli-server';
    }
}
