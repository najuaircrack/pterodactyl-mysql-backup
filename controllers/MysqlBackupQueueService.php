<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier};

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Pterodactyl\Models\Server;

class MysqlBackupQueueService
{
    public function queueServer(
        MysqlBackupConfiguration $configuration,
        Server $server,
        ?int $requestedBy,
        ?array $databaseIds,
        bool $manual,
    ): Collection {
        if (!$manual && !$configuration->enabled) {
            return collect();
        }

        app(MysqlBackupQuotaService::class)->assertCanQueue(
            $server,
            $requestedBy ? \Pterodactyl\Models\User::query()->find($requestedBy) : null,
            app(MysqlBackupAdminSettingsService::class),
        );

        $storage = app(MysqlBackupStorageManager::class);
        $logger = app(MysqlBackupLogger::class);
        $provider = $storage->providerFor($server, $configuration);
        $selectedDatabaseIds = $databaseIds ?: $configuration->database_ids;
        $records = collect();

        $databases = $server->databases()->with('host')
            ->when(is_array($selectedDatabaseIds) && count($selectedDatabaseIds) > 0, fn ($query) => $query->whereIn('id', $selectedDatabaseIds))
            ->get();

        $logger->write(null, $server->id, 'info', 'Creating MySQL backup queue records.', [
            'count' => $databases->count(),
            'manual' => $manual,
        ]);

        foreach ($databases as $database) {
            $extension = $configuration->encrypt ? 'sql.gz.enc' : 'sql.gz';
            $path = $storage->pathFor($server, $database->database, $extension);

            $record = MysqlBackupRecord::create([
                'uuid' => (string) Str::uuid(),
                'server_id' => $server->id,
                'database_id' => $database->id,
                'storage_provider_id' => $provider->id,
                'requested_by' => $requestedBy,
                'database_name' => $database->database,
                'status' => MysqlBackupStatus::QUEUED,
                'stage' => 'queued',
                'filename' => basename($path),
                'path' => $path,
                'compressed' => true,
                'encrypted' => $configuration->encrypt,
                'manual' => $manual,
                'metadata' => [
                    'storage_driver' => $provider->driver,
                    'storage_name' => $provider->name,
                    'queued_by_request' => $manual,
                ],
            ]);

            $logger->write($record, $server->id, 'info', 'Backup record created and dump job dispatched.', [
                'database' => $database->database,
                'storage_provider' => $provider->name,
            ]);

            ProcessMysqlDatabaseBackupJob::dispatch($record->id, $configuration->id);
            $records->push($record);
        }

        return $records;
    }
}
