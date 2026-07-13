<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier};

/**
 * Protects against Server-Side Request Forgery (SSRF) by blocking requests
 * to private, reserved, or link-local IP ranges. Used for user-controlled
 * URLs like WebDAV endpoints and notification webhooks.
 */
class MysqlBackupSsrfGuard
{
    /**
     * Returns true when the URL is safe to fetch from the panel host.
     * Admins can bypass this check with MYSQL_BACKUP_ALLOW_PRIVATE_URLS=true.
     */
    public function isAllowed(string $url): bool
    {
        if ($this->bypassEnabled()) {
            return true;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return false;
        }

        $ips = [];

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            foreach ((array) @dns_get_record($host, DNS_A + DNS_AAAA) as $record) {
                if (!empty($record['ip'])) {
                    $ips[] = $record['ip'];
                }
                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }

    private function bypassEnabled(): bool
    {
        return filter_var(env('MYSQL_BACKUP_ALLOW_PRIVATE_URLS', env('MYSQL_BACKUP_ALLOW_PRIVATE_WEBHOOKS', false)), FILTER_VALIDATE_BOOLEAN);
    }
}
