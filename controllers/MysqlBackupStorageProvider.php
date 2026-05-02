<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier};

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Pterodactyl\Models\Server;

class MysqlBackupStorageProvider extends Model
{
    protected $table = 'mysql_backup_storage_providers';

    protected $fillable = [
        'server_id',
        'name',
        'driver',
        'config_encrypted',
        'is_global',
        'is_default',
        'enabled',
        'last_tested_at',
        'last_test_status',
        'last_test_message',
    ];

    protected $casts = [
        'is_global' => 'boolean',
        'is_default' => 'boolean',
        'enabled' => 'boolean',
        'last_tested_at' => 'datetime',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function getConfig(): array
    {
        return (array) Crypt::decrypt($this->config_encrypted);
    }

    public function setConfig(array $config): void
    {
        $this->config_encrypted = Crypt::encrypt($config);
    }
}
