<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier};

use Illuminate\Database\Eloquent\Model;

class MysqlBackupAuditLog extends Model
{
    protected $table = 'mysql_backup_audit_logs';

    protected $fillable = [
        'server_id',
        'user_id',
        'backup_record_id',
        'event',
        'ip_address',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];
}
