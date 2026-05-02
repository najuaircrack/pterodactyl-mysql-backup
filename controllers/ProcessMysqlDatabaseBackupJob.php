<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier};

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessMysqlDatabaseBackupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 7200;
    public int $backoff = 120;

    public function __construct(
        public int $recordId,
        public int $configurationId,
    ) {}

    public function handle(
        MysqlDumpService $dump,
        MysqlBackupEncryptionService $encryption,
        MysqlBackupStorageManager $storage,
        MysqlBackupRetentionService $retention,
        MysqlBackupNotificationService $notifications,
        MysqlBackupVerificationService $verification,
        MysqlBackupLogger $logger,
    ): void {
        $record = MysqlBackupRecord::query()->with(['database.host', 'server', 'storageProvider'])->findOrFail($this->recordId);
        $configuration = MysqlBackupConfiguration::query()->findOrFail($this->configurationId);
        $limits = app(MysqlBackupAdminSettingsService::class)->limitsResponse($record->server_id);
        $lock = null;

        for ($slot = 1; $slot <= $limits['max_concurrent_server_jobs']; $slot++) {
            $candidate = cache()->lock('mysql-backup-server-' . $record->server_id . '-slot-' . $slot, 7200);

            if ($candidate->get()) {
                $lock = $candidate;
                break;
            }
        }

        if (!$lock) {
            self::dispatch($this->recordId, $this->configurationId)->delay(now()->addMinutes(2));
            return;
        }

        $record->forceFill([
            'status' => MysqlBackupStatus::RUNNING,
            'stage' => 'dumping',
            'started_at' => now(),
            'progress' => 5,
        ])->save();

        $logger->write($record, $record->server_id, 'info', 'Starting MySQL dump.', [
            'database' => $record->database_name,
        ]);

        $localPath = null;

        try {
            $localPath = $dump->dump($record->database, $record, $logger);
            $record->forceFill(['stage' => 'compressing'])->save();
            if ($configuration->encrypt) {
                $record->forceFill(['stage' => 'encrypting'])->save();
                $encryptedPath = $encryption->encryptFile($localPath);
                @unlink($localPath);
                $localPath = $encryptedPath;
            }

            $record->forceFill([
                'size_bytes' => filesize($localPath) ?: 0,
                'checksum_sha256' => hash_file('sha256', $localPath),
                'progress' => 92,
            ])->save();

            $provider = $record->storageProvider;
            $stored = false;
            $lastStorageException = null;

            $record->forceFill(['stage' => 'uploading'])->save();

            foreach (collect([$provider])->merge($storage->fallbackProviders($record->server, $provider)) as $candidate) {
                $stream = fopen($localPath, 'rb');

                try {
                    $storage->putStream($candidate, $record->path, $stream);
                    $record->forceFill([
                        'storage_provider_id' => $candidate->id,
                        'metadata' => array_merge($record->metadata ?: [], [
                            'storage_driver' => $candidate->driver,
                            'storage_name' => $candidate->name,
                            'storage_fallback_used' => $candidate->id !== $provider->id,
                        ]),
                    ])->save();
                    $stored = true;
                    break;
                } catch (Throwable $exception) {
                    $lastStorageException = $exception;
                    $logger->write($record, $record->server_id, 'warning', 'Storage upload failed, trying next provider.', [
                        'provider' => $candidate->name,
                        'message' => $exception->getMessage(),
                    ]);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            }

            if (!$stored && $lastStorageException) {
                throw $lastStorageException;
            }

            if ($limits['verify_after_upload']) {
                $record->forceFill(['stage' => 'verifying'])->save();
                $verification->verify($record->fresh(['storageProvider']), $storage);
                $record = $record->fresh(['database.host', 'server', 'storageProvider']);
            }

            $record->forceFill([
                'status' => MysqlBackupStatus::SUCCESS,
                'stage' => 'complete',
                'completed_at' => now(),
                'duration_ms' => $record->started_at ? $record->started_at->diffInMilliseconds(now()) : null,
                'progress' => 100,
            ])->save();
            app(MysqlBackupAuditService::class)->record('backup_completed', [
                'server_id' => $record->server_id,
                'backup_uuid' => $record->uuid,
                'database' => $record->database_name,
                'verified' => $record->verified,
            ], null, $record);

            $logger->write($record, $record->server_id, 'info', 'MySQL backup completed.', [
                'path' => $record->path,
                'size_bytes' => $record->size_bytes,
            ]);

            $notifications->send($configuration, $record, 'success', $localPath);
            $retention->enforce($configuration, $storage);
        } catch (Throwable $exception) {
            $record->forceFill([
                'status' => MysqlBackupStatus::FAILED,
                'stage' => 'failed',
                'completed_at' => now(),
                'duration_ms' => $record->started_at ? $record->started_at->diffInMilliseconds(now()) : null,
                'error_message' => mb_substr($exception->getMessage(), 0, 64000),
            ])->save();
            app(MysqlBackupAuditService::class)->record('backup_failed', [
                'server_id' => $record->server_id,
                'backup_uuid' => $record->uuid,
                'database' => $record->database_name,
                'message' => $exception->getMessage(),
            ], null, $record);

            $logger->write($record, $record->server_id, 'error', 'MySQL backup failed.', [
                'exception' => get_class($exception),
                'message' => $exception->getMessage(),
            ]);

            $notifications->send($configuration, $record, 'failure');
            throw $exception;
        } finally {
            if ($localPath && file_exists($localPath)) {
                @unlink($localPath);
            }
            optional($lock)->release();
        }
    }

    public function failed(Throwable $exception): void
    {
        MysqlBackupRecord::query()
            ->where('id', $this->recordId)
            ->whereNotIn('status', MysqlBackupStatus::TERMINAL)
            ->update([
                'status' => MysqlBackupStatus::FAILED,
                'stage' => 'failed',
                'completed_at' => now(),
                'error_message' => mb_substr($exception->getMessage(), 0, 64000),
            ]);
    }
}
