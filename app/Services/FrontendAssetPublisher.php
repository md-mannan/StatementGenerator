<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

class FrontendAssetPublisher
{
    public function shouldSkipPublishing(): bool
    {
        return File::exists(public_path('hot'));
    }

    public function canPublish(): bool
    {
        return extension_loaded('zip')
            && class_exists(ZipArchive::class)
            && File::exists(public_path('build.zip'));
    }

    public function publish(bool $force = false): bool
    {
        if ($this->shouldSkipPublishing()) {
            return false;
        }

        if (! $this->canPublish()) {
            return false;
        }

        $buildPath = public_path('build');

        if (! $force && File::isDirectory($buildPath) && File::exists($buildPath.'/manifest.json')) {
            return false;
        }

        $zipPath = public_path('build.zip');
        $temporaryPath = public_path('build-tmp-'.uniqid('', true));

        File::ensureDirectoryExists($temporaryPath);

        try {
            $zip = new ZipArchive;

            if ($zip->open($zipPath) !== true) {
                return false;
            }

            if (! $zip->extractTo($temporaryPath)) {
                $zip->close();

                return false;
            }

            $zip->close();

            if (! File::exists($temporaryPath.'/manifest.json')) {
                return false;
            }

            if (File::isDirectory($buildPath)) {
                File::deleteDirectory($buildPath);
            }

            File::moveDirectory($temporaryPath, $buildPath);

            return true;
        } finally {
            if (File::isDirectory($temporaryPath)) {
                File::deleteDirectory($temporaryPath);
            }
        }
    }

    public function archive(): string
    {
        if (! extension_loaded('zip') || ! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required to create public/build.zip.');
        }

        $buildPath = public_path('build');

        if (! File::isDirectory($buildPath) || ! File::exists($buildPath.'/manifest.json')) {
            throw new RuntimeException('Run npm run build before creating public/build.zip.');
        }

        $zipPath = public_path('build.zip');

        if (File::exists($zipPath)) {
            File::delete($zipPath);
        }

        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create public/build.zip.');
        }

        foreach (File::allFiles($buildPath) as $file) {
            $relativePath = str_replace('\\', '/', $file->getRelativePathname());

            if (! $zip->addFile($file->getPathname(), $relativePath)) {
                $zip->close();

                throw new RuntimeException('Unable to add files to public/build.zip.');
            }
        }

        $zip->close();

        return $zipPath;
    }
}
