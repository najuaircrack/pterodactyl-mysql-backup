<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier};

use RuntimeException;

/**
 * Native OneDrive / Microsoft Graph OAuth2 integration (curl only, no SDK).
 *
 * The OAuth *app* (client id + secret + tenant) is owned by the panel
 * administrator and read from the admin settings. Each user connects their
 * own OneDrive account once; only the resulting refresh/access tokens are
 * stored on the provider record.
 *
 * Microsoft Graph is path-addressed, so — unlike Google Drive — we do not
 * need to track file IDs or folder IDs. Every operation uses the logical
 * path under /me/drive/root:/.
 *
 * Provider config keys:
 *   onedrive_refresh_token – long-lived refresh token
 *   onedrive_access_token  – short-lived access token (cached, auto-refreshed)
 *   onedrive_token_expiry  – unix timestamp when the access token expires
 */
class OneDriveOAuthService
{
    private const GRAPH_ROOT    = 'https://graph.microsoft.com/v1.0/me/drive/root:';
    private const GRAPH_ITEMS   = 'https://graph.microsoft.com/v1.0/me/drive/items/';
    private const SCOPES        = 'Files.ReadWrite offline_access';

    // Microsoft requires an upload session for files above 4 MB.
    private const SESSION_THRESHOLD_BYTES = 4 * 1024 * 1024;
    private const CHUNK_BYTES = 5 * 1024 * 1024;

    public function providerKey(): string
    {
        return 'onedrive';
    }

    // -------------------------------------------------------------------------
    // OAuth flow
    // -------------------------------------------------------------------------

    public function buildAuthUrl(string $clientId, string $redirectUri, string $state, string $tenant = 'common'): string
    {
        $base = rtrim(sprintf('https://login.microsoftonline.com/%s/oauth2/v2.0/authorize', $tenant), '/');

        return $base . '?' . http_build_query([
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => self::SCOPES,
            'state'         => $state,
            'prompt'        => 'select_account',
        ]);
    }

    public function exchangeCode(string $clientId, string $clientSecret, string $code, string $redirectUri, string $tenant = 'common'): array
    {
        $tokenUrl = rtrim(sprintf('https://login.microsoftonline.com/%s/oauth2/v2.0/token', $tenant), '/');

        $response = $this->postForm($tokenUrl, [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
            'scope'         => self::SCOPES,
        ]);

        if (empty($response['access_token'])) {
            throw new RuntimeException('OneDrive did not return an access token: ' . json_encode($response));
        }

        if (empty($response['refresh_token'])) {
            throw new RuntimeException('OneDrive did not return a refresh token. Re-consent and ensure offline_access scope is requested.');
        }

        return $response;
    }

    // -------------------------------------------------------------------------
    // Token management
    // -------------------------------------------------------------------------

    public function accessToken(MysqlBackupStorageProvider $provider): string
    {
        $config  = $provider->getConfig();
        $expiry  = (int) ($config['onedrive_token_expiry'] ?? 0);
        $current = (string) ($config['onedrive_access_token'] ?? '');

        if ($current !== '' && $expiry > time() + 60) {
            return $current;
        }

        return $this->refreshAccessToken($provider);
    }

    public function refreshAccessToken(MysqlBackupStorageProvider $provider): string
    {
        $config = $provider->getConfig();
        $app = $this->adminApp();
        $tokenUrl = rtrim(sprintf('https://login.microsoftonline.com/%s/oauth2/v2.0/token', $app['tenant']), '/');

        $response = $this->postForm($tokenUrl, [
            'client_id'     => $app['client_id'],
            'client_secret' => $app['client_secret'],
            'refresh_token' => $config['onedrive_refresh_token'] ?? '',
            'grant_type'    => 'refresh_token',
            'scope'         => self::SCOPES,
        ]);

        if (empty($response['access_token'])) {
            throw new RuntimeException('Failed to refresh the OneDrive access token: ' . json_encode($response));
        }

        $config['onedrive_access_token'] = $response['access_token'];
        $config['onedrive_token_expiry'] = time() + (int) ($response['expires_in'] ?? 3600);

        if (!empty($response['refresh_token'])) {
            $config['onedrive_refresh_token'] = $response['refresh_token'];
        }

        $provider->setConfig($config);
        $provider->save();

        return $response['access_token'];
    }

    // -------------------------------------------------------------------------
    // File operations
    // -------------------------------------------------------------------------

    public function upload(MysqlBackupStorageProvider $provider, string $path, mixed $stream): void
    {
        $graphPath = $this->graphPath($path);
        $size = $this->streamSize($stream);

        if ($size !== null && $size <= self::SESSION_THRESHOLD_BYTES) {
            $body = stream_get_contents($stream);

            if ($body === false) {
                throw new RuntimeException('Could not read backup stream for the OneDrive upload.');
            }

            $this->simpleUpload($provider, $graphPath, $body);

            return;
        }

        // For unknown-size or large streams, use a resumable upload session.
        $this->sessionUpload($provider, $graphPath, $stream);
    }

    public function download(MysqlBackupStorageProvider $provider, string $path): mixed
    {
        $tmp = tmpfile();

        if ($tmp === false) {
            throw new RuntimeException('Cannot create a temporary file for the OneDrive download.');
        }

        $token = $this->accessToken($provider);
        $url = self::GRAPH_ROOT . $this->graphPath($path) . ':/content';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FILE           => $tmp,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
            CURLOPT_TIMEOUT        => 3600,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $ok     = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status === 401) {
            $token = $this->refreshAccessToken($provider);
            ftruncate($tmp, 0);
            rewind($tmp);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FILE           => $tmp,
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
                CURLOPT_TIMEOUT        => 3600,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        }

        if (!$ok || $status >= 400) {
            fclose($tmp);
            throw new RuntimeException('OneDrive download failed (HTTP ' . $status . ').');
        }

        rewind($tmp);

        return $tmp;
    }

    public function delete(MysqlBackupStorageProvider $provider, string $path): void
    {
        $token = $this->accessToken($provider);
        $url = self::GRAPH_ROOT . $this->graphPath($path);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
            CURLOPT_TIMEOUT        => 60,
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status === 401) {
            $token = $this->refreshAccessToken($provider);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => 'DELETE',
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
                CURLOPT_TIMEOUT        => 60,
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function simpleUpload(MysqlBackupStorageProvider $provider, string $graphPath, string $body): void
    {
        $token = $this->accessToken($provider);
        $url = self::GRAPH_ROOT . $graphPath . ':/content';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/octet-stream',
            ],
            CURLOPT_TIMEOUT        => 3600,
        ]);
        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status === 401) {
            $token = $this->refreshAccessToken($provider);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => 'PUT',
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: application/octet-stream',
                ],
                CURLOPT_TIMEOUT        => 3600,
            ]);
            $raw    = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        }

        if ($status >= 400) {
            throw new RuntimeException('OneDrive upload failed (HTTP ' . $status . '): ' . mb_substr((string) $raw, 0, 1000));
        }
    }

    private function sessionUpload(MysqlBackupStorageProvider $provider, string $graphPath, mixed $stream): void
    {
        $token = $this->accessToken($provider);
        $url = self::GRAPH_ROOT . $graphPath . ':/createUploadSession';

        // Create the upload session
        $json = json_encode(['item' => ['@microsoft.graph.conflictBehavior' => 'replace']]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 60,
        ]);
        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status === 401) {
            $token = $this->refreshAccessToken($provider);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $json,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: application/json',
                ],
                CURLOPT_TIMEOUT        => 60,
            ]);
            $raw    = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        }

        if ($status >= 400) {
            throw new RuntimeException('OneDrive upload session could not be created (HTTP ' . $status . '): ' . mb_substr((string) $raw, 0, 1000));
        }

        $session = json_decode((string) $raw, true);
        $uploadUrl = $session['uploadUrl'] ?? '';

        if ($uploadUrl === '') {
            throw new RuntimeException('OneDrive did not return an upload URL for the upload session.');
        }

        $offset = 0;
        $totalSize = $this->streamSize($stream);

        while (!feof($stream)) {
            $chunk = $this->readChunk($stream);

            if ($chunk === '') {
                break;
            }

            $chunkLen = strlen($chunk);
            $end = $offset + $chunkLen - 1;
            $contentRange = $totalSize !== null
                ? sprintf('bytes %d-%d/%d', $offset, $end, $totalSize)
                : sprintf('bytes %d-%d', $offset, $end);

            $ch = curl_init($uploadUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => 'PUT',
                CURLOPT_POSTFIELDS     => $chunk,
                CURLOPT_HTTPHEADER     => [
                    'Content-Length: ' . $chunkLen,
                    'Content-Range: ' . $contentRange,
                ],
                CURLOPT_TIMEOUT        => 3600,
            ]);
            $raw    = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // 202 = partial, 200/201 = complete
            if ($status !== 200 && $status !== 201 && $status !== 202) {
                throw new RuntimeException('OneDrive chunk upload failed (HTTP ' . $status . '): ' . mb_substr((string) $raw, 0, 1000));
            }

            $offset += $chunkLen;
        }
    }

    private function graphPath(string $path): string
    {
        $clean = ltrim(str_replace('\\', '/', $path), '/');
        $segments = explode('/', $clean);

        // Encode each segment individually; preserve the literal / separators
        // that Graph uses between path components.
        return '/' . implode('/', array_map('rawurlencode', $segments));
    }

    private function readChunk(mixed $stream): string
    {
        $buffer = '';

        while (strlen($buffer) < self::CHUNK_BYTES && !feof($stream)) {
            $part = fread($stream, self::CHUNK_BYTES - strlen($buffer));

            if ($part === false || $part === '') {
                break;
            }

            $buffer .= $part;
        }

        return $buffer;
    }

    private function streamSize(mixed $stream): ?int
    {
        $stat = @fstat($stream);

        return isset($stat['size']) && $stat['size'] > 0 ? (int) $stat['size'] : null;
    }

    private function adminApp(): array
    {
        $app = app(MysqlBackupAdminSettingsService::class)->oauthApp($this->providerKey());

        if (!$app) {
            throw new RuntimeException('OneDrive is not configured. Ask an administrator to add the Microsoft app in the admin settings.');
        }

        return $app;
    }

    private function postForm(string $url, array $params): array
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
