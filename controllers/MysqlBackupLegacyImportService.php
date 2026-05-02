<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier};

use Illuminate\Support\Str;
use Pterodactyl\Models\Server;

class MysqlBackupLegacyImportService
{
    public function importForServer(Server $server, MysqlBackupStorageManager $storage): int
    {
        $provider = $storage->localProvider($server);
        $config = $provider->getConfig();
        $root = rtrim($storage->normalizeLocalRoot((string) ($config['root'] ?? '/var/lib/pterodactyl/backups/databases')), DIRECTORY_SEPARATOR);

        if (!is_dir($root)) {
            return 0;
        }

        $imported = 0;
        $databases = $server->databases()->with('host')->get();

        foreach ($databases as $database) {
            $host = $database->host?->host;

            if (!$host) {
                continue;
            }

            foreach (glob($root . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . $host . DIRECTORY_SEPARATOR . $database->database . '.sql*') ?: [] as $file) {
                if (!is_file($file)) {
                    continue;
                }

                $relativePath = ltrim(str_replace($root, '', $file), DIRECTORY_SEPARATOR);

                if (MysqlBackupRecord::query()->where('server_id', $server->id)->where('path', $relativePath)->exists()) {
                    continue;
                }

                $completedAt = now()->setTimestamp(filemtime($file) ?: time());
                MysqlBackupRecord::create([
                    'uuid' => (string) Str::uuid(),
                    'server_id' => $server->id,
                    'database_id' => $database->id,
                    'storage_provider_id' => $provider->id,
                    'database_name' => $database->database,
                    'status' => MysqlBackupStatus::SUCCESS,
                    'stage' => 'legacy_imported',
                    'filename' => basename($file),
                    'path' => $relativePath,
                    'checksum_sha256' => hash_file('sha256', $file),
                    'size_bytes' => filesize($file) ?: 0,
                    'progress' => 100,
                    'compressed' => str_ends_with($file, '.gz'),
                    'encrypted' => str_ends_with($file, '.enc'),
                    'verified' => true,
                    'verified_at' => $completedAt,
                    'manual' => false,
                    'metadata' => [
                        'legacy_path' => $file,
                        'legacy_imported' => true,
                    ],
                    'started_at' => $completedAt,
                    'completed_at' => $completedAt,
                    'created_at' => $completedAt,
                    'updated_at' => now(),
                ]);

                $imported++;
            }
        }

        return $imported;
    }
}
