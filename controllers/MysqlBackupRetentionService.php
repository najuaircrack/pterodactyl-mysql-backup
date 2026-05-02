<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier};

use Carbon\CarbonImmutable;

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

    private function deleteRecord(MysqlBackupRecord $record, MysqlBackupStorageManager $storage): void
    {
        if ($record->storageProvider) {
            $storage->delete($record->storageProvider, $record->path);
        }

        $record->delete();
    }
}
