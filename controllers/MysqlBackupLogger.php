<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier};

use Illuminate\Support\Facades\Log;

class MysqlBackupLogger
{
    public function write(?MysqlBackupRecord $record, int $serverId, string $level, string $message, array $context = []): void
    {
        MysqlBackupLog::create([
            'backup_record_id' => $record?->id,
            'server_id' => $serverId,
            'level' => $level,
            'message' => $message,
            'context' => $context ?: null,
        ]);

        Log::log($level, '[mysql-backups] ' . $message, array_merge($context, [
            'backup_record_id' => $record?->id,
            'server_id' => $serverId,
        ]));
    }
}
