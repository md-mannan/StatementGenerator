<?php

namespace App\Console\Commands;

use App\Services\FrontendAssetPublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PublishFrontendAssetsCommand extends Command
{
    protected $signature = 'frontend:publish
                            {--force : Replace an existing public/build directory}
                            {--archive : Create public/build.zip from public/build instead of extracting it}';

    protected $description = 'Publish compiled frontend assets for production (extract public/build.zip to public/build)';

    public function handle(FrontendAssetPublisher $publisher): int
    {
        if ($this->option('archive')) {
            $path = $publisher->archive();

            $this->components->info("Created {$path}");

            return self::SUCCESS;
        }

        if ($publisher->shouldSkipPublishing()) {
            $this->components->warn('Skipped: Vite dev server is active (public/hot exists).');

            return self::SUCCESS;
        }

        if (! $publisher->canPublish()) {
            if (! extension_loaded('zip')) {
                $this->components->warn('Skipped: PHP zip extension is not enabled.');
            } else {
                $this->components->warn('Skipped: public/build.zip was not found.');
            }

            return self::SUCCESS;
        }

        if (! $publisher->publish(force: (bool) $this->option('force'))) {
            if ($publisher->shouldSkipPublishing()) {
                $this->components->warn('Skipped: Vite dev server is active (public/hot exists).');
            } elseif (File::exists(public_path('build/manifest.json'))) {
                $this->components->warn('Skipped: public/build already exists. Use --force to replace it.');
            } else {
                $this->components->error('Unable to publish frontend assets. public/build was left unchanged.');
            }

            return File::exists(public_path('build/manifest.json')) ? self::SUCCESS : self::FAILURE;
        }

        $this->components->info('Published frontend assets to public/build.');

        return self::SUCCESS;
    }
}
