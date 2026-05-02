<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier};

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class MysqlBackupSchedulerService
{
    public function dueConfigurations(): Collection
    {
        return MysqlBackupConfiguration::query()
            ->where('enabled', true)
            ->where(function ($query) {
                $query->whereNull('next_run_at')
                    ->orWhere('next_run_at', '<=', CarbonImmutable::now());
            })
            ->get();
    }

    public function reconcile(): int
    {
        $count = 0;
        $queue = app(MysqlBackupQueueService::class);

        foreach ($this->dueConfigurations() as $configuration) {
            try {
                if ($configuration->server) {
                    $queue->queueServer($configuration, $configuration->server, null, null, false);
                }
            } catch (\Throwable $exception) {
                app(MysqlBackupLogger::class)->write(null, $configuration->server_id, 'error', 'Scheduled backup could not be queued.', [
                    'message' => $exception->getMessage(),
                ]);
            }

            $configuration->forceFill([
                'last_queued_at' => CarbonImmutable::now(),
                'next_run_at' => $this->nextRunAt($configuration),
            ])->save();

            $count++;
        }

        return $count;
    }

    public function saveAndSchedule(MysqlBackupConfiguration $configuration, array $attributes): MysqlBackupConfiguration
    {
        $attributes['frequency_type'] = 'interval';
        $attributes['cron_expression'] = null;
        $attributes['compress'] = true;

        $configuration->fill($attributes);
        $configuration->next_run_at = $configuration->enabled
            ? $this->nextRunAt($configuration, CarbonImmutable::now())
            : null;
        $configuration->save();

        if ($configuration->enabled) {
            ReconcileMysqlBackupSchedulesJob::dispatch()->delay(now()->addMinute());
        }

        return $configuration;
    }

    public function nextRunAt(MysqlBackupConfiguration $configuration, ?CarbonImmutable $from = null): CarbonImmutable
    {
        $from ??= CarbonImmutable::now();

        $minutes = max(1, (int) $configuration->interval_minutes);

        return $from->addMinutes($minutes);
    }
}
