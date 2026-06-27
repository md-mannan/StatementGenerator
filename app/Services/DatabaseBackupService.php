<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use ZipArchive;

class DatabaseBackupService
{
    /**
     * @return array{
     *     driver: string,
     *     database: string,
     *     tables: int,
     *     mysqldump_available: bool,
     * }
     */
    public function summary(): array
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        return [
            'driver' => $driver,
            'database' => $this->databaseName($connection),
            'tables' => count($this->tableNames($connection)),
            'mysqldump_available' => $driver === 'mysql' && $this->mysqldumpBinary() !== null,
        ];
    }

    public function exportFilename(): string
    {
        $database = str_replace(['/', '\\', ' '], '-', $this->databaseName(DB::connection()));

        return 'database-backup-'.$database.'-'.now()->format('Y-m-d-His').'.sql.gz';
    }

    /**
     * @return string Absolute path to a temporary .sql.gz backup file.
     */
    public function export(): string
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        $sql = match ($driver) {
            'mysql', 'mariadb' => $this->exportMysql($connection),
            'sqlite' => $this->exportSqlite($connection),
            default => throw new RuntimeException("Database driver [{$driver}] is not supported for backup."),
        };

        File::ensureDirectoryExists(storage_path('app/backups'));

        $path = storage_path('app/backups/export-'.uniqid('', true).'.sql.gz');

        $compressed = gzencode($sql, 9);

        if ($compressed === false) {
            throw new RuntimeException('Unable to compress the database backup.');
        }

        File::put($path, $compressed);

        return $path;
    }

    public function restore(string $path): void
    {
        $sql = $this->readSqlFromBackup($path);
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $this->restoreMysql($connection, $sql);

            return;
        }

        if ($driver === 'sqlite') {
            $this->restoreSqlite($connection, $sql);

            return;
        }

        throw new RuntimeException("Database driver [{$driver}] is not supported for restore.");
    }

    private function exportMysql(Connection $connection): string
    {
        $binary = $this->mysqldumpBinary();

        if ($binary !== null) {
            return $this->exportMysqlUsingBinary($connection, $binary);
        }

        return $this->exportUsingPhp($connection);
    }

    private function exportMysqlUsingBinary(Connection $connection, string $binary): string
    {
        $config = $connection->getConfig();
        $configFile = storage_path('app/backups/mysqldump-'.uniqid('', true).'.cnf');

        File::ensureDirectoryExists(dirname($configFile));

        File::put($configFile, implode(PHP_EOL, [
            '[client]',
            'user='.($config['username'] ?? 'root'),
            'password='.($config['password'] ?? ''),
            'host='.($config['host'] ?? '127.0.0.1'),
            'port='.($config['port'] ?? '3306'),
        ]));

        try {
            $process = new Process([
                $binary,
                '--defaults-extra-file='.$configFile,
                '--single-transaction',
                '--quick',
                '--lock-tables=false',
                '--routines',
                '--triggers',
                (string) ($config['database'] ?? ''),
            ]);

            $process->setTimeout(null);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            $output = $process->getOutput();

            if ($output === '') {
                throw new RuntimeException('mysqldump returned an empty backup.');
            }

            return $this->prependHeader($connection, $output);
        } finally {
            File::delete($configFile);
        }
    }

    private function restoreMysql(Connection $connection, string $sql): void
    {
        $binary = $this->mysqlBinary();

        if ($binary !== null) {
            $this->restoreMysqlUsingBinary($connection, $binary, $sql);

            return;
        }

        $this->restoreUsingPhp($connection, $sql);
    }

    private function restoreMysqlUsingBinary(Connection $connection, string $binary, string $sql): void
    {
        $config = $connection->getConfig();
        $configFile = storage_path('app/backups/mysql-'.uniqid('', true).'.cnf');

        File::put($configFile, implode(PHP_EOL, [
            '[client]',
            'user='.($config['username'] ?? 'root'),
            'password='.($config['password'] ?? ''),
            'host='.($config['host'] ?? '127.0.0.1'),
            'port='.($config['port'] ?? '3306'),
        ]));

        try {
            $process = new Process([
                $binary,
                '--defaults-extra-file='.$configFile,
                (string) ($config['database'] ?? ''),
            ]);

            $process->setInput($sql);
            $process->setTimeout(null);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }
        } finally {
            File::delete($configFile);
        }
    }

    private function exportSqlite(Connection $connection): string
    {
        return $this->exportUsingPhp($connection);
    }

    private function restoreSqlite(Connection $connection, string $sql): void
    {
        $this->restoreUsingPhp($connection, $sql);
    }

    private function exportUsingPhp(Connection $connection): string
    {
        $driver = $connection->getDriverName();
        $lines = [
            '-- Statement Analyzer full database backup',
            '-- Generated at: '.now()->toIso8601String(),
            '-- Driver: '.$driver,
            '',
        ];

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $lines[] = 'SET FOREIGN_KEY_CHECKS=0;';
            $lines[] = 'SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";';
            $lines[] = '';
        }

        if ($driver === 'sqlite') {
            $lines[] = 'PRAGMA foreign_keys=OFF;';
            $lines[] = '';
        }

        $tables = $this->tableNames($connection);

        foreach ($tables as $table) {
            $lines[] = $this->exportTableSchema($connection, $table);
            $lines[] = '';
        }

        foreach ($this->sortTablesForInsert($tables) as $table) {
            $lines = [...$lines, ...$this->exportTableData($connection, $table)];
        }

        $lines[] = '';

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $lines[] = 'SET FOREIGN_KEY_CHECKS=1;';
        }

        if ($driver === 'sqlite') {
            $lines[] = 'PRAGMA foreign_keys=ON;';
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function sortTablesForInsert(array $tables): array
    {
        $priority = [
            'migrations',
            'users',
            'clients',
            'branches',
            'client_annexures',
            'client_annexure_cheques',
            'statement_entries',
            'incoming_statement_entries',
            'client_annexure_entries',
            'passkeys',
        ];

        usort($tables, function (string $a, string $b) use ($priority): int {
            $aIndex = array_search($a, $priority, true);
            $bIndex = array_search($b, $priority, true);

            return ($aIndex === false ? 999 : $aIndex) <=> ($bIndex === false ? 999 : $bIndex);
        });

        return $tables;
    }

    /**
     * @return list<string>
     */
    private function exportTableData(Connection $connection, string $table): array
    {
        $rows = $connection->table($table)->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $columns = array_keys((array) $rows->first());
        $columnList = implode(', ', array_map(fn (string $column): string => $this->quoteIdentifier($connection, $column), $columns));
        $lines = [];

        foreach ($rows as $row) {
            $values = array_map(
                fn (string $column) => $this->quoteValue($connection, ((array) $row)[$column] ?? null),
                $columns,
            );

            $lines[] = sprintf(
                'INSERT INTO %s (%s) VALUES (%s);',
                $this->quoteIdentifier($connection, $table),
                $columnList,
                implode(', ', $values),
            );
        }

        return $lines;
    }

    private function exportTableSchema(Connection $connection, string $table): string
    {
        $driver = $connection->getDriverName();
        $quotedTable = $this->quoteIdentifier($connection, $table);

        if ($driver === 'sqlite') {
            $result = $connection->selectOne(
                "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table],
            );

            $create = $result->sql ?? null;

            if (! is_string($create) || $create === '') {
                throw new RuntimeException("Unable to read schema for table [{$table}].");
            }

            return 'DROP TABLE IF EXISTS '.$quotedTable.';'.PHP_EOL.$create.';';
        }

        $result = $connection->selectOne('SHOW CREATE TABLE '.$quotedTable);
        $create = $result->{'Create Table'} ?? null;

        if (! is_string($create) || $create === '') {
            throw new RuntimeException("Unable to read schema for table [{$table}].");
        }

        return 'DROP TABLE IF EXISTS '.$quotedTable.';'.PHP_EOL.$create.';';
    }

    private function restoreUsingPhp(Connection $connection, string $sql): void
    {
        $connection->transaction(function () use ($connection, $sql): void {
            $driver = $connection->getDriverName();

            if ($driver === 'mysql' || $driver === 'mariadb') {
                $connection->statement('SET FOREIGN_KEY_CHECKS=0');
            }

            if ($driver === 'sqlite') {
                $connection->statement('PRAGMA foreign_keys=OFF');
            }

            foreach ($this->parseSqlStatements($sql) as $statement) {
                $connection->unprepared($statement.';');
            }

            if ($driver === 'mysql' || $driver === 'mariadb') {
                $connection->statement('SET FOREIGN_KEY_CHECKS=1');
            }

            if ($driver === 'sqlite') {
                $connection->statement('PRAGMA foreign_keys=ON');
            }
        });
    }

    /**
     * @return list<string>
     */
    private function parseSqlStatements(string $sql): array
    {
        $sql = preg_replace('/^--.*$/m', '', $sql) ?? $sql;
        $parts = preg_split('/;\s*\n/', $sql) ?: [];
        $statements = [];

        foreach ($parts as $part) {
            $statement = trim($part);

            if ($statement !== '') {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    private function readSqlFromBackup(string $path): string
    {
        if (! File::exists($path)) {
            throw new InvalidArgumentException('The backup file could not be found.');
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'gz') {
            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new InvalidArgumentException('Unable to read the backup file.');
            }

            $sql = gzdecode($contents);

            if ($sql === false) {
                throw new InvalidArgumentException('Unable to decompress the backup file.');
            }

            return $sql;
        }

        if ($extension === 'zip') {
            return $this->readSqlFromZip($path);
        }

        if ($extension === 'sql') {
            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new InvalidArgumentException('Unable to read the backup file.');
            }

            return $contents;
        }

        throw new InvalidArgumentException('Upload a .sql.gz, .sql, or .zip database backup file.');
    }

    private function readSqlFromZip(string $path): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('Unable to open the backup archive.');
        }

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);

                if (! is_string($name)) {
                    continue;
                }

                if (str_ends_with(strtolower($name), '.sql')) {
                    $contents = $zip->getFromName($name);

                    if ($contents === false) {
                        break;
                    }

                    return $contents;
                }

                if (str_ends_with(strtolower($name), '.sql.gz')) {
                    $contents = $zip->getFromName($name);

                    if ($contents === false) {
                        break;
                    }

                    $sql = gzdecode($contents);

                    if ($sql === false) {
                        throw new InvalidArgumentException('Unable to decompress SQL file inside the backup archive.');
                    }

                    return $sql;
                }
            }
        } finally {
            $zip->close();
        }

        throw new InvalidArgumentException('The backup archive does not contain a .sql or .sql.gz file.');
    }

    private function prependHeader(Connection $connection, string $sql): string
    {
        return implode(PHP_EOL, [
            '-- Statement Analyzer full database backup',
            '-- Generated at: '.now()->toIso8601String(),
            '-- Driver: '.$connection->getDriverName(),
            'SET FOREIGN_KEY_CHECKS=0;',
            'SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";',
            '',
            $sql,
            '',
            'SET FOREIGN_KEY_CHECKS=1;',
        ]);
    }

    /**
     * @return list<string>
     */
    private function tableNames(Connection $connection): array
    {
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            return collect($connection->select(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
            ))->pluck('name')->map(fn ($name): string => (string) $name)->values()->all();
        }

        $database = $this->databaseName($connection);

        return collect($connection->select('SHOW TABLES'))
            ->map(fn ($row): string => (string) array_values((array) $row)[0])
            ->filter(fn (string $table): bool => $table !== '')
            ->values()
            ->all();
    }

    private function databaseName(Connection $connection): string
    {
        $database = $connection->getDatabaseName();

        return $database !== '' ? $database : 'database';
    }

    private function quoteIdentifier(Connection $connection, string $identifier): string
    {
        if ($connection->getDriverName() === 'sqlite') {
            return '"'.str_replace('"', '""', $identifier).'"';
        }

        return '`'.str_replace('`', '``', $identifier).'`';
    }

    private function quoteValue(Connection $connection, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $connection->getPdo()->quote((string) $value);
    }

    private function mysqldumpBinary(): ?string
    {
        return $this->findBinary(['mysqldump', 'mysqldump.exe']);
    }

    private function mysqlBinary(): ?string
    {
        return $this->findBinary(['mysql', 'mysql.exe']);
    }

    /**
     * @param  list<string>  $candidates
     */
    private function findBinary(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $process = new Process([$candidate, '--version']);
            $process->run();

            if ($process->isSuccessful()) {
                return $candidate;
            }
        }

        return null;
    }
}
