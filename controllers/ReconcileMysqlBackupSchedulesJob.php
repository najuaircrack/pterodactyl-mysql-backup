<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier};

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcileMysqlBackupSchedulesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;

    public function handle(MysqlBackupSchedulerService $scheduler): void
    {
        $scheduler->reconcile();

        if (MysqlBackupConfiguration::query()->where('enabled', true)->exists()) {
            $settings = app(MysqlBackupAdminSettingsService::class)->get();
            self::dispatch()->delay(now()->addMinutes((int) $settings['runtime']['reconcile_minutes']));
        }
    }
}
