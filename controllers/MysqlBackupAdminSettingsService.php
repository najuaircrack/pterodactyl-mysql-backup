<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier};

use Illuminate\Support\Facades\Crypt;

class MysqlBackupAdminSettingsService
{
    public const KEY = 'global';

    public function get(): array
    {
        $row = MysqlBackupAdminSetting::query()->where('key', self::KEY)->first();

        return array_replace_recursive($this->defaults(), $row?->value ?: []);
    }

    public function getForServer(int|\Pterodactyl\Models\Server|null $server = null): array
    {
        $serverId = $server instanceof \Pterodactyl\Models\Server ? $server->id : $server;

        if (!$serverId) {
            return $this->get();
        }

        $row = MysqlBackupAdminSetting::query()->where('key', 'server:' . $serverId)->first();

        return array_replace_recursive($this->get(), $row?->value ?: []);
    }

    public function save(array $settings): array
    {
        $settings = array_replace_recursive($this->defaults(), $settings);
        $settings = $this->normalize($settings);

        MysqlBackupAdminSetting::query()->updateOrCreate(
            ['key' => self::KEY],
            ['value' => $settings],
        );

        return $settings;
    }

    public function saveForServer(int $serverId, array $settings): array
    {
        $settings = $this->normalize(array_replace_recursive($this->get(), $settings));

        // OAuth app credentials are global — strip them so server-specific
        // rows never shadow the global OAuth configuration.
        unset($settings['oauth']);

        MysqlBackupAdminSetting::query()->updateOrCreate(
            ['key' => 'server:' . $serverId],
            ['value' => $settings],
        );

        return $settings;
    }

    public function defaults(): array
    {
        return [
            'policy' => [
                'default_enabled' => false,
                'default_interval_minutes' => 60,
                'min_interval_minutes' => 1,
                'max_interval_minutes' => 43200,
                'default_retention_count' => 14,
                'max_retention_count' => 100,
                'default_retention_days' => null,
                'max_retention_days' => 365,
                'default_encrypt' => false,
                'force_encrypt' => false,
                'max_history_items' => 200,
                'manual_cooldown_minutes' => 10,
                'server_quota_mb' => 10240,
                'user_quota_mb' => null,
                'pre_restore_safety_backup' => true,
                'verify_after_upload' => true,
                'max_concurrent_server_jobs' => 1,
            ],
            'providers' => [
                'default_storage_provider_id' => null,
                'allow_server_providers' => true,
                'allow_local' => true,
                'allow_google_drive' => true,
                'allow_onedrive' => true,
                'allow_dropbox' => true,
                'allow_box' => true,
                'allow_mega' => true,
                'allow_pcloud' => true,
                'allow_yandex_disk' => true,
                'allow_webdav' => true,
                'allow_rclone' => false,
                'allow_s3' => true,
                'allow_aws_s3' => true,
                'allow_cloudflare_r2' => true,
                'allow_minio' => true,
                'allow_wasabi' => true,
                'allow_backblaze_b2' => true,
                'allow_digitalocean_spaces' => true,
                'allow_linode_object_storage' => true,
                'allow_vultr_object_storage' => true,
                'allow_scaleway_object_storage' => true,
                'allow_oracle_object_storage' => true,
                'allow_google_cloud_storage' => true,
                'allow_ftp' => true,
                'allow_ftps' => true,
                'allow_sftp' => true,
            ],
            'runtime' => [
                'dump_timeout_seconds' => 3600,
                'restore_timeout_seconds' => 3600,
                'reconcile_minutes' => 5,
                'local_root' => env('MYSQL_BACKUP_LOCAL_ROOT', '/var/lib/pterodactyl/backups/databases'),
                'dump_credential_mode' => 'database',
                'dump_username' => env('MYSQL_BACKUP_DUMP_USERNAME', ''),
                'dump_password' => env('MYSQL_BACKUP_DUMP_PASSWORD', ''),
                'dump_host' => env('MYSQL_BACKUP_DUMP_HOST', ''),
            ],
            // Admin-owned OAuth apps. When these are filled in, users get a
            // one-click "Connect" button and never touch a client id/secret.
            'oauth' => [
                'google_drive' => ['client_id' => '', 'client_secret' => ''],
                'dropbox' => ['client_id' => '', 'client_secret' => ''],
                'onedrive' => ['client_id' => '', 'client_secret' => '', 'tenant' => 'common'],
            ],
        ];
    }

    public function defaultConfigurationAttributes(int|\Pterodactyl\Models\Server|null $server = null): array
    {
        $settings = $this->getForServer($server);
        $policy = $settings['policy'];

        return [
            'enabled' => (bool) $policy['default_enabled'],
            'frequency_type' => 'interval',
            'interval_minutes' => (int) $policy['default_interval_minutes'],
            'retention_count' => (int) $policy['default_retention_count'],
            'retention_days' => $policy['default_retention_days'],
            'compress' => true,
            'encrypt' => (bool) ($policy['force_encrypt'] || $policy['default_encrypt']),
            'storage_provider_id' => $settings['providers']['default_storage_provider_id'],
        ];
    }

    public function limitsResponse(int|\Pterodactyl\Models\Server|null $server = null): array
    {
        $settings = $this->getForServer($server);

        return [
            'min_interval_minutes' => (int) $settings['policy']['min_interval_minutes'],
            'max_interval_minutes' => (int) $settings['policy']['max_interval_minutes'],
            'max_retention_count' => (int) $settings['policy']['max_retention_count'],
            'max_retention_days' => (int) $settings['policy']['max_retention_days'],
            'force_encrypt' => (bool) $settings['policy']['force_encrypt'],
            'max_history_items' => (int) $settings['policy']['max_history_items'],
            'manual_cooldown_minutes' => (int) $settings['policy']['manual_cooldown_minutes'],
            'server_quota_mb' => (int) $settings['policy']['server_quota_mb'],
            'user_quota_mb' => $settings['policy']['user_quota_mb'] ? (int) $settings['policy']['user_quota_mb'] : null,
            'pre_restore_safety_backup' => (bool) $settings['policy']['pre_restore_safety_backup'],
            'verify_after_upload' => (bool) $settings['policy']['verify_after_upload'],
            'max_concurrent_server_jobs' => (int) $settings['policy']['max_concurrent_server_jobs'],
            'allow_server_providers' => (bool) $settings['providers']['allow_server_providers'],
            'allowed_drivers' => $this->allowedDrivers($settings),
            'oauth_providers' => $this->oauthOneClickProviders($settings),
        ];
    }

    /**
     * OAuth providers that are ready for one-click "Connect": the admin has
     * filled in an app (client id + secret) AND the driver is allowed.
     */
    public function oauthOneClickProviders(?array $settings = null): array
    {
        $settings ??= $this->get();
        $allowed = $this->allowedDrivers($settings);
        $out = [];

        foreach (['google_drive', 'dropbox', 'onedrive'] as $provider) {
            if (in_array($provider, $allowed, true) && $this->oauthApp($provider, $settings) !== null) {
                $out[] = $provider;
            }
        }

        return $out;
    }

    /**
     * Return the admin-configured OAuth app credentials for a provider, or null
     * when the admin has not set them up yet.
     */
    public function oauthApp(string $provider, ?array $settings = null): ?array
    {
        $settings ??= $this->get();
        $entry = $settings['oauth'][$provider] ?? null;

        if (!is_array($entry)) {
            return null;
        }

        $clientId = trim((string) ($entry['client_id'] ?? ''));
        $clientSecret = $this->revealSecret($entry['client_secret'] ?? '');

        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'tenant' => trim((string) ($entry['tenant'] ?? 'common')) ?: 'common',
        ];
    }

    public function allowedDrivers(?array $settings = null): array
    {
        $settings ??= $this->get();
        $providers = $settings['providers'];

        return collect([
            'local' => $providers['allow_local'],
            'google_drive' => $providers['allow_google_drive'],
            'onedrive' => $providers['allow_onedrive'],
            'dropbox' => $providers['allow_dropbox'],
            'box' => $providers['allow_box'],
            'mega' => $providers['allow_mega'],
            'pcloud' => $providers['allow_pcloud'],
            'yandex_disk' => $providers['allow_yandex_disk'],
            'webdav' => $providers['allow_webdav'],
            'rclone' => $providers['allow_rclone'],
            's3' => $providers['allow_s3'],
            'aws_s3' => $providers['allow_aws_s3'],
            'cloudflare_r2' => $providers['allow_cloudflare_r2'],
            'minio' => $providers['allow_minio'],
            'wasabi' => $providers['allow_wasabi'],
            'backblaze_b2' => $providers['allow_backblaze_b2'],
            'digitalocean_spaces' => $providers['allow_digitalocean_spaces'],
            'linode_object_storage' => $providers['allow_linode_object_storage'],
            'vultr_object_storage' => $providers['allow_vultr_object_storage'],
            'scaleway_object_storage' => $providers['allow_scaleway_object_storage'],
            'oracle_object_storage' => $providers['allow_oracle_object_storage'],
            'google_cloud_storage' => $providers['allow_google_cloud_storage'],
            'ftp' => $providers['allow_ftp'],
            'ftps' => $providers['allow_ftps'],
            'sftp' => $providers['allow_sftp'],
        ])->filter()->keys()->values()->all();
    }

    public function normalize(array $settings): array
    {
        $policy = $settings['policy'];
        $providers = $settings['providers'];
        $runtime = $settings['runtime'];

        $policy['min_interval_minutes'] = max(1, (int) $policy['min_interval_minutes']);
        $policy['max_interval_minutes'] = max($policy['min_interval_minutes'], (int) $policy['max_interval_minutes']);
        $policy['default_interval_minutes'] = min(
            $policy['max_interval_minutes'],
            max($policy['min_interval_minutes'], (int) $policy['default_interval_minutes'])
        );
        $policy['max_retention_count'] = max(1, (int) $policy['max_retention_count']);
        $policy['default_retention_count'] = min($policy['max_retention_count'], max(1, (int) $policy['default_retention_count']));
        $policy['max_retention_days'] = max(1, (int) $policy['max_retention_days']);
        $policy['default_retention_days'] = $policy['default_retention_days'] === null || $policy['default_retention_days'] === ''
            ? null
            : min($policy['max_retention_days'], max(1, (int) $policy['default_retention_days']));
        $policy['max_history_items'] = min(1000, max(25, (int) $policy['max_history_items']));
        $policy['manual_cooldown_minutes'] = max(0, (int) $policy['manual_cooldown_minutes']);
        $policy['server_quota_mb'] = max(0, (int) $policy['server_quota_mb']);
        $policy['user_quota_mb'] = $policy['user_quota_mb'] === null || $policy['user_quota_mb'] === ''
            ? null
            : max(0, (int) $policy['user_quota_mb']);
        $policy['pre_restore_safety_backup'] = (bool) $policy['pre_restore_safety_backup'];
        $policy['verify_after_upload'] = (bool) $policy['verify_after_upload'];
        $policy['max_concurrent_server_jobs'] = max(1, (int) $policy['max_concurrent_server_jobs']);
        $policy['force_encrypt'] = (bool) $policy['force_encrypt'];
        $policy['default_encrypt'] = (bool) $policy['default_encrypt'];
        $policy['default_enabled'] = (bool) $policy['default_enabled'];

        foreach ([
            'allow_server_providers',
            'allow_local',
            'allow_google_drive',
            'allow_onedrive',
            'allow_dropbox',
            'allow_box',
            'allow_mega',
            'allow_pcloud',
            'allow_yandex_disk',
            'allow_webdav',
            'allow_rclone',
            'allow_s3',
            'allow_aws_s3',
            'allow_cloudflare_r2',
            'allow_minio',
            'allow_wasabi',
            'allow_backblaze_b2',
            'allow_digitalocean_spaces',
            'allow_linode_object_storage',
            'allow_vultr_object_storage',
            'allow_scaleway_object_storage',
            'allow_oracle_object_storage',
            'allow_google_cloud_storage',
            'allow_ftp',
            'allow_ftps',
            'allow_sftp',
        ] as $key) {
            $providers[$key] = (bool) $providers[$key];
        }

        if (count($this->allowedDrivers(['providers' => $providers])) === 0) {
            $providers['allow_local'] = true;
        }
        $providers['default_storage_provider_id'] = $providers['default_storage_provider_id']
            ? (int) $providers['default_storage_provider_id']
            : null;

        $runtime['dump_timeout_seconds'] = max(60, (int) $runtime['dump_timeout_seconds']);
        $runtime['restore_timeout_seconds'] = max(60, (int) $runtime['restore_timeout_seconds']);
        $runtime['reconcile_minutes'] = max(1, (int) $runtime['reconcile_minutes']);
        $runtime['local_root'] = trim((string) $runtime['local_root']) ?: '/var/lib/pterodactyl/backups/databases';
        $runtime['dump_credential_mode'] = in_array($runtime['dump_credential_mode'] ?? 'database', ['database', 'backup_user'], true)
            ? $runtime['dump_credential_mode']
            : 'database';
        $runtime['dump_username'] = trim((string) ($runtime['dump_username'] ?? ''));
        $runtime['dump_host'] = trim((string) ($runtime['dump_host'] ?? ''));
        $runtime['dump_password'] = $this->prepareSecret((string) ($runtime['dump_password'] ?? ''));

        $oauth = $settings['oauth'] ?? [];
        foreach (['google_drive', 'dropbox', 'onedrive'] as $provider) {
            $entry = is_array($oauth[$provider] ?? null) ? $oauth[$provider] : [];
            $normalized = [
                'client_id' => trim((string) ($entry['client_id'] ?? '')),
                'client_secret' => $this->prepareSecret((string) ($entry['client_secret'] ?? '')),
            ];

            if ($provider === 'onedrive') {
                $normalized['tenant'] = trim((string) ($entry['tenant'] ?? 'common')) ?: 'common';
            }

            $oauth[$provider] = $normalized;
        }

        return [
            'policy' => $policy,
            'providers' => $providers,
            'runtime' => $runtime,
            'oauth' => $oauth,
        ];
    }

    public function revealSecret(?string $value): string
    {
        $value = (string) $value;

        if (!str_starts_with($value, 'crypt:')) {
            return $value;
        }

        try {
            return Crypt::decryptString(substr($value, 6));
        } catch (\Throwable) {
            return '';
        }
    }

    private function prepareSecret(string $value): string
    {
        if ($value === '' || str_starts_with($value, 'crypt:')) {
            return $value;
        }

        return 'crypt:' . Crypt::encryptString($value);
    }
}
