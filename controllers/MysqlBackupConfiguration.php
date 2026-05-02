<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier};

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\Server;

class MysqlBackupConfiguration extends Model
{
    protected $table = 'mysql_backup_configurations';

    protected $fillable = [
        'server_id',
        'storage_provider_id',
        'enabled',
        'database_ids',
        'frequency_type',
        'interval_minutes',
        'cron_expression',
        'retention_count',
        'retention_days',
        'compress',
        'encrypt',
        'notifications',
        'next_run_at',
        'last_queued_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'database_ids' => 'array',
        'interval_minutes' => 'integer',
        'retention_count' => 'integer',
        'retention_days' => 'integer',
        'compress' => 'boolean',
        'encrypt' => 'boolean',
        'notifications' => 'array',
        'next_run_at' => 'datetime',
        'last_queued_at' => 'datetime',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function storageProvider(): BelongsTo
    {
        return $this->belongsTo(MysqlBackupStorageProvider::class, 'storage_provider_id');
    }
}
