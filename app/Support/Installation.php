<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\File;

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

    public static function markInstalled(): void
    {
        File::ensureDirectoryExists(storage_path('app'));

        File::put(self::markerPath(), now()->toIso8601String());
    }

    private static function detectLegacyInstallation(): bool
    {
        try {
            if (User::query()->exists()) {
                self::markInstalled();

                return true;
            }
        } catch (\Throwable) {
            // Database is not configured yet.
        }

        return false;
    }
}
