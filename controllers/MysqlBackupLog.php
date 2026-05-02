<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier};

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\Server;

class MysqlBackupLog extends Model
{
    protected $table = 'mysql_backup_logs';

    protected $fillable = [
        'backup_record_id',
        'server_id',
        'level',
        'message',
        'context',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    public function backup(): BelongsTo
    {
        return $this->belongsTo(MysqlBackupRecord::class, 'backup_record_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
