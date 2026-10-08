<?php

namespace App\Services\DatabaseExports;

use Illuminate\Support\Facades\DB;
use Pdo\Mysql;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class DatabaseDumpWriter
{
    public function available(): bool
    {
        $driver = DB::connection()->getDriverName();

        return in_array($driver, ['mysql', 'mariadb'], true) && $this->binary() !== null && function_exists('proc_open') && function_exists('gzopen');
    }

    public function write(string $path): void
    {
        if (! $this->available()) {
            throw new RuntimeException('DUMP_UNAVAILABLE');
        }
        // Native single-transaction dumps are consistent for InnoDB. Refuse an
        // incomplete/non-transactional snapshot instead of silently claiming safety.
        $connection = DB::connection();
        $config = $connection->getConfig(); // Includes parsed DB_URL configuration.
        $unsupported = $connection->table('information_schema.TABLES')->where('TABLE_SCHEMA', $config['database'])
            ->where('TABLE_TYPE', 'BASE TABLE')->where('ENGINE', '<>', 'InnoDB')->exists();
        if ($unsupported) {
            throw new RuntimeException('NON_TRANSACTIONAL_TABLES');
        }
        $credentials = dirname($path).'/client.cnf';
        $lines = ['[client]', 'user='.$this->quote((string) $config['username']), 'password='.$this->quote((string) ($config['password'] ?? ''))];
        if (filled($config['unix_socket'] ?? null)) {
            $lines[] = 'socket='.$this->quote($config['unix_socket']);
        } else {
            $lines[] = 'host='.$this->quote((string) ($config['host'] ?? '127.0.0.1'));
            $lines[] = 'port='.(int) ($config['port'] ?? 3306);
        }
        $sslCa = $config['options'][PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : \PDO::MYSQL_ATTR_SSL_CA] ?? null;
        if ($sslCa) {
            $lines[] = 'ssl-ca='.$this->quote($sslCa);
        }
        $handle = fopen($credentials, 'x');
        if ($handle === false) {
            throw new RuntimeException('DUMP_FAILED');
        }
        chmod($credentials, 0600);
        try {
            if (fwrite($handle, implode("\n", $lines)."\n") === false) {
                throw new RuntimeException('DUMP_FAILED');
            }
            fclose($handle);
            $handle = null;
            // No password in argv, environment, job payloads, logs, or public files.
            // No shell interpolation; the database is a single argv value.
            $binary = $this->binary();
            $version = new Process([$binary, '--version']);
            $version->setTimeout(10)->mustRun();
            $mysqlOptions = [];
            if (! str_contains(strtolower($version->getOutput()), 'mariadb')) {
                $mysqlOptions[] = '--set-gtid-purged=OFF';
                if (preg_match('/Ver\s+(\d+)/i', $version->getOutput(), $matches) && (int) $matches[1] >= 8) {
                    $mysqlOptions[] = '--column-statistics=0';
                }
            }
            $process = new Process([
                $binary, '--defaults-file='.$credentials,
                ...$mysqlOptions,
                '--single-transaction', '--quick', '--hex-blob', '--routines', '--events', '--triggers',
                '--skip-lock-tables', '--no-tablespaces', '--default-character-set=utf8mb4',
                '--result-file='.$path, '--', (string) $config['database'],
            ]);
            $process->setTimeout((int) config('database_exports.timeout_seconds', 900));
            $process->disableOutput();
            if ($process->run() !== 0 || ! is_file($path) || filesize($path) === 0) {
                throw new RuntimeException('DUMP_FAILED');
            }
            chmod($path, 0600);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($credentials)) {
                unlink($credentials);
            }
        }
    }

    protected function binary(): ?string
    {
        $configured = config('database_exports.mysql_dump_binary');
        if ($configured) {
            return is_file($configured) && is_executable($configured) ? $configured : null;
        }
        $finder = new ExecutableFinder;

        return $finder->find('mysqldump') ?? $finder->find('mariadb-dump');
    }

    private function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"', "\n", "\r", "\t"], ['\\\\', '\\"', '\\n', '\\r', '\\t'], $value).'"';
    }
}
