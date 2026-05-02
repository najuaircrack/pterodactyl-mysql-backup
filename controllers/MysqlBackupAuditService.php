<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier};

use Illuminate\Http\Request;

class MysqlBackupAuditService
{
    public function record(string $event, array $metadata = [], ?Request $request = null, ?MysqlBackupRecord $backup = null): void
    {
        MysqlBackupAuditLog::create([
            'server_id' => $metadata['server_id'] ?? $backup?->server_id,
            'user_id' => $metadata['user_id'] ?? $request?->user()?->id,
            'backup_record_id' => $backup?->id,
            'event' => $event,
            'ip_address' => $request?->ip(),
            'metadata' => $metadata ?: null,
        ]);
    }
}
