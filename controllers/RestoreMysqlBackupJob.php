<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier};

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Pterodactyl\Models\Database;
use Illuminate\Support\Str;
use Throwable;

class RestoreMysqlBackupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 7200;

    public function __construct(
        public int $recordId,
        public int $targetDatabaseId,
        public int $requestedBy,
        public bool $overwriteConfirmed,
    ) {}

    public function handle(
        MysqlBackupStorageManager $storage,
        MysqlBackupEncryptionService $encryption,
        MysqlDumpService $dump,
        MysqlBackupLogger $logger,
    ): void {
        $record = MysqlBackupRecord::query()->with(['storageProvider'])->findOrFail($this->recordId);
        $targetDatabase = Database::query()->with('host')->findOrFail($this->targetDatabaseId);
        $settings = app(MysqlBackupAdminSettingsService::class);

        if (!$this->overwriteConfirmed) {
            throw new \RuntimeException('Restore overwrite confirmation was not provided.');
        }

        if ($settings->limitsResponse($record->server_id)['pre_restore_safety_backup']) {
            $this->createSafetyBackup($targetDatabase, $record, $storage, $encryption, $dump, $logger);
        }

        $record->forceFill([
            'status' => MysqlBackupStatus::RESTORING,
            'stage' => 'restoring',
            'progress' => 10,
            'metadata' => array_merge($record->metadata ?: [], [
                'restore_target_database_id' => $targetDatabase->id,
                'restore_requested_by' => $this->requestedBy,
                'restore_started_at' => now()->toIso8601String(),
            ]),
        ])->save();

        $logger->write($record, $record->server_id, 'warning', 'Starting MySQL restore.', [
            'target_database' => $targetDatabase->database,
        ]);

        $tempPath = tempnam(sys_get_temp_dir(), 'ptero-mysql-restore-');
        $restorePath = $tempPath;

        try {
            $stream = $storage->readStream($record->storageProvider, $record->path);
            $out = fopen($tempPath, 'wb');

            stream_copy_to_stream($stream, $out);

            if (is_resource($stream)) {
                fclose($stream);
            }

            fclose($out);

            if ($record->encrypted) {
                $restorePath = $encryption->decryptFile($tempPath);
                @unlink($tempPath);
            }

            $record->forceFill(['progress' => 45])->save();
            $dump->restore($targetDatabase, $restorePath, $record, $logger);

            $record->forceFill([
                'status' => MysqlBackupStatus::RESTORED,
                'stage' => 'restored',
                'progress' => 100,
                'metadata' => array_merge($record->metadata ?: [], [
                    'restore_completed_at' => now()->toIso8601String(),
                ]),
            ])->save();

            $logger->write($record, $record->server_id, 'info', 'MySQL restore completed.', [
                'target_database' => $targetDatabase->database,
            ]);
            app(MysqlBackupAuditService::class)->record('restore_completed', [
                'server_id' => $record->server_id,
                'backup_uuid' => $record->uuid,
                'target_database_id' => $targetDatabase->id,
            ], null, $record);
        } catch (Throwable $exception) {
            $record->forceFill([
                'status' => MysqlBackupStatus::FAILED,
                'stage' => 'restore_failed',
                'error_message' => mb_substr($exception->getMessage(), 0, 64000),
            ])->save();
            app(MysqlBackupAuditService::class)->record('restore_failed', [
                'server_id' => $record->server_id,
                'backup_uuid' => $record->uuid,
                'target_database_id' => $targetDatabase->id,
                'message' => $exception->getMessage(),
            ], null, $record);

            $logger->write($record, $record->server_id, 'error', 'MySQL restore failed.', [
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        } finally {
            foreach (array_unique([$tempPath, $restorePath]) as $path) {
                if ($path && file_exists($path)) {
                    @unlink($path);
                }
            }
        }
    }

    private function createSafetyBackup(
        Database $targetDatabase,
        MysqlBackupRecord $parent,
        MysqlBackupStorageManager $storage,
        MysqlBackupEncryptionService $encryption,
        MysqlDumpService $dump,
        MysqlBackupLogger $logger,
    ): void {
        $provider = $parent->storageProvider;
        $path = $storage->pathFor($parent->server, $targetDatabase->database . '_pre_restore', $parent->encrypted ? 'sql.gz.enc' : 'sql.gz');
        $safety = MysqlBackupRecord::create([
            'uuid' => (string) Str::uuid(),
            'server_id' => $parent->server_id,
            'database_id' => $targetDatabase->id,
            'storage_provider_id' => $provider->id,
            'requested_by' => $this->requestedBy,
            'database_name' => $targetDatabase->database,
            'status' => MysqlBackupStatus::RUNNING,
            'stage' => 'safety_backup',
            'filename' => basename($path),
            'path' => $path,
            'compressed' => true,
            'encrypted' => $parent->encrypted,
            'manual' => false,
            'safety_backup' => true,
            'parent_backup_uuid' => $parent->uuid,
            'started_at' => now(),
        ]);

        $localPath = null;

        try {
            $localPath = $dump->dump($targetDatabase, $safety, $logger);

            if ($parent->encrypted) {
                $encryptedPath = $encryption->encryptFile($localPath);
                @unlink($localPath);
                $localPath = $encryptedPath;
            }

            $safety->forceFill([
                'size_bytes' => filesize($localPath) ?: 0,
                'checksum_sha256' => hash_file('sha256', $localPath),
            ])->save();

            $stream = fopen($localPath, 'rb');
            $storage->putStream($provider, $path, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            $safety->forceFill([
                'status' => MysqlBackupStatus::SUCCESS,
                'stage' => 'complete',
                'progress' => 100,
                'completed_at' => now(),
                'duration_ms' => $safety->started_at ? $safety->started_at->diffInMilliseconds(now()) : null,
            ])->save();
        } finally {
            if ($localPath && file_exists($localPath)) {
                @unlink($localPath);
            }
        }
    }
}
