<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier};

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Pterodactyl\Models\Database;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;

class MysqlBackupRecord extends Model
{
    protected $table = 'mysql_backup_records';

    protected $fillable = [
        'uuid',
        'server_id',
        'database_id',
        'storage_provider_id',
        'requested_by',
        'database_name',
        'status',
        'stage',
        'filename',
        'path',
        'checksum_sha256',
        'size_bytes',
        'progress',
        'duration_ms',
        'compressed',
        'encrypted',
        'verified',
        'verified_at',
        'manual',
        'safety_backup',
        'parent_backup_uuid',
        'error_message',
        'metadata',
        'started_at',
        'completed_at',
        'expires_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'progress' => 'integer',
        'duration_ms' => 'integer',
        'compressed' => 'boolean',
        'encrypted' => 'boolean',
        'verified' => 'boolean',
        'verified_at' => 'datetime',
        'manual' => 'boolean',
        'safety_backup' => 'boolean',
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function database(): BelongsTo
    {
        return $this->belongsTo(Database::class);
    }

    public function storageProvider(): BelongsTo
    {
        return $this->belongsTo(MysqlBackupStorageProvider::class, 'storage_provider_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(MysqlBackupLog::class, 'backup_record_id');
    }
}
