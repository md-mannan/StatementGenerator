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

    public function publish(bool $force = false): FrontendPublishResult
    {
        if ($this->shouldSkipPublishing()) {
            return new FrontendPublishResult(
                success: false,
                message: 'Skipped: Vite dev server is active (public/hot exists).',
            );
        }

        if (! extension_loaded('zip') || ! class_exists(ZipArchive::class)) {
            return new FrontendPublishResult(
                success: false,
                message: 'The PHP zip extension is not enabled on this server.',
            );
        }

        $zipPath = public_path('build.zip');

        if (! File::exists($zipPath)) {
            return new FrontendPublishResult(
                success: false,
                message: 'public/build.zip was not found.',
            );
        }

        $buildPath = public_path('build');

        if (! $force && File::isDirectory($buildPath) && File::exists($buildPath.'/manifest.json')) {
            return new FrontendPublishResult(
                success: false,
                message: 'public/build already exists. Use --force to replace it.',
            );
        }

        $temporaryPath = public_path('build-tmp-'.uniqid('', true));

        File::ensureDirectoryExists($temporaryPath);

        try {
            $zip = new ZipArchive;
            $openResult = $zip->open($zipPath);

            if ($openResult !== true) {
                return new FrontendPublishResult(
                    success: false,
                    message: 'Unable to open public/build.zip (it may be corrupt or incomplete).',
                );
            }

            if (! $zip->extractTo($temporaryPath)) {
                $zip->close();

                return new FrontendPublishResult(
                    success: false,
                    message: 'Unable to extract public/build.zip. Check folder permissions on public/.',
                );
            }

            $zip->close();

            $sourcePath = $this->resolveExtractedBuildPath($temporaryPath);

            if ($sourcePath === null) {
                return new FrontendPublishResult(
                    success: false,
                    message: 'public/build.zip does not contain a valid Vite manifest.json file.',
                );
            }

            if (! $this->swapBuildDirectory($sourcePath, $buildPath)) {
                return new FrontendPublishResult(
                    success: false,
                    message: 'Unable to move extracted assets into public/build. The previous build was restored if one existed.',
                );
            }

            return new FrontendPublishResult(
                success: true,
                message: 'Published frontend assets to public/build.',
            );
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

    private function resolveExtractedBuildPath(string $temporaryPath): ?string
    {
        if (File::exists($temporaryPath.'/manifest.json')) {
            return $temporaryPath;
        }

        $nestedBuildPath = $temporaryPath.'/build';

        if (File::exists($nestedBuildPath.'/manifest.json')) {
            return $nestedBuildPath;
        }

        return null;
    }

    private function swapBuildDirectory(string $sourcePath, string $buildPath): bool
    {
        $previousPath = public_path('build-previous-'.uniqid('', true));
        $hadPreviousBuild = File::isDirectory($buildPath);

        if ($hadPreviousBuild) {
            if (! @rename($buildPath, $previousPath)) {
                return false;
            }
        }

        if (! @rename($sourcePath, $buildPath)) {
            if ($hadPreviousBuild && File::isDirectory($previousPath)) {
                @rename($previousPath, $buildPath);
            }

            return false;
        }

        if ($hadPreviousBuild && File::isDirectory($previousPath)) {
            File::deleteDirectory($previousPath);
        }

        return File::exists($buildPath.'/manifest.json');
    }
}
