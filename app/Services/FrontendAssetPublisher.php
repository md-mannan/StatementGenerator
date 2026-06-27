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

    public function publish(bool $force = false): bool
    {
        if ($this->shouldSkipPublishing()) {
            return false;
        }

        $zipPath = public_path('build.zip');

        if (! File::exists($zipPath)) {
            return false;
        }

        $buildPath = public_path('build');

        if (! $force && File::isDirectory($buildPath) && File::exists($buildPath.'/manifest.json')) {
            return false;
        }

        if (File::isDirectory($buildPath)) {
            File::deleteDirectory($buildPath);
        }

        File::ensureDirectoryExists($buildPath);

        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Unable to open the frontend build archive at public/build.zip.');
        }

        if (! $zip->extractTo($buildPath)) {
            $zip->close();

            throw new RuntimeException('Unable to extract the frontend build archive to public/build.');
        }

        $zip->close();

        return true;
    }

    public function archive(): string
    {
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
