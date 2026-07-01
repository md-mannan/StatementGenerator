<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use ZipArchive;

class DatabaseBackupService
{
    /**
     * Application data removed by wipe. Order is child tables first.
     *
     * @var list<string>
     */
    private const APPLICATION_DATA_TABLES = [
        'client_annexure_entries',
        'client_annexure_cheques',
        'incoming_statement_entries',
        'statement_entries',
        'client_annexures',
        'branches',
        'clients',
    ];

    /**
     * @var list<string>
     */
    private const SYSTEM_TABLES_CLEARED_ON_WIPE = [
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
    ];

    /**
     * @var list<string>
     */
    private const PRESERVED_TABLES = [
        'users',
        'migrations',
        'password_reset_tokens',
        'sessions',
    ];

    /**
     * @return array{
     *     driver: string,
     *     database: string,
     *     tables: int,
     *     mysqldump_available: bool,
     *     records: array{
     *         clients: int,
     *         branches: int,
     *         statement_entries: int,
     *         incoming_statement_entries: int,
     *         client_annexures: int,
     *     },
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
            'records' => [
                'clients' => $this->tableCount($connection, 'clients'),
                'branches' => $this->tableCount($connection, 'branches'),
                'statement_entries' => $this->tableCount($connection, 'statement_entries'),
                'incoming_statement_entries' => $this->tableCount($connection, 'incoming_statement_entries'),
                'client_annexures' => $this->tableCount($connection, 'client_annexures'),
            ],
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

    public function restore(string $path, ?string $originalFilename = null): void
    {
        @set_time_limit(0);
        ini_set('memory_limit', '512M');

        $sql = $this->readSqlFromBackup($path, $originalFilename);
        $metadata = $this->extractBackupMetadata($sql);
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $this->restoreMysql($connection, $sql);
            $this->verifyRestoredData($connection, $metadata);

            return;
        }

        if ($driver === 'sqlite') {
            $this->restoreSqlite($connection, $sql);
            $this->verifyRestoredData($connection, $metadata);

            return;
        }

        throw new RuntimeException("Database driver [{$driver}] is not supported for restore.");
    }

    public function wipeApplicationData(): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        $this->deleteInvoiceScanFiles();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $connection->statement('SET FOREIGN_KEY_CHECKS=0');
        }

        if ($driver === 'sqlite') {
            $connection->statement('PRAGMA foreign_keys=OFF');
        }

        try {
            foreach ($this->tablesToWipe($connection) as $table) {
                $connection->table($table)->truncate();
            }
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                'Failed to clear application data: '.$exception->getMessage(),
                previous: $exception,
            );
        } finally {
            if ($driver === 'mysql' || $driver === 'mariadb') {
                $connection->statement('SET FOREIGN_KEY_CHECKS=1');
            }

            if ($driver === 'sqlite') {
                $connection->statement('PRAGMA foreign_keys=ON');
            }
        }
    }

    /**
     * @return list<string>
     */
    public function tablesPreservedOnWipe(): array
    {
        return self::PRESERVED_TABLES;
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

            return $this->appendBackupMetadata($connection, $this->prependHeader($connection, $output));
        } finally {
            File::delete($configFile);
        }
    }

    private function restoreMysql(Connection $connection, string $sql): void
    {
        $binary = $this->mysqlBinary();

        if ($binary !== null) {
            try {
                $this->restoreMysqlUsingBinary($connection, $binary, $sql);

                return;
            } catch (ProcessFailedException) {
                // Fall back to PHP restore on hosts where the mysql client is unavailable or misconfigured.
            }
        }

        $this->restoreUsingPhp($connection, $sql);
    }

    private function restoreMysqlUsingBinary(Connection $connection, string $binary, string $sql): void
    {
        $config = $connection->getConfig();
        $configFile = storage_path('app/backups/mysql-'.uniqid('', true).'.cnf');
        $sqlFile = storage_path('app/backups/restore-'.uniqid('', true).'.sql');

        File::put($configFile, implode(PHP_EOL, [
            '[client]',
            'user='.($config['username'] ?? 'root'),
            'password='.($config['password'] ?? ''),
            'host='.($config['host'] ?? '127.0.0.1'),
            'port='.($config['port'] ?? '3306'),
        ]));

        File::put($sqlFile, $sql);

        try {
            $database = (string) ($config['database'] ?? '');

            if (PHP_OS_FAMILY === 'Windows') {
                $process = new Process([
                    $binary,
                    '--defaults-extra-file='.$configFile,
                    '--max_allowed_packet=512M',
                    '--default-character-set=utf8mb4',
                    $database,
                ]);
                $process->setInput($sql);
                $process->setTimeout(null);
                $process->run();
            } else {
                $process = Process::fromShellCommandline(sprintf(
                    '%s --defaults-extra-file=%s --max_allowed_packet=512M --default-character-set=utf8mb4 %s < %s',
                    escapeshellarg($binary),
                    escapeshellarg($configFile),
                    escapeshellarg($database),
                    escapeshellarg($sqlFile),
                ));
                $process->setTimeout(null);
                $process->run();
            }

            if (! $process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }
        } finally {
            File::delete($configFile);
            File::delete($sqlFile);
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

        $lines[] = '-- backup-metadata:'.json_encode($this->backupMetadata($connection));

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
            ...self::PRESERVED_TABLES,
            ...array_reverse(self::APPLICATION_DATA_TABLES),
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
        if ($connection->table($table)->count() === 0) {
            return [];
        }

        $quotedTable = $this->quoteIdentifier($connection, $table);
        $query = $connection->table($table);

        if ($this->tableHasColumn($connection, $table, 'id')) {
            $query->orderBy('id');
        }

        $lines = [];
        $columns = null;
        $columnList = null;
        $chunk = [];

        foreach ($query->cursor() as $row) {
            if ($columns === null) {
                $columns = array_keys((array) $row);
                $columnList = implode(', ', array_map(
                    fn (string $column): string => $this->quoteIdentifier($connection, $column),
                    $columns,
                ));
            }

            $chunk[] = $row;

            if (count($chunk) >= 200) {
                $lines[] = $this->buildInsertStatement($connection, $quotedTable, $columnList, $columns, $chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            $lines[] = $this->buildInsertStatement($connection, $quotedTable, $columnList, $columns, $chunk);
        }

        return $lines;
    }

    /**
     * @param  list<string>  $columns
     * @param  list<object>  $rows
     */
    private function buildInsertStatement(
        Connection $connection,
        string $quotedTable,
        string $columnList,
        array $columns,
        array $rows,
    ): string {
        $valueGroups = [];

        foreach ($rows as $row) {
            $values = array_map(
                fn (string $column) => $this->quoteValue($connection, ((array) $row)[$column] ?? null),
                $columns,
            );

            $valueGroups[] = '('.implode(', ', $values).')';
        }

        return sprintf(
            'INSERT INTO %s (%s) VALUES %s;',
            $quotedTable,
            $columnList,
            implode(', ', $valueGroups),
        );
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
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $connection->transaction(function () use ($connection, $sql): void {
                $connection->statement('PRAGMA foreign_keys=OFF');

                foreach ($this->parseSqlStatements($sql) as $statement) {
                    $connection->unprepared($statement.';');
                }

                $connection->statement('PRAGMA foreign_keys=ON');
            });

            return;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $connection->statement('SET FOREIGN_KEY_CHECKS=0');
        }

        try {
            foreach ($this->parseSqlStatements($sql) as $statement) {
                $connection->unprepared($statement.';');
            }
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                'Database restore failed while executing SQL: '.$exception->getMessage(),
                previous: $exception,
            );
        } finally {
            if ($driver === 'mysql' || $driver === 'mariadb') {
                $connection->statement('SET FOREIGN_KEY_CHECKS=1');
            }
        }
    }

    /**
     * @return list<string>
     */
    private function parseSqlStatements(string $sql): array
    {
        $sql = str_replace("\r\n", "\n", $sql);
        $sql = preg_replace('/^--(?! backup-metadata:).*$/m', '', $sql) ?? $sql;
        $sql = preg_replace('/^#.*$/m', '', $sql) ?? $sql;

        $statements = [];
        $length = strlen($sql);
        $buffer = '';
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $inBacktick = false;
        $escapeNext = false;

        for ($index = 0; $index < $length; $index++) {
            $character = $sql[$index];

            if ($escapeNext) {
                $buffer .= $character;
                $escapeNext = false;

                continue;
            }

            if ($inSingleQuote) {
                $buffer .= $character;

                if ($character === '\\') {
                    $escapeNext = true;
                } elseif ($character === "'") {
                    $inSingleQuote = false;
                }

                continue;
            }

            if ($inDoubleQuote) {
                $buffer .= $character;

                if ($character === '\\') {
                    $escapeNext = true;
                } elseif ($character === '"') {
                    $inDoubleQuote = false;
                }

                continue;
            }

            if ($inBacktick) {
                $buffer .= $character;

                if ($character === '`') {
                    $inBacktick = false;
                }

                continue;
            }

            if ($character === "'") {
                $inSingleQuote = true;
                $buffer .= $character;

                continue;
            }

            if ($character === '"') {
                $inDoubleQuote = true;
                $buffer .= $character;

                continue;
            }

            if ($character === '`') {
                $inBacktick = true;
                $buffer .= $character;

                continue;
            }

            if ($character === ';') {
                $statement = trim($buffer);

                if ($statement !== '' && ! $this->shouldSkipStatement($statement)) {
                    $statements[] = $statement;
                }

                $buffer = '';

                continue;
            }

            $buffer .= $character;
        }

        $statement = trim($buffer);

        if ($statement !== '' && ! $this->shouldSkipStatement($statement)) {
            $statements[] = $statement;
        }

        return $statements;
    }

    private function shouldSkipStatement(string $statement): bool
    {
        $normalized = strtoupper(ltrim($statement));

        return str_starts_with($normalized, 'LOCK TABLES')
            || str_starts_with($normalized, 'UNLOCK TABLES')
            || str_starts_with($normalized, 'USE ');
    }

    /**
     * @return array<string, int>|null
     */
    private function extractBackupMetadata(string $sql): ?array
    {
        if (! preg_match('/^-- backup-metadata:(.+)$/m', $sql, $matches)) {
            return null;
        }

        $metadata = json_decode(trim($matches[1]), true);

        if (! is_array($metadata)) {
            return null;
        }

        $counts = [];

        foreach ($metadata as $table => $count) {
            if (is_string($table) && is_numeric($count)) {
                $counts[$table] = (int) $count;
            }
        }

        return $counts === [] ? null : $counts;
    }

    /**
     * @return array<string, int>
     */
    private function backupMetadata(Connection $connection): array
    {
        $metadata = [];

        foreach ([
            'users',
            'clients',
            'branches',
            'statement_entries',
            'incoming_statement_entries',
            'client_annexures',
            'client_annexure_entries',
            'client_annexure_cheques',
        ] as $table) {
            if (in_array($table, $this->tableNames($connection), true)) {
                $metadata[$table] = $this->tableCount($connection, $table);
            }
        }

        return $metadata;
    }

    /**
     * @param  array<string, int>|null  $metadata
     */
    private function verifyRestoredData(Connection $connection, ?array $metadata): void
    {
        if ($metadata === null) {
            return;
        }

        $mismatches = [];

        foreach ($metadata as $table => $expectedCount) {
            if (! in_array($table, $this->tableNames($connection), true)) {
                continue;
            }

            $actualCount = $this->tableCount($connection, $table);

            if ($actualCount !== $expectedCount) {
                $mismatches[] = "{$table} (expected {$expectedCount}, found {$actualCount})";
            }
        }

        if ($mismatches !== []) {
            throw new RuntimeException(
                'Restore verification failed. Some tables were not fully imported: '.implode(', ', $mismatches).'. '
                .'Create a fresh backup from Settings → Database backup and try again.',
            );
        }
    }

    private function appendBackupMetadata(Connection $connection, string $sql): string
    {
        return rtrim($sql).PHP_EOL.'-- backup-metadata:'.json_encode($this->backupMetadata($connection)).PHP_EOL;
    }

    private function tableHasColumn(Connection $connection, string $table, string $column): bool
    {
        return in_array($column, $connection->getSchemaBuilder()->getColumnListing($table), true);
    }

    private function readSqlFromBackup(string $path, ?string $originalFilename = null): string
    {
        if (! File::exists($path)) {
            throw new InvalidArgumentException('The backup file could not be found.');
        }

        $extension = $this->resolveBackupExtension($path, $originalFilename);

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

    private function resolveBackupExtension(string $path, ?string $originalFilename = null): string
    {
        $name = strtolower($originalFilename ?? basename($path));

        if (str_ends_with($name, '.sql.gz')) {
            return 'gz';
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (in_array($extension, ['gz', 'zip', 'sql'], true)) {
            return $extension;
        }

        $header = @file_get_contents($path, false, null, 0, 2);

        if ($header === "\x1f\x8b") {
            return 'gz';
        }

        if ($header === 'PK') {
            return 'zip';
        }

        return $extension;
    }

    private function deleteInvoiceScanFiles(): void
    {
        Storage::disk('local')->deleteDirectory('invoice-scans');
    }

    /**
     * @return list<string>
     */
    private function tablesToWipe(Connection $connection): array
    {
        $existingTables = $this->tableNames($connection);
        $ordered = [...self::APPLICATION_DATA_TABLES, ...self::SYSTEM_TABLES_CLEARED_ON_WIPE];
        $tablesToWipe = [];

        foreach ($ordered as $table) {
            if (in_array($table, $existingTables, true)) {
                $tablesToWipe[] = $table;
            }
        }

        foreach ($existingTables as $table) {
            if (
                ! in_array($table, self::PRESERVED_TABLES, true)
                && ! in_array($table, $tablesToWipe, true)
            ) {
                $tablesToWipe[] = $table;
            }
        }

        return $tablesToWipe;
    }

    private function tableCount(Connection $connection, string $table): int
    {
        if (! in_array($table, $this->tableNames($connection), true)) {
            return 0;
        }

        return (int) $connection->table($table)->count();
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
