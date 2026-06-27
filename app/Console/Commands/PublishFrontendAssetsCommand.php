<?php

namespace App\Console\Commands;

use App\Services\FrontendAssetPublisher;
use Illuminate\Console\Command;

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

        if (! file_exists(public_path('build.zip'))) {
            $this->components->error('public/build.zip was not found. Run npm run build:release locally first.');

            return self::FAILURE;
        }

        if (! $publisher->publish(force: (bool) $this->option('force'))) {
            $this->components->warn('Skipped: public/build already exists. Use --force to replace it.');

            return self::SUCCESS;
        }

        $this->components->info('Published frontend assets to public/build.');

        return self::SUCCESS;
    }
}
