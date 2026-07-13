<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier};

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Pterodactyl\Models\Server;
use RuntimeException;
use Symfony\Component\Process\Process;

class MysqlBackupStorageManager
{
    public function providerFor(Server $server, ?MysqlBackupConfiguration $configuration = null): MysqlBackupStorageProvider
    {
        $settings = app(MysqlBackupAdminSettingsService::class)->getForServer($server);
        $defaultProviderId = $settings['providers']['default_storage_provider_id'] ?? null;

        if ($configuration?->storageProvider?->enabled) {
            return $configuration->storageProvider;
        }

        if ($defaultProviderId) {
            $defaultProvider = MysqlBackupStorageProvider::query()
                ->where('id', $defaultProviderId)
                ->where('enabled', true)
                ->where(function ($query) use ($server) {
                    $query->where('is_global', true)->orWhere('server_id', $server->id);
                })
                ->first();

            if ($defaultProvider) {
                return $defaultProvider;
            }
        }

        $provider = MysqlBackupStorageProvider::query()
            ->where('enabled', true)
            ->where(function ($query) use ($server) {
                $query->where('server_id', $server->id)->orWhere('is_global', true);
            })
            ->orderByDesc('server_id')
            ->orderByDesc('is_default')
            ->first();

        if ($provider) {
            return $provider;
        }

        return $this->localProvider($server);
    }

    public function localProvider(Server|int|null $server = null): MysqlBackupStorageProvider
    {
        $serverId = $server instanceof Server ? $server->id : $server;
        $settings = app(MysqlBackupAdminSettingsService::class)->getForServer($server);
        $hasServerOverride = $serverId && MysqlBackupAdminSetting::query()->where('key', 'server:' . $serverId)->exists();
        $providerQuery = MysqlBackupStorageProvider::query()
            ->where('driver', 'local')
            ->where('name', $hasServerOverride ? 'Panel local storage for server ' . $serverId : 'Panel local storage');

        $provider = $hasServerOverride
            ? $providerQuery->where('server_id', $serverId)->first()
            : $providerQuery->where('is_global', true)->first();

        if ($provider) {
            $provider->setConfig([
                'root' => $this->normalizeLocalRoot($settings['runtime']['local_root'] ?? env('MYSQL_BACKUP_LOCAL_ROOT', '/var/lib/pterodactyl/backups/databases')),
            ]);
            $provider->forceFill(['enabled' => true, 'is_default' => true, 'is_global' => !$hasServerOverride])->save();

            return $provider;
        }

        $provider = new MysqlBackupStorageProvider([
            'server_id' => $hasServerOverride ? $serverId : null,
            'name' => $hasServerOverride ? 'Panel local storage for server ' . $serverId : 'Panel local storage',
            'driver' => 'local',
            'is_global' => !$hasServerOverride,
            'is_default' => true,
            'enabled' => true,
        ]);
        $provider->setConfig([
            'root' => $this->normalizeLocalRoot($settings['runtime']['local_root'] ?? env('MYSQL_BACKUP_LOCAL_ROOT', '/var/lib/pterodactyl/backups/databases')),
        ]);
        $provider->save();

        return $provider;
    }

    public function fallbackProviders(Server $server, MysqlBackupStorageProvider $primary): Collection
    {
        return MysqlBackupStorageProvider::query()
            ->where('enabled', true)
            ->where('id', '!=', $primary->id)
            ->where(function ($query) use ($server) {
                $query->where('server_id', $server->id)->orWhere('is_global', true);
            })
            ->orderByDesc('server_id')
            ->orderByDesc('is_default')
            ->get();
    }

    public function disk(MysqlBackupStorageProvider $provider): Filesystem
    {
        $config = $provider->getConfig();

        if (in_array($provider->driver, $this->streamBasedDrivers(), true)) {
            throw new RuntimeException('The ' . $provider->driver . ' provider is stream-based and does not expose a Laravel disk.');
        }

        return match (true) {
            in_array($provider->driver, $this->s3CompatibleDrivers(), true) => Storage::build([
                'driver' => 's3',
                'key' => $config['key'] ?? null,
                'secret' => $config['secret'] ?? null,
                'region' => $config['region'] ?? 'auto',
                'bucket' => $config['bucket'] ?? null,
                'endpoint' => $config['endpoint'] ?? null,
                'use_path_style_endpoint' => (bool) ($config['path_style'] ?? true),
                'throw' => true,
            ]),
            in_array($provider->driver, ['ftp', 'ftps'], true) => Storage::build([
                'driver' => 'ftp',
                'host' => $config['host'] ?? '',
                'username' => $config['username'] ?? '',
                'password' => $config['password'] ?? '',
                'port' => (int) ($config['port'] ?? 21),
                'root' => $config['root'] ?? '/',
                'ssl' => $provider->driver === 'ftps' || (bool) ($config['ssl'] ?? false),
                'passive' => (bool) ($config['passive'] ?? true),
                'timeout' => (int) ($config['timeout'] ?? 30),
                'throw' => true,
            ]),
            $provider->driver === 'sftp' => Storage::build([
                'driver' => 'sftp',
                'host' => $config['host'] ?? '',
                'username' => $config['username'] ?? '',
                'password' => $config['password'] ?? null,
                'privateKey' => $config['private_key'] ?? null,
                'passphrase' => $config['passphrase'] ?? null,
                'port' => (int) ($config['port'] ?? 22),
                'root' => $config['root'] ?? '/',
                'timeout' => (int) ($config['timeout'] ?? 30),
                'throw' => true,
            ]),
            default => Storage::build([
                'driver' => 'local',
                'root' => $this->normalizeLocalRoot($config['root'] ?? '/var/lib/pterodactyl/backups/databases'),
                'throw' => true,
            ]),
        };
    }

    public function putStream(MysqlBackupStorageProvider $provider, string $path, mixed $stream): void
    {
        if ($provider->driver === 'google_drive') {
            app(GoogleDriveOAuthService::class)->upload($provider, $path, $stream);
            return;
        }

        if ($provider->driver === 'dropbox') {
            app(DropboxOAuthService::class)->upload($provider, $path, $stream);
            return;
        }

        if ($provider->driver === 'onedrive') {
            app(OneDriveOAuthService::class)->upload($provider, $path, $stream);
            return;
        }

        if ($provider->driver === 'webdav') {
            $this->putWebdavStream($provider, $path, $stream);
            return;
        }

        if (in_array($provider->driver, $this->rcloneDrivers(), true)) {
            $this->putRcloneStream($provider, $path, $stream);
            return;
        }

        if ($provider->driver === 'local') {
            $this->ensureLocalRoot($provider);
        }

        $this->disk($provider)->put($path, $stream);
    }

    public function readStream(MysqlBackupStorageProvider $provider, string $path): mixed
    {
        if ($provider->driver === 'google_drive') {
            return app(GoogleDriveOAuthService::class)->download($provider, $path);
        }

        if ($provider->driver === 'dropbox') {
            return app(DropboxOAuthService::class)->download($provider, $path);
        }

        if ($provider->driver === 'onedrive') {
            return app(OneDriveOAuthService::class)->download($provider, $path);
        }

        if ($provider->driver === 'webdav') {
            return $this->readWebdavStream($provider, $path);
        }

        if (in_array($provider->driver, $this->rcloneDrivers(), true)) {
            return $this->readRcloneStream($provider, $path);
        }

        return $this->disk($provider)->readStream($path);
    }

    public function delete(MysqlBackupStorageProvider $provider, string $path): void
    {
        if ($provider->driver === 'google_drive') {
            app(GoogleDriveOAuthService::class)->delete($provider, $path);
            return;
        }

        if ($provider->driver === 'dropbox') {
            app(DropboxOAuthService::class)->delete($provider, $path);
            return;
        }

        if ($provider->driver === 'onedrive') {
            app(OneDriveOAuthService::class)->delete($provider, $path);
            return;
        }

        if ($provider->driver === 'webdav') {
            $this->deleteWebdavFile($provider, $path);
            return;
        }

        if (in_array($provider->driver, $this->rcloneDrivers(), true)) {
            $this->deleteRcloneFile($provider, $path);
            return;
        }

        $this->disk($provider)->delete($path);
    }

    public function s3CompatibleDrivers(): array
    {
        return [
            's3',
            'aws_s3',
            'cloudflare_r2',
            'minio',
            'wasabi',
            'backblaze_b2',
            'digitalocean_spaces',
            'linode_object_storage',
            'vultr_object_storage',
            'scaleway_object_storage',
            'oracle_object_storage',
            'google_cloud_storage',
        ];
    }

    public function rcloneDrivers(): array
    {
        return [
            'box',
            'mega',
            'pcloud',
            'yandex_disk',
            'rclone',
        ];
    }

    /**
     * All drivers handled by custom stream methods (OAuth services, native
     * WebDAV, or the rclone binary) rather than a Laravel filesystem disk.
     */
    public function streamBasedDrivers(): array
    {
        return array_merge(
            ['google_drive', 'dropbox', 'onedrive', 'webdav'],
            $this->rcloneDrivers(),
        );
    }

    public function storageDriverLabels(): array
    {
        return [
            'google_drive' => 'Google Drive',
            'onedrive' => 'OneDrive',
            'dropbox' => 'Dropbox',
            'box' => 'Box',
            'mega' => 'MEGA',
            'pcloud' => 'pCloud',
            'yandex_disk' => 'Yandex Disk',
            'webdav' => 'WebDAV',
            'rclone' => 'Other rclone remote',
            's3' => 'S3 compatible',
            'aws_s3' => 'AWS S3',
            'cloudflare_r2' => 'Cloudflare R2',
            'minio' => 'MinIO',
            'wasabi' => 'Wasabi',
            'backblaze_b2' => 'Backblaze B2',
            'digitalocean_spaces' => 'DigitalOcean Spaces',
            'linode_object_storage' => 'Linode Object Storage',
            'vultr_object_storage' => 'Vultr Object Storage',
            'scaleway_object_storage' => 'Scaleway Object Storage',
            'oracle_object_storage' => 'Oracle Object Storage',
            'google_cloud_storage' => 'Google Cloud Storage',
            'ftp' => 'FTP',
            'ftps' => 'FTPS',
            'sftp' => 'SFTP',
        ];
    }

    public function pathFor(Server $server, string $databaseName, string $extension): string
    {
        $date = now()->format('Y/m/d');
        $safeDatabase = Str::slug($databaseName, '_') ?: 'database';

        return sprintf(
            'servers/%s/%s/%s_%s.%s',
            $server->uuid,
            $date,
            $safeDatabase,
            now()->format('His'),
            $extension
        );
    }

    public function normalizeLocalRoot(string $root): string
    {
        $root = trim($root);

        if ($root === '') {
            return base_path('database/backups/mysql');
        }

        if (str_starts_with($root, './var/www/')) {
            return '/' . substr($root, 2);
        }

        if (str_starts_with($root, 'var/www/')) {
            return '/' . $root;
        }

        if (str_starts_with($root, './')) {
            return base_path(substr($root, 2));
        }

        if (!str_starts_with($root, '/') && !preg_match('/^[A-Za-z]:[\/\\\\]/', $root)) {
            return base_path($root);
        }

        return $root;
    }

    public function ensureLocalRoot(MysqlBackupStorageProvider $provider): string
    {
        $config = $provider->getConfig();
        $root = $this->normalizeLocalRoot($config['root'] ?? '');

        if (!is_dir($root) && !@mkdir($root, 0750, true) && !is_dir($root)) {
            throw new \RuntimeException('Local backup root does not exist and could not be created: ' . $root);
        }

        if (!is_writable($root)) {
            throw new \RuntimeException(
                'Local backup root is not writable by the panel PHP user: ' . $root .
                '. Fix ownership/permissions, for example: chown -R www-data:www-data ' . $root . ' && chmod -R 750 ' . $root
            );
        }

        return $root;
    }

    private function putWebdavStream(MysqlBackupStorageProvider $provider, string $path, mixed $stream): void
    {
        $url = $this->webdavUrl($provider, $path);
        $auth = $this->webdavAuth($provider);

        // Ensure parent directories exist via MKCOL (best-effort, ignore errors).
        $this->webdavEnsureParents($provider, $path, $auth);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_UPLOAD         => true,
            CURLOPT_INFILE         => $stream,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/octet-stream'],
            CURLOPT_USERPWD        => $auth,
            CURLOPT_TIMEOUT        => 3600,
        ]);
        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status >= 400) {
            throw new RuntimeException('WebDAV upload failed (HTTP ' . $status . '): ' . mb_substr((string) $raw, 0, 1000));
        }
    }

    private function readWebdavStream(MysqlBackupStorageProvider $provider, string $path): mixed
    {
        $handle = tmpfile();

        if ($handle === false) {
            throw new RuntimeException('Unable to create a temporary file for WebDAV download.');
        }

        $url = $this->webdavUrl($provider, $path);
        $auth = $this->webdavAuth($provider);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FILE           => $handle,
            CURLOPT_USERPWD        => $auth,
            CURLOPT_TIMEOUT        => 3600,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $ok     = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$ok || $status >= 400) {
            fclose($handle);
            throw new RuntimeException('WebDAV download failed (HTTP ' . $status . ').');
        }

        rewind($handle);

        return $handle;
    }

    private function deleteWebdavFile(MysqlBackupStorageProvider $provider, string $path): void
    {
        $url = $this->webdavUrl($provider, $path);
        $auth = $this->webdavAuth($provider);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_USERPWD        => $auth,
            CURLOPT_TIMEOUT        => 60,
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 404 = already gone, that's fine
        if ($status !== 204 && $status !== 200 && $status !== 404) {
            throw new RuntimeException('WebDAV delete failed (HTTP ' . $status . ').');
        }
    }

    private function webdavUrl(MysqlBackupStorageProvider $provider, string $path): string
    {
        $config = $provider->getConfig();
        $base = rtrim((string) ($config['url'] ?? ''), '/');

        if ($base === '') {
            throw new RuntimeException('WebDAV provider is missing the base URL.');
        }

        $cleanPath = ltrim(str_replace('\\', '/', $path), '/');

        return $base . '/' . implode('/', array_map('rawurlencode', explode('/', $cleanPath)));
    }

    private function webdavAuth(MysqlBackupStorageProvider $provider): string
    {
        $config = $provider->getConfig();
        $user = (string) ($config['username'] ?? '');
        $pass = (string) ($config['password'] ?? '');

        return $user . ':' . $pass;
    }

    private function webdavEnsureParents(MysqlBackupStorageProvider $provider, string $path, string $auth): void
    {
        $config = $provider->getConfig();
        $base = rtrim((string) ($config['url'] ?? ''), '/');
        $parent = dirname($path);

        if ($parent === '.' || $parent === '/') {
            return;
        }

        $segments = array_filter(explode('/', str_replace('\\', '/', $parent)));
        $current = $base;

        foreach ($segments as $segment) {
            $current .= '/' . rawurlencode($segment);

            $ch = curl_init($current);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => 'MKCOL',
                CURLOPT_USERPWD        => $auth,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_NOBODY         => false,
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    }

    private function putRcloneStream(MysqlBackupStorageProvider $provider, string $path, mixed $stream): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ptero-rclone-upload-');
        $out = fopen($tmp, 'wb');

        try {
            stream_copy_to_stream($stream, $out);
            fclose($out);
            $this->runRclone($provider, ['copyto', $tmp, $this->rclonePath($provider, $path)]);
        } finally {
            if (is_resource($out)) {
                fclose($out);
            }
            if ($tmp && file_exists($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private function readRcloneStream(MysqlBackupStorageProvider $provider, string $path): mixed
    {
        $handle = tmpfile();

        if ($handle === false) {
            throw new RuntimeException('Unable to create a temporary file for rclone download.');
        }

        $metadata = stream_get_meta_data($handle);
        $this->runRclone($provider, ['copyto', $this->rclonePath($provider, $path), $metadata['uri']]);
        rewind($handle);

        return $handle;
    }

    private function deleteRcloneFile(MysqlBackupStorageProvider $provider, string $path): void
    {
        $this->runRclone($provider, ['deletefile', $this->rclonePath($provider, $path)]);
    }

    private function rclonePath(MysqlBackupStorageProvider $provider, string $path): string
    {
        $config = $provider->getConfig();
        $remote = trim((string) ($config['remote'] ?? ''));

        if ($remote === '') {
            throw new RuntimeException('Rclone provider is missing the remote path, for example gdrive:pterodactyl/mysql.');
        }

        return rtrim($remote, '/') . '/' . ltrim(str_replace('\\', '/', $path), '/');
    }

    private function runRclone(MysqlBackupStorageProvider $provider, array $arguments): void
    {
        $binary = env('MYSQL_BACKUP_RCLONE_PATH', 'rclone');
        $config = $provider->getConfig();
        $rcloneConfig = trim((string) ($config['rclone_config'] ?? ''));
        $configPath = null;
        $env = null;

        if ($rcloneConfig !== '') {
            $configPath = tempnam(sys_get_temp_dir(), 'ptero-rclone-config-');
            file_put_contents($configPath, $rcloneConfig);
            @chmod($configPath, 0600);
            $env = ['RCLONE_CONFIG' => $configPath];
        }

        try {
            $process = new Process(array_merge([$binary], $arguments), base_path(), $env, null, 3600);
            $process->run();
        } finally {
            if ($configPath && file_exists($configPath)) {
                @unlink($configPath);
            }
        }

        if (!$process->isSuccessful()) {
            throw new RuntimeException('rclone failed: ' . mb_substr($process->getErrorOutput() ?: $process->getOutput(), 0, 4000));
        }
    }
}