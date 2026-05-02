<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier};

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Pterodactyl\Models\Database;
use Illuminate\Support\Facades\Crypt;

class MysqlDumpService
{
    public function dump(Database $database, MysqlBackupRecord $record, MysqlBackupLogger $logger): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'ptero-mysql-backup-');
        $gzipPath = $tempPath . '.sql.gz';
        @rename($tempPath, $gzipPath);

        $defaultsFile = $this->defaultsFile($database, false, $record->server_id);
        $output = gzopen($gzipPath, 'wb6');

        if ($output === false) {
            @unlink($defaultsFile);
            throw new RuntimeException('Unable to open temporary gzip backup file.');
        }

        $bytes = 0;
        $lastProgressAt = 0;
        $command = [
            env('MYSQL_BACKUP_MYSQLDUMP_PATH', 'mysqldump'),
            '--defaults-extra-file=' . $defaultsFile,
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--routines',
            '--events',
            '--triggers',
            '--hex-blob',
            '--no-tablespaces',
            '--databases',
            $database->database,
        ];

        $settings = app(MysqlBackupAdminSettingsService::class)->getForServer($record->server_id);
        $process = new Process($command, null, null, null, (int) $settings['runtime']['dump_timeout_seconds']);
        $process->setIdleTimeout((int) env('MYSQL_BACKUP_DUMP_IDLE_TIMEOUT', 300));

        try {
            $process->run(function (string $type, string $buffer) use ($output, $gzipPath, &$bytes, &$lastProgressAt, $record) {
                if ($type !== Process::OUT) {
                    return;
                }

                $bytes += strlen($buffer);
                gzwrite($output, $buffer);

                if ($bytes - $lastProgressAt > 1024 * 1024 * 5) {
                    $lastProgressAt = $bytes;
                    $record->forceFill([
                        'size_bytes' => file_exists($gzipPath) ? (filesize($gzipPath) ?: 0) : 0,
                        'progress' => min(90, $record->progress + 5),
                    ])->save();
                }
            });
        } finally {
            gzclose($output);
            @unlink($defaultsFile);
        }

        if (!$process->isSuccessful()) {
            @unlink($gzipPath);
            $stderr = $process->getErrorOutput();
            $logger->write($record, $record->server_id, 'error', 'mysqldump failed.', [
                'exit_code' => $process->getExitCode(),
                'stderr' => mb_substr($stderr, 0, 4000),
            ]);

            if (str_contains($stderr, '1045') || str_contains($stderr, 'Access denied')) {
                throw new RuntimeException(
                    'MySQL rejected the dump credentials. Configure a dedicated backup user in the extension admin Dump Credentials section, or grant this database user SELECT/SHOW VIEW/TRIGGER access from the panel server IP.'
                );
            }

            throw new ProcessFailedException($process);
        }

        return $gzipPath;
    }

    public function restore(Database $targetDatabase, string $gzipPath, MysqlBackupRecord $record, MysqlBackupLogger $logger): void
    {
        $defaultsFile = $this->defaultsFile($targetDatabase, true, $record->server_id);
        $command = [
            env('MYSQL_BACKUP_MYSQL_PATH', 'mysql'),
            '--defaults-extra-file=' . $defaultsFile,
            $targetDatabase->database,
        ];

        $settings = app(MysqlBackupAdminSettingsService::class)->getForServer($record->server_id);
        $process = new Process($command, null, null, null, (int) $settings['runtime']['restore_timeout_seconds']);
        $process->setInput($this->gzipIterator($gzipPath));

        try {
            $process->run();
        } finally {
            @unlink($defaultsFile);
        }

        if (!$process->isSuccessful()) {
            $logger->write($record, $record->server_id, 'error', 'mysql restore failed.', [
                'exit_code' => $process->getExitCode(),
                'stderr' => mb_substr($process->getErrorOutput(), 0, 4000),
            ]);

            throw new ProcessFailedException($process);
        }
    }

    private function resolvePassword(string $password): string
    {

        if (!is_string($password)) {
            return '';
        }

        try {
            $password = Crypt::decryptString($password);
        } catch (\Throwable) {
            return $password;
        }

         
        if (str_starts_with($password, 's:')) {
            $unserialized = @unserialize($password);
            if ($unserialized !== false) {
                return $unserialized;
            }
        }

        return (string) $password;
    }

    private function defaultsFile(Database $database, bool $restore = false, ?int $serverId = null): string
    {
        $credentials = $this->credentials($database, $restore, $serverId);
        $path = tempnam(sys_get_temp_dir(), 'ptero-mysql-defaults-');
        $contents = sprintf(
            "[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n",
            $this->optionValue($credentials['host']),
            $credentials['port'],
            $this->optionValue($credentials['username']),
            $this->optionValue($credentials['password'])
        );

        file_put_contents($path, $contents);
        @chmod($path, 0600);

        return $path;
    }

    private function credentials(Database $database, bool $restore, ?int $serverId): array
    {
        $host = $database->host;
        $settingsService = app(MysqlBackupAdminSettingsService::class);
        $settings = $settingsService->getForServer($serverId);
        $runtime = $settings['runtime'];

        if (!$restore && ($runtime['dump_credential_mode'] ?? 'database') === 'backup_user' && !empty($runtime['dump_username'])) {
            return [
                'host' => (string) (($runtime['dump_host'] ?? '') ?: $host?->host),
                'port' => (int) ($host?->port ?: 3306),
                'username' => (string) $runtime['dump_username'],
                'password' => $settingsService->revealSecret($runtime['dump_password'] ?? ''),
            ];
        }

        return [
            'host' => (string) $host?->host,
            'port' => (int) ($host?->port ?: 3306),
            'username' => (string) $database->username,
            'password' => (string) $this->resolvePassword($database->password),
        ];
    }

    private function optionValue(string $value): string
    {
        return '"' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\"', '', ''], $value) . '"';
    }

    private function gzipIterator(string $path): \Generator
    {
        $handle = gzopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to read compressed backup for restore.');
        }

        try {
            while (!gzeof($handle)) {
                yield gzread($handle, 1024 * 1024);
            }
        } finally {
            gzclose($handle);
        }
    }
}
