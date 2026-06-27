<?php

use App\Services\FrontendAssetPublisher;
use Illuminate\Support\Facades\File;

test('frontend publish extracts build zip into public build directory', function () {
    $buildPath = public_path('build-test-'.uniqid('', true));
    $zipPath = public_path('build-test-'.uniqid('', true).'.zip');

    File::ensureDirectoryExists($buildPath.'/assets/js');
    File::put($buildPath.'/manifest.json', '{"app.js":"assets/js/app.js"}');
    File::put($buildPath.'/assets/js/app.js', 'console.log("backup");');

    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFile($buildPath.'/manifest.json', 'manifest.json');
    $zip->addFile($buildPath.'/assets/js/app.js', 'assets/js/app.js');
    $zip->close();

    File::deleteDirectory($buildPath);

    $originalZip = public_path('build.zip');
    $originalBuild = public_path('build');
    $hadOriginalZip = File::exists($originalZip);
    $hadOriginalBuild = File::isDirectory($originalBuild);
    $backupZip = null;
    $backupBuild = null;

    if ($hadOriginalZip) {
        $backupZip = public_path('build-backup-'.uniqid('', true).'.zip');
        File::copy($originalZip, $backupZip);
    }

    if ($hadOriginalBuild) {
        $backupBuild = public_path('build-backup-'.uniqid('', true));
        File::moveDirectory($originalBuild, $backupBuild);
    }

    File::copy($zipPath, $originalZip);

    try {
        $published = app(FrontendAssetPublisher::class)->publish(force: true);

        expect($published->success)->toBeTrue()
            ->and(File::exists($originalBuild.'/manifest.json'))->toBeTrue()
            ->and(File::get($originalBuild.'/assets/js/app.js'))->toBe('console.log("backup");');
    } finally {
        File::delete($zipPath);

        if (File::isDirectory($originalBuild)) {
            File::deleteDirectory($originalBuild);
        }

        if ($backupBuild !== null) {
            File::moveDirectory($backupBuild, $originalBuild);
        }

        if ($hadOriginalZip && $backupZip !== null) {
            File::move($backupZip, $originalZip);
        } elseif (File::exists($originalZip)) {
            File::delete($originalZip);
        }
    }
});

test('frontend publish keeps existing build when archive is invalid', function () {
    $buildPath = public_path('build');
    $zipPath = public_path('build.zip');
    $manifest = '{"app.js":"assets/js/app.js"}';

    File::ensureDirectoryExists($buildPath);
    File::put($buildPath.'/manifest.json', $manifest);

    $backupZip = null;

    if (File::exists($zipPath)) {
        $backupZip = public_path('build-backup-'.uniqid('', true).'.zip');
        File::copy($zipPath, $backupZip);
    }

    File::put($zipPath, 'not-a-valid-zip');

    try {
        $published = app(FrontendAssetPublisher::class)->publish(force: true);

        expect($published->success)->toBeFalse()
            ->and(File::get($buildPath.'/manifest.json'))->toBe($manifest);
    } finally {
        File::delete($zipPath);

        if ($backupZip !== null) {
            File::move($backupZip, $zipPath);
        }
    }
});

test('frontend publish skips while vite hot file exists', function () {
    $hotPath = public_path('hot');

    File::put($hotPath, 'http://localhost:5173');

    try {
        expect(app(FrontendAssetPublisher::class)->publish(force: true)->success)->toBeFalse();
    } finally {
        File::delete($hotPath);
    }
});
