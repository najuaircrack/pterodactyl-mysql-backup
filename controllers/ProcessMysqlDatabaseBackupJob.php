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

            // Enforce quota: reject the backup before uploading if its size
            // alone exceeds the server or user quota. This prevents a single
            // huge dump from blowing past the limit and wasting upload bandwidth.
            $this->assertBackupWithinQuota($record, $limits);

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
            $retention->enforceQuota($record->server_id, $record->requested_by, $storage);
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

    /**
     * Reject a backup whose own size exceeds the server or user quota.
     * Called after the dump is created but before it is uploaded, so we
     * avoid wasting storage bandwidth on a backup that can never fit.
     */
    private function assertBackupWithinQuota(MysqlBackupRecord $record, array $limits): void
    {
        $backupBytes = (int) $record->size_bytes;
        if ($backupBytes <= 0) {
            return;
        }

        $serverQuotaBytes = ((int) $limits['server_quota_mb']) * 1024 * 1024;
        if ($serverQuotaBytes > 0 && $backupBytes > $serverQuotaBytes) {
            throw new \RuntimeException(
                'This backup is ' . $this->formatBytes($backupBytes) .
                ' which exceeds the server quota of ' . $this->formatBytes($serverQuotaBytes) .
                '. The backup was cancelled before upload.'
            );
        }

        if ($limits['user_quota_mb']) {
            $userQuotaBytes = ((int) $limits['user_quota_mb']) * 1024 * 1024;
            if ($userQuotaBytes > 0 && $backupBytes > $userQuotaBytes) {
                throw new \RuntimeException(
                    'This backup is ' . $this->formatBytes($backupBytes) .
                    ' which exceeds your user quota of ' . $this->formatBytes($userQuotaBytes) .
                    '. The backup was cancelled before upload.'
                );
            }
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return round($bytes / 1024 / 1024 / 1024, 2) . ' GB';
        }
        if ($bytes >= 1024 * 1024) {
            return round($bytes / 1024 / 1024, 1) . ' MB';
        }
        return round($bytes / 1024, 1) . ' KB';
    }
}
