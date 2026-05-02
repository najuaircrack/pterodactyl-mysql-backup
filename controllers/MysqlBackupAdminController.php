<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier};

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Pterodactyl\Models\Server;

class MysqlBackupAdminController extends Controller
{
    public function settings(Request $request, MysqlBackupAdminSettingsService $settings): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $data = Validator::make($request->all(), [
            'policy.default_enabled' => ['nullable', 'boolean'],
            'policy.default_interval_minutes' => ['required', 'integer', 'min:1', 'max:43200'],
            'policy.min_interval_minutes' => ['required', 'integer', 'min:1', 'max:43200'],
            'policy.max_interval_minutes' => ['required', 'integer', 'min:1', 'max:43200'],
            'policy.default_retention_count' => ['required', 'integer', 'min:1', 'max:500'],
            'policy.max_retention_count' => ['required', 'integer', 'min:1', 'max:500'],
            'policy.default_retention_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'policy.max_retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'policy.default_encrypt' => ['nullable', 'boolean'],
            'policy.force_encrypt' => ['nullable', 'boolean'],
            'policy.max_history_items' => ['required', 'integer', 'min:25', 'max:1000'],
            'policy.manual_cooldown_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'policy.server_quota_mb' => ['required', 'integer', 'min:0', 'max:10485760'],
            'policy.user_quota_mb' => ['nullable', 'integer', 'min:0', 'max:10485760'],
            'policy.pre_restore_safety_backup' => ['nullable', 'boolean'],
            'policy.verify_after_upload' => ['nullable', 'boolean'],
            'policy.max_concurrent_server_jobs' => ['required', 'integer', 'min:1', 'max:25'],
            'providers.default_storage_provider_id' => ['nullable', 'integer'],
            'providers.allow_server_providers' => ['nullable', 'boolean'],
            'providers.allow_local' => ['nullable', 'boolean'],
            'providers.allow_google_drive' => ['nullable', 'boolean'],
            'providers.allow_onedrive' => ['nullable', 'boolean'],
            'providers.allow_dropbox' => ['nullable', 'boolean'],
            'providers.allow_box' => ['nullable', 'boolean'],
            'providers.allow_mega' => ['nullable', 'boolean'],
            'providers.allow_pcloud' => ['nullable', 'boolean'],
            'providers.allow_yandex_disk' => ['nullable', 'boolean'],
            'providers.allow_webdav' => ['nullable', 'boolean'],
            'providers.allow_rclone' => ['nullable', 'boolean'],
            'providers.allow_s3' => ['nullable', 'boolean'],
            'providers.allow_aws_s3' => ['nullable', 'boolean'],
            'providers.allow_cloudflare_r2' => ['nullable', 'boolean'],
            'providers.allow_minio' => ['nullable', 'boolean'],
            'providers.allow_wasabi' => ['nullable', 'boolean'],
            'providers.allow_backblaze_b2' => ['nullable', 'boolean'],
            'providers.allow_digitalocean_spaces' => ['nullable', 'boolean'],
            'providers.allow_linode_object_storage' => ['nullable', 'boolean'],
            'providers.allow_vultr_object_storage' => ['nullable', 'boolean'],
            'providers.allow_scaleway_object_storage' => ['nullable', 'boolean'],
            'providers.allow_oracle_object_storage' => ['nullable', 'boolean'],
            'providers.allow_google_cloud_storage' => ['nullable', 'boolean'],
            'providers.allow_ftp' => ['nullable', 'boolean'],
            'providers.allow_ftps' => ['nullable', 'boolean'],
            'providers.allow_sftp' => ['nullable', 'boolean'],
            'runtime.dump_timeout_seconds' => ['required', 'integer', 'min:60', 'max:86400'],
            'runtime.restore_timeout_seconds' => ['required', 'integer', 'min:60', 'max:86400'],
            'runtime.reconcile_minutes' => ['required', 'integer', 'min:1', 'max:60'],
            'runtime.local_root' => ['required', 'string', 'max:500'],
            'runtime.dump_credential_mode' => ['required', Rule::in(['database', 'backup_user'])],
            'runtime.dump_username' => ['nullable', 'string', 'max:255'],
            'runtime.dump_password' => ['nullable', 'string', 'max:2048'],
            'runtime.dump_host' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $data['policy']['default_enabled'] = $request->boolean('policy.default_enabled');
        $data['policy']['default_encrypt'] = $request->boolean('policy.default_encrypt');
        $data['policy']['force_encrypt'] = $request->boolean('policy.force_encrypt');
        $data['policy']['pre_restore_safety_backup'] = $request->boolean('policy.pre_restore_safety_backup');
        $data['policy']['verify_after_upload'] = $request->boolean('policy.verify_after_upload');
        $data['providers']['allow_server_providers'] = $request->boolean('providers.allow_server_providers');
        $data['providers']['allow_local'] = $request->boolean('providers.allow_local');
        $data['providers']['allow_google_drive'] = $request->boolean('providers.allow_google_drive');
        $data['providers']['allow_onedrive'] = $request->boolean('providers.allow_onedrive');
        $data['providers']['allow_dropbox'] = $request->boolean('providers.allow_dropbox');
        $data['providers']['allow_box'] = $request->boolean('providers.allow_box');
        $data['providers']['allow_mega'] = $request->boolean('providers.allow_mega');
        $data['providers']['allow_pcloud'] = $request->boolean('providers.allow_pcloud');
        $data['providers']['allow_yandex_disk'] = $request->boolean('providers.allow_yandex_disk');
        $data['providers']['allow_webdav'] = $request->boolean('providers.allow_webdav');
        $data['providers']['allow_rclone'] = $request->boolean('providers.allow_rclone');
        $data['providers']['allow_s3'] = $request->boolean('providers.allow_s3');
        $data['providers']['allow_aws_s3'] = $request->boolean('providers.allow_aws_s3');
        $data['providers']['allow_cloudflare_r2'] = $request->boolean('providers.allow_cloudflare_r2');
        $data['providers']['allow_minio'] = $request->boolean('providers.allow_minio');
        $data['providers']['allow_wasabi'] = $request->boolean('providers.allow_wasabi');
        $data['providers']['allow_backblaze_b2'] = $request->boolean('providers.allow_backblaze_b2');
        $data['providers']['allow_digitalocean_spaces'] = $request->boolean('providers.allow_digitalocean_spaces');
        $data['providers']['allow_linode_object_storage'] = $request->boolean('providers.allow_linode_object_storage');
        $data['providers']['allow_vultr_object_storage'] = $request->boolean('providers.allow_vultr_object_storage');
        $data['providers']['allow_scaleway_object_storage'] = $request->boolean('providers.allow_scaleway_object_storage');
        $data['providers']['allow_oracle_object_storage'] = $request->boolean('providers.allow_oracle_object_storage');
        $data['providers']['allow_google_cloud_storage'] = $request->boolean('providers.allow_google_cloud_storage');
        $data['providers']['allow_ftp'] = $request->boolean('providers.allow_ftp');
        $data['providers']['allow_ftps'] = $request->boolean('providers.allow_ftps');
        $data['providers']['allow_sftp'] = $request->boolean('providers.allow_sftp');

        $providerId = $data['providers']['default_storage_provider_id'] ?? null;
        if ($providerId && !MysqlBackupStorageProvider::query()->where('id', $providerId)->where('is_global', true)->exists()) {
            return back()->withErrors(['providers.default_storage_provider_id' => 'Default storage must be a global provider.']);
        }

        $scopeServerId = $request->input('scope_server_id');
        if (($data['runtime']['dump_password'] ?? '') === '') {
            $currentSettings = $scopeServerId ? $settings->getForServer((int) $scopeServerId) : $settings->get();
            $data['runtime']['dump_password'] = $currentSettings['runtime']['dump_password'] ?? '';
        }

        if ($scopeServerId) {
            Server::query()->findOrFail($scopeServerId);
            $settings->saveForServer((int) $scopeServerId, $data);
        } else {
            $settings->save($data);
        }
        app(MysqlBackupAuditService::class)->record('admin_settings_saved', [
            'server_id' => $scopeServerId ? (int) $scopeServerId : null,
            'scope' => $scopeServerId ? 'server' : 'global',
        ], $request);

        return back()->with('success', $scopeServerId ? 'Server MySQL backup settings saved.' : 'Global MySQL backup defaults saved.');
    }

    public function storeProvider(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $settings = app(MysqlBackupAdminSettingsService::class);
        if ($request->input('driver') === 'local') {
            return back()->withErrors(['driver' => 'Local storage is built in. Configure its root under Runtime Limits.']);
        }

        $data = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'driver' => ['required', Rule::in(array_values(array_diff($settings->allowedDrivers(), ['local'])))],
            'is_default' => ['nullable', 'boolean'],
            'config' => ['required', 'array'],
        ])->validate();

        $config = $this->sanitizeProviderConfig($data['driver'], $data['config']);
        if ($message = $this->providerConfigError($data['driver'], $config)) {
            return back()->withErrors(['config' => $message]);
        }

        $provider = new MysqlBackupStorageProvider([
            'server_id' => null,
            'name' => $data['name'],
            'driver' => $data['driver'],
            'is_global' => true,
            'is_default' => $request->boolean('is_default'),
            'enabled' => true,
        ]);
        $provider->setConfig($config);
        $provider->save();

        if ($provider->is_default) {
            MysqlBackupStorageProvider::query()
                ->where('id', '!=', $provider->id)
                ->where('is_global', true)
                ->update(['is_default' => false]);

            $current = $settings->get();
            $current['providers']['default_storage_provider_id'] = $provider->id;
            $settings->save($current);
        }
        app(MysqlBackupAuditService::class)->record('global_provider_created', [
            'provider_id' => $provider->id,
            'provider_name' => $provider->name,
            'driver' => $provider->driver,
        ], $request);

        return back()->with('success', 'Global storage provider saved.');
    }

    public function deleteProvider(Request $request, int $provider): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $storageProvider = MysqlBackupStorageProvider::query()
            ->where('is_global', true)
            ->findOrFail($provider);

        $storageProvider->forceFill(['enabled' => false])->save();
        app(MysqlBackupAuditService::class)->record('global_provider_disabled', [
            'provider_id' => $storageProvider->id,
            'provider_name' => $storageProvider->name,
        ], $request);

        return back()->with('success', 'Global storage provider disabled.');
    }

    public function testProvider(
        Request $request,
        int $provider,
        MysqlBackupStorageManager $storage,
        MysqlBackupProviderTestService $tester,
        MysqlBackupAuditService $audit,
    ): RedirectResponse {
        $this->authorizeAdmin($request);

        $storageProvider = MysqlBackupStorageProvider::query()
            ->where('is_global', true)
            ->where('enabled', true)
            ->findOrFail($provider);
        $result = $tester->test($storageProvider, $storage);

        $audit->record('provider_tested', [
            'provider_id' => $storageProvider->id,
            'provider_name' => $storageProvider->name,
            'status' => $result['status'],
            'message' => $result['message'],
        ], $request);

        return back()->with(
            $result['status'] === 'success' ? 'success' : 'error',
            $storageProvider->name . ': ' . $result['message'],
        );
    }

    private function sanitizeProviderConfig(string $driver, array $config): array
    {
        $allowed = match ($driver) {
            's3', 'aws_s3', 'cloudflare_r2', 'minio', 'wasabi', 'backblaze_b2', 'digitalocean_spaces', 'linode_object_storage', 'vultr_object_storage', 'scaleway_object_storage', 'oracle_object_storage', 'google_cloud_storage' => ['key', 'secret', 'region', 'bucket', 'endpoint', 'path_style'],
            'ftp', 'ftps' => ['host', 'username', 'password', 'port', 'root', 'ssl', 'passive', 'timeout'],
            'sftp' => ['host', 'username', 'password', 'private_key', 'passphrase', 'port', 'root', 'timeout'],
            'google_drive', 'onedrive', 'dropbox', 'box', 'mega', 'pcloud', 'yandex_disk', 'webdav', 'rclone' => ['remote', 'rclone_config'],
            default => ['root'],
        };

        return array_intersect_key($config, array_flip($allowed));
    }

    private function providerConfigError(string $driver, array $config): ?string
    {
        if (in_array($driver, ['google_drive', 'onedrive', 'dropbox', 'box', 'mega', 'pcloud', 'yandex_disk', 'webdav', 'rclone'], true)) {
            $remote = trim((string) ($config['remote'] ?? ''));

            if ($remote === '') {
                return 'Enter the rclone remote path, for example gdrive:pterodactyl/mysql-backups.';
            }

            if (!preg_match('/^[A-Za-z0-9_.-]+:.+$/', $remote)) {
                return 'Rclone remote paths must use a named remote, for example gdrive:pterodactyl/mysql-backups.';
            }

            if (str_contains($remote, '..')) {
                return 'Rclone remote paths cannot contain parent directory traversal.';
            }

            if (strlen((string) ($config['rclone_config'] ?? '')) > 65535) {
                return 'Rclone config is too large. Keep the provider config below 64 KB.';
            }

            return $this->rcloneConfigError($driver, $remote, (string) ($config['rclone_config'] ?? ''));
        }

        if (in_array($driver, ['s3', 'aws_s3', 'cloudflare_r2', 'minio', 'wasabi', 'backblaze_b2', 'digitalocean_spaces', 'linode_object_storage', 'vultr_object_storage', 'scaleway_object_storage', 'oracle_object_storage', 'google_cloud_storage'], true)) {
            return trim((string) ($config['bucket'] ?? '')) === ''
                ? 'Enter a bucket name for this storage provider.'
                : null;
        }

        if (in_array($driver, ['ftp', 'ftps', 'sftp'], true)) {
            return trim((string) ($config['host'] ?? '')) === '' || trim((string) ($config['username'] ?? '')) === ''
                ? 'Enter both host and username for this storage provider.'
                : null;
        }

        return null;
    }

    private function rcloneConfigError(string $driver, string $remote, string $rcloneConfig): ?string
    {
        if (trim($rcloneConfig) === '') {
            return null;
        }

        $remoteName = strtok($remote, ':') ?: '';
        if (!preg_match('/\[' . preg_quote($remoteName, '/') . '\](.*?)(?:\n\[|\z)/s', $rcloneConfig, $matches)) {
            return 'The rclone config must contain a section for the selected remote name.';
        }

        if (!preg_match('/^\s*type\s*=\s*([A-Za-z0-9_-]+)/mi', $matches[1], $typeMatch)) {
            return 'The rclone config remote section must include a type value.';
        }

        $allowedTypes = match ($driver) {
            'google_drive' => ['drive'],
            'onedrive' => ['onedrive'],
            'dropbox' => ['dropbox'],
            'box' => ['box'],
            'mega' => ['mega'],
            'pcloud' => ['pcloud'],
            'yandex_disk' => ['yandex'],
            'webdav' => ['webdav'],
            default => ['drive', 'onedrive', 'dropbox', 'box', 'mega', 'pcloud', 'yandex', 'webdav'],
        };

        return in_array(strtolower($typeMatch[1]), $allowedTypes, true)
            ? null
            : 'The rclone config type does not match the selected storage driver.';
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->root_admin, 403);
    }
}
