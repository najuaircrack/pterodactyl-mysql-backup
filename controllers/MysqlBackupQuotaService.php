<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier};

use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;

class MysqlBackupQuotaService
{
    public function serverUsageBytes(Server|int $server): int
    {
        $serverId = $server instanceof Server ? $server->id : $server;

        return (int) MysqlBackupRecord::query()
            ->where('server_id', $serverId)
            ->whereIn('status', [MysqlBackupStatus::SUCCESS, MysqlBackupStatus::RESTORED])
            ->sum('size_bytes');
    }

    public function userUsageBytes(User|int|null $user): int
    {
        if (!$user) {
            return 0;
        }

        $userId = $user instanceof User ? $user->id : $user;

        return (int) MysqlBackupRecord::query()
            ->where('requested_by', $userId)
            ->whereIn('status', [MysqlBackupStatus::SUCCESS, MysqlBackupStatus::RESTORED])
            ->sum('size_bytes');
    }

    public function assertCanQueue(Server $server, ?User $user, MysqlBackupAdminSettingsService $settings): void
    {
        $limits = $settings->limitsResponse($server);
        $serverQuota = ((int) $limits['server_quota_mb']) * 1024 * 1024;

        if ($serverQuota > 0 && $this->serverUsageBytes($server) >= $serverQuota) {
            throw new \RuntimeException('This server has reached its MySQL backup storage quota.');
        }

        if ($user && $limits['user_quota_mb']) {
            $userQuota = ((int) $limits['user_quota_mb']) * 1024 * 1024;

            if ($userQuota > 0 && $this->userUsageBytes($user) >= $userQuota) {
                throw new \RuntimeException('Your MySQL backup storage quota has been reached.');
            }
        }
    }

    public function response(Server $server, ?User $user, MysqlBackupAdminSettingsService $settings): array
    {
        $limits = $settings->limitsResponse($server);

        return [
            'server_used_bytes' => $this->serverUsageBytes($server),
            'server_quota_bytes' => ((int) $limits['server_quota_mb']) * 1024 * 1024,
            'user_used_bytes' => $this->userUsageBytes($user),
            'user_quota_bytes' => $limits['user_quota_mb'] ? ((int) $limits['user_quota_mb']) * 1024 * 1024 : null,
        ];
    }
}
