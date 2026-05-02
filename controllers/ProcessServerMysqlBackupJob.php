<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier};

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Pterodactyl\Models\Server;

class ProcessServerMysqlBackupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        public int $configurationId,
        public ?int $requestedBy = null,
        public ?array $databaseIds = null,
        public bool $manual = false,
    ) {}

    public function handle(MysqlBackupQueueService $queue): void
    {
        $configuration = MysqlBackupConfiguration::query()->findOrFail($this->configurationId);
        $server = Server::query()->findOrFail($configuration->server_id);
        $queue->queueServer($configuration, $server, $this->requestedBy, $this->databaseIds, $this->manual);
    }
}
