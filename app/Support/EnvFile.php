<?php

namespace App\Support;

use Illuminate\Support\Facades\File;

class EnvFile
{
    /**
     * @param  array<string, string|null>  $values
     */
    public function update(array $values): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        $path = base_path('.env');

        if (! File::exists($path)) {
            File::copy(base_path('.env.example'), $path);
        }

        $content = File::get($path);

        foreach ($values as $key => $value) {
            $content = $this->setValue($content, $key, $value);
        }

        File::put($path, $content);
    }

    private function setValue(string $content, string $key, ?string $value): string
    {
        $line = $key.'='.$this->formatValue($value);
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        if (preg_match($pattern, $content)) {
            return (string) preg_replace($pattern, $line, $content);
        }

        return rtrim($content).PHP_EOL.$line.PHP_EOL;
    }

    private function formatValue(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (preg_match('/\s|#|"|\'/', $value) === 1) {
            return '"'.str_replace('"', '\\"', $value).'"';
        }

        return $value;
    }
}
