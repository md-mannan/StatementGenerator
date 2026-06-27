<?php

namespace App\Services;

class SetupRequirementsChecker
{
    private const MIN_PHP_VERSION = '8.3.0';

    /**
     * @return array{
     *     php_version: array{label: string, passed: bool, message: string},
     *     extensions: list<array{label: string, passed: bool, message: string}>,
     *     permissions: list<array{label: string, passed: bool, message: string}>,
     *     ready: bool,
     * }
     */
    public function check(): array
    {
        $phpVersion = [
            'label' => 'PHP '.self::MIN_PHP_VERSION.' or higher',
            'passed' => version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '>='),
            'message' => 'Current version: '.PHP_VERSION,
        ];

        $extensions = collect([
            'pdo',
            'pdo_mysql',
            'mbstring',
            'openssl',
            'tokenizer',
            'xml',
            'ctype',
            'json',
            'fileinfo',
        ])->map(fn (string $extension): array => [
            'label' => $extension.' extension',
            'passed' => extension_loaded($extension),
            'message' => extension_loaded($extension) ? 'Enabled' : 'Missing',
        ])->all();

        $permissions = collect([
            storage_path(),
            storage_path('app'),
            storage_path('framework'),
            storage_path('logs'),
            base_path('bootstrap/cache'),
        ])->map(fn (string $path): array => [
            'label' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $path),
            'passed' => is_writable($path),
            'message' => is_writable($path) ? 'Writable' : 'Not writable',
        ])->all();

        $ready = $phpVersion['passed']
            && collect($extensions)->every(fn (array $item): bool => $item['passed'])
            && collect($permissions)->every(fn (array $item): bool => $item['passed']);

        return [
            'php_version' => $phpVersion,
            'extensions' => $extensions,
            'permissions' => $permissions,
            'ready' => $ready,
        ];
    }
}
