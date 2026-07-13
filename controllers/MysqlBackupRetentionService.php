<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier};

use Carbon\CarbonImmutable;
use Pterodactyl\Models\User;

class MysqlBackupRetentionService
{
    public function enforce(MysqlBackupConfiguration $configuration, MysqlBackupStorageManager $storage): void
    {
        $query = MysqlBackupRecord::query()
            ->where('server_id', $configuration->server_id)
            ->where('status', MysqlBackupStatus::SUCCESS)
            ->orderByDesc('completed_at')
            ->orderByDesc('id');

        if ($configuration->retention_count > 0) {
            $query->skip($configuration->retention_count)
                ->take(500)
                ->get()
                ->each(fn (MysqlBackupRecord $record) => $this->deleteRecord($record, $storage));
        }

        if ($configuration->retention_days) {
            MysqlBackupRecord::query()
                ->where('server_id', $configuration->server_id)
                ->where('status', MysqlBackupStatus::SUCCESS)
                ->where('completed_at', '<', CarbonImmutable::now()->subDays($configuration->retention_days))
                ->take(500)
                ->get()
                ->each(fn (MysqlBackupRecord $record) => $this->deleteRecord($record, $storage));
        }
    }

    /**
     * Delete oldest backups until the server (and user) usage drops back
     * under the configured quota. Called after each successful backup so
     * that a new upload that pushes the total over the limit triggers
     * automatic pruning of the oldest records.
     */
    public function enforceQuota(int $serverId, ?int $requestedBy, MysqlBackupStorageManager $storage): void
    {
        $settings = app(MysqlBackupAdminSettingsService::class);
        $limits = $settings->limitsResponse($serverId);
        $quotaService = app(MysqlBackupQuotaService::class);
        $server = \Pterodactyl\Models\Server::find($serverId);

        $serverQuotaBytes = ((int) $limits['server_quota_mb']) * 1024 * 1024;
        if ($serverQuotaBytes > 0) {
            $used = $quotaService->serverUsageBytes($serverId);

            if ($used > $serverQuotaBytes) {
                $this->pruneOldestUntilUnderQuota(
                    $serverId,
                    $serverQuotaBytes,
                    $quotaService,
                    $storage,
                );
            }
        }

        if ($requestedBy && $limits['user_quota_mb']) {
            $userQuotaBytes = ((int) $limits['user_quota_mb']) * 1024 * 1024;

            if ($userQuotaBytes > 0) {
                $used = $quotaService->userUsageBytes($requestedBy);

                if ($used > $userQuotaBytes) {
                    $this->pruneOldestUserBackupsUntilUnderQuota(
                        $requestedBy,
                        $userQuotaBytes,
                        $quotaService,
                        $storage,
                    );
                }
            }
        }
    }

    private function pruneOldestUntilUnderQuota(
        int $serverId,
        int $quotaBytes,
        MysqlBackupQuotaService $quotaService,
        MysqlBackupStorageManager $storage,
    ): void {
        $oldest = MysqlBackupRecord::query()
            ->where('server_id', $serverId)
            ->where('status', MysqlBackupStatus::SUCCESS)
            ->orderBy('completed_at')
            ->orderBy('id')
            ->take(200)
            ->get();

        foreach ($oldest as $record) {
            $this->deleteRecord($record, $storage);

            if ($quotaService->serverUsageBytes($serverId) <= $quotaBytes) {
                break;
            }
        }
    }

    private function pruneOldestUserBackupsUntilUnderQuota(
        int $userId,
        int $quotaBytes,
        MysqlBackupQuotaService $quotaService,
        MysqlBackupStorageManager $storage,
    ): void {
        $oldest = MysqlBackupRecord::query()
            ->where('requested_by', $userId)
            ->where('status', MysqlBackupStatus::SUCCESS)
            ->orderBy('completed_at')
            ->orderBy('id')
            ->take(200)
            ->get();

        foreach ($oldest as $record) {
            $this->deleteRecord($record, $storage);

            if ($quotaService->userUsageBytes($userId) <= $quotaBytes) {
                break;
            }
        }
    }

    private function deleteRecord(MysqlBackupRecord $record, MysqlBackupStorageManager $storage): void
    {
        if ($record->storageProvider) {
            $storage->delete($record->storageProvider, $record->path);
        }

        $record->delete();
    }
}
