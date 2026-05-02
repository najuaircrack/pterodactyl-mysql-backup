<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier};

use RuntimeException;

/**
 * Handles all Google Drive OAuth and REST API operations.
 *
 * Config keys stored on the provider:
 *   gdrive_client_id       – OAuth client ID from Google Cloud Console
 *   gdrive_client_secret   – OAuth client secret
 *   gdrive_refresh_token   – long-lived refresh token (stored after callback)
 *   gdrive_access_token    – short-lived access token (cached, auto-refreshed)
 *   gdrive_token_expiry    – unix timestamp when access token expires
 *   gdrive_folder_id       – Drive folder ID used as the root for all backups
 *                            (auto-created as "pterodactyl-mysql-backups" if absent)
 */
class GoogleDriveOAuthService
{
    private const TOKEN_URL   = 'https://oauth2.googleapis.com/token';
    private const UPLOAD_URL  = 'https://www.googleapis.com/upload/drive/v3/files';
    private const FILES_URL   = 'https://www.googleapis.com/drive/v3/files';
    private const SCOPES      = 'https://www.googleapis.com/auth/drive.file';
    private const FOLDER_NAME = 'pterodactyl-mysql-backups';

    // -------------------------------------------------------------------------
    // OAuth flow
    // -------------------------------------------------------------------------

    /**
     * Build the Google OAuth consent URL the user should be redirected to.
     */
    public function buildAuthUrl(string $clientId, string $redirectUri, string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'             => $clientId,
            'redirect_uri'          => $redirectUri,
            'response_type'         => 'code',
            'scope'                 => self::SCOPES,
            'access_type'           => 'offline',
            'prompt'                => 'consent',
            'state'                 => $state,
        ]);
    }

    /**
     * Exchange an authorization code for access + refresh tokens.
     * Returns ['access_token', 'refresh_token', 'expires_in'].
     */
    public function exchangeCode(string $clientId, string $clientSecret, string $code, string $redirectUri): array
    {
        $response = $this->post(self::TOKEN_URL, [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
        ]);

        if (empty($response['refresh_token'])) {
            throw new RuntimeException('Google did not return a refresh token. Make sure prompt=consent and access_type=offline were set.');
        }

        return $response;
    }

    // -------------------------------------------------------------------------
    // Token management
    // -------------------------------------------------------------------------

    /**
     * Return a valid access token for the given provider, refreshing if needed.
     * Persists updated tokens back to the provider record.
     */
    public function accessToken(MysqlBackupStorageProvider $provider): string
    {
        $config  = $provider->getConfig();
        $expiry  = (int) ($config['gdrive_token_expiry'] ?? 0);
        $current = (string) ($config['gdrive_access_token'] ?? '');

        if ($current !== '' && $expiry > time() + 60) {
            return $current;
        }

        return $this->refreshAccessToken($provider);
    }

    /**
     * Use the refresh token to obtain a new access token and persist it.
     */
    public function refreshAccessToken(MysqlBackupStorageProvider $provider): string
    {
        $config = $provider->getConfig();

        $response = $this->post(self::TOKEN_URL, [
            'client_id'     => $config['gdrive_client_id']     ?? '',
            'client_secret' => $config['gdrive_client_secret'] ?? '',
            'refresh_token' => $config['gdrive_refresh_token'] ?? '',
            'grant_type'    => 'refresh_token',
        ]);

        if (empty($response['access_token'])) {
            throw new RuntimeException('Failed to refresh Google Drive access token: ' . json_encode($response));
        }

        $config['gdrive_access_token'] = $response['access_token'];
        $config['gdrive_token_expiry']  = time() + (int) ($response['expires_in'] ?? 3600);
        $provider->setConfig($config);
        $provider->save();

        return $response['access_token'];
    }

    // -------------------------------------------------------------------------
    // Folder management
    // -------------------------------------------------------------------------

    /**
     * Return the Drive folder ID used as root, creating it if needed.
     */
    public function folderId(MysqlBackupStorageProvider $provider): string
    {
        $config   = $provider->getConfig();
        $existing = trim((string) ($config['gdrive_folder_id'] ?? ''));

        if ($existing !== '') {
            return $existing;
        }

        $token = $this->accessToken($provider);

        // Check if folder already exists
        $query = sprintf(
            "mimeType='application/vnd.google-apps.folder' and name='%s' and trashed=false",
            addslashes(self::FOLDER_NAME)
        );
        $list = $this->driveGet($token, self::FILES_URL . '?' . http_build_query([
            'q'      => $query,
            'fields' => 'files(id,name)',
        ]));

        if (!empty($list['files'][0]['id'])) {
            $folderId = $list['files'][0]['id'];
        } else {
            // Create it
            $folder = $this->driveRequest('POST', $token, self::FILES_URL, [
                'name'     => self::FOLDER_NAME,
                'mimeType' => 'application/vnd.google-apps.folder',
            ]);
            $folderId = $folder['id'] ?? '';
        }

        if ($folderId === '') {
            throw new RuntimeException('Could not create or find the backup folder in Google Drive.');
        }

        $config['gdrive_folder_id'] = $folderId;
        $provider->setConfig($config);
        $provider->save();

        return $folderId;
    }

    /**
     * Ensure a subfolder path (e.g. "servers/uuid/2026/05/03") exists under the
     * root backup folder. Returns the leaf folder ID.
     */
    public function ensurePath(MysqlBackupStorageProvider $provider, string $path): string
    {
        $token    = $this->accessToken($provider);
        $parentId = $this->folderId($provider);
        $parts    = array_filter(explode('/', $path));

        foreach ($parts as $part) {
            $parentId = $this->ensureSubfolder($token, $parentId, $part);
        }

        return $parentId;
    }

    // -------------------------------------------------------------------------
    // File operations
    // -------------------------------------------------------------------------

    /**
     * Upload a stream to Drive at the given logical path.
     * Returns the Drive file ID.
     */
    public function upload(MysqlBackupStorageProvider $provider, string $path, mixed $stream): string
    {
        $token     = $this->accessToken($provider);
        $filename  = basename($path);
        $parentDir = dirname($path);
        $parentId  = $parentDir !== '.' ? $this->ensurePath($provider, $parentDir) : $this->folderId($provider);

        // Read stream into memory for multipart upload
        // For very large files a resumable upload would be better, but for
        // typical DB dumps (under a few hundred MB) this is fine.
        $body = stream_get_contents($stream);
        if ($body === false) {
            throw new RuntimeException('Could not read backup stream for Google Drive upload.');
        }

        $metadata = json_encode(['name' => $filename, 'parents' => [$parentId]]);
        $boundary = 'gdrive_upload_' . bin2hex(random_bytes(8));

        $multipart = "--{$boundary}\r\n"
            . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
            . $metadata . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n"
            . $body . "\r\n"
            . "--{$boundary}--";

        $ch = curl_init(self::UPLOAD_URL . '?uploadType=multipart&fields=id');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $multipart,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: multipart/related; boundary=' . $boundary,
                'Content-Length: ' . strlen($multipart),
            ],
            CURLOPT_TIMEOUT        => 3600,
        ]);
        $raw    = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode((string) $raw, true) ?? [];

        if ($status === 401) {
            // Token expired mid-upload — refresh and retry once
            $token = $this->refreshAccessToken($provider);
            $ch    = curl_init(self::UPLOAD_URL . '?uploadType=multipart&fields=id');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $multipart,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: multipart/related; boundary=' . $boundary,
                    'Content-Length: ' . strlen($multipart),
                ],
                CURLOPT_TIMEOUT        => 3600,
            ]);
            $raw    = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $data = json_decode((string) $raw, true) ?? [];
        }

        if (empty($data['id'])) {
            throw new RuntimeException(
                'Google Drive upload failed (HTTP ' . $status . '): ' . mb_substr((string) $raw, 0, 1000)
            );
        }

        // Store file_id → path mapping so we can find files later
        $this->storeFileMapping($provider, $path, $data['id']);

        return $data['id'];
    }

    /**
     * Return a readable stream for a file at the given logical path.
     */
    public function download(MysqlBackupStorageProvider $provider, string $path): mixed
    {
        $token  = $this->accessToken($provider);
        $fileId = $this->resolveFileId($provider, $path);

        $tmpFile = tmpfile();
        if ($tmpFile === false) {
            throw new RuntimeException('Cannot create temp file for Drive download.');
        }

        $ch = curl_init(self::FILES_URL . '/' . urlencode($fileId) . '?alt=media');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FILE           => $tmpFile,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
            CURLOPT_TIMEOUT        => 3600,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $ok     = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$ok || $status >= 400) {
            throw new RuntimeException('Google Drive download failed (HTTP ' . $status . ').');
        }

        rewind($tmpFile);
        return $tmpFile;
    }

    /**
     * Delete a file at the given logical path from Drive.
     */
    public function delete(MysqlBackupStorageProvider $provider, string $path): void
    {
        $token  = $this->accessToken($provider);
        $fileId = $this->resolveFileId($provider, $path, false);

        if ($fileId === null) {
            return; // Already gone
        }

        $ch = curl_init(self::FILES_URL . '/' . urlencode($fileId));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
            CURLOPT_TIMEOUT        => 60,
        ]);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_exec($ch);
        curl_close($ch);

        // Remove from local mapping
        $this->removeFileMapping($provider, $path);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * File ID mapping: we store path → Drive file ID in the provider config so
     * we can look up files without searching Drive on every operation.
     * Key: gdrive_files (array)
     */
    private function storeFileMapping(MysqlBackupStorageProvider $provider, string $path, string $fileId): void
    {
        $config = $provider->getConfig();
        $files  = (array) ($config['gdrive_files'] ?? []);
        $files[$path] = $fileId;
        $config['gdrive_files'] = $files;
        $provider->setConfig($config);
        $provider->save();
    }

    private function removeFileMapping(MysqlBackupStorageProvider $provider, string $path): void
    {
        $config = $provider->getConfig();
        $files  = (array) ($config['gdrive_files'] ?? []);
        unset($files[$path]);
        $config['gdrive_files'] = $files;
        $provider->setConfig($config);
        $provider->save();
    }

    private function resolveFileId(MysqlBackupStorageProvider $provider, string $path, bool $required = true): ?string
    {
        $config = $provider->getConfig();
        $files  = (array) ($config['gdrive_files'] ?? []);

        if (!empty($files[$path])) {
            return $files[$path];
        }

        // Fallback: search Drive by name under the correct parent
        $token    = $this->accessToken($provider);
        $filename = basename($path);
        $parentDir = dirname($path);
        $parentId = $parentDir !== '.' ? $this->ensurePath($provider, $parentDir) : $this->folderId($provider);

        $query = sprintf(
            "name='%s' and '%s' in parents and trashed=false",
            addslashes($filename),
            $parentId
        );
        $list = $this->driveGet($token, self::FILES_URL . '?' . http_build_query([
            'q'      => $query,
            'fields' => 'files(id)',
        ]));

        if (!empty($list['files'][0]['id'])) {
            return $list['files'][0]['id'];
        }

        if ($required) {
            throw new RuntimeException('Google Drive file not found: ' . $path);
        }

        return null;
    }

    private function ensureSubfolder(string $token, string $parentId, string $name): string
    {
        $query = sprintf(
            "mimeType='application/vnd.google-apps.folder' and name='%s' and '%s' in parents and trashed=false",
            addslashes($name),
            $parentId
        );
        $list = $this->driveGet($token, self::FILES_URL . '?' . http_build_query([
            'q'      => $query,
            'fields' => 'files(id)',
        ]));

        if (!empty($list['files'][0]['id'])) {
            return $list['files'][0]['id'];
        }

        $folder = $this->driveRequest('POST', $token, self::FILES_URL, [
            'name'     => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents'  => [$parentId],
        ]);

        if (empty($folder['id'])) {
            throw new RuntimeException('Could not create Drive subfolder: ' . $name);
        }

        return $folder['id'];
    }

    private function driveGet(string $token, string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
            CURLOPT_TIMEOUT        => 30,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        return json_decode((string) $raw, true) ?? [];
    }

    private function driveRequest(string $method, string $token, string $url, array $body): array
    {
        $json = json_encode($body);
        $ch   = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Content-Length: ' . strlen($json),
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        return json_decode((string) $raw, true) ?? [];
    }

    private function post(string $url, array $params): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 30,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        return json_decode((string) $raw, true) ?? [];
    }
}