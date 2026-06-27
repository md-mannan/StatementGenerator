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

        $result = $publisher->publish(force: (bool) $this->option('force'));

        if ($result->success) {
            $this->components->info($result->message);

            return self::SUCCESS;
        }

        if (File::exists(public_path('build/manifest.json'))) {
            $this->components->warn($result->message);

            return self::SUCCESS;
        }

        $this->components->error($result->message);
        $this->line('Restore manually with cPanel File Manager: extract public/build.zip into public/build/.');

        return self::FAILURE;
    }
}
