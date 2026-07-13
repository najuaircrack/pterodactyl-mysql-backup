<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier};

use RuntimeException;

/**
 * Native Dropbox OAuth2 + content API integration (curl only, no rclone / SDK).
 *
 * The OAuth *app* (client id + secret) is owned by the panel administrator and
 * read from the admin settings. Each user connects their own Dropbox account
 * once; only the resulting refresh/access tokens are stored on the provider.
 *
 * Provider config keys:
 *   dropbox_refresh_token – long-lived refresh token (token_access_type=offline)
 *   dropbox_access_token  – short-lived access token (cached, auto-refreshed)
 *   dropbox_token_expiry  – unix timestamp when the access token expires
 */
class DropboxOAuthService
{
    private const AUTH_URL    = 'https://www.dropbox.com/oauth2/authorize';
    private const TOKEN_URL   = 'https://api.dropboxapi.com/oauth2/token';
    private const UPLOAD_URL  = 'https://content.dropboxapi.com/2/files/upload';
    private const SESSION_START  = 'https://content.dropboxapi.com/2/files/upload_session/start';
    private const SESSION_APPEND = 'https://content.dropboxapi.com/2/files/upload_session/append_v2';
    private const SESSION_FINISH = 'https://content.dropboxapi.com/2/files/upload_session/finish';
    private const DOWNLOAD_URL = 'https://content.dropboxapi.com/2/files/download';
    private const DELETE_URL   = 'https://api.dropboxapi.com/2/files/delete_v2';

    // Dropbox rejects single-shot uploads above 150 MB; use a session past this.
    private const CHUNK_BYTES = 8 * 1024 * 1024;
    private const SINGLE_MAX_BYTES = 140 * 1024 * 1024;

    public function providerKey(): string
    {
        return 'dropbox';
    }

    // -------------------------------------------------------------------------
    // OAuth flow
    // -------------------------------------------------------------------------

    public function buildAuthUrl(string $clientId, string $redirectUri, string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id'         => $clientId,
            'redirect_uri'      => $redirectUri,
            'response_type'     => 'code',
            'token_access_type' => 'offline',
            'scope'             => 'files.content.write files.content.read files.metadata.write',
            'state'             => $state,
        ]);
    }

    public function exchangeCode(string $clientId, string $clientSecret, string $code, string $redirectUri): array
    {
        $response = $this->postForm(self::TOKEN_URL, [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
        ]);

        if (empty($response['refresh_token'])) {
            throw new RuntimeException('Dropbox did not return a refresh token. Ensure token_access_type=offline and the app has the required scopes.');
        }

        return $response;
    }

    // -------------------------------------------------------------------------
    // Token management
    // -------------------------------------------------------------------------

    public function accessToken(MysqlBackupStorageProvider $provider): string
    {
        $config  = $provider->getConfig();
        $expiry  = (int) ($config['dropbox_token_expiry'] ?? 0);
        $current = (string) ($config['dropbox_access_token'] ?? '');

        if ($current !== '' && $expiry > time() + 60) {
            return $current;
        }

        return $this->refreshAccessToken($provider);
    }

    public function refreshAccessToken(MysqlBackupStorageProvider $provider): string
    {
        $config = $provider->getConfig();
        $app = $this->adminApp();

        $response = $this->postForm(self::TOKEN_URL, [
            'client_id'     => $app['client_id'],
            'client_secret' => $app['client_secret'],
            'refresh_token' => $config['dropbox_refresh_token'] ?? '',
            'grant_type'    => 'refresh_token',
        ]);

        if (empty($response['access_token'])) {
            throw new RuntimeException('Failed to refresh the Dropbox access token: ' . json_encode($response));
        }

        $config['dropbox_access_token'] = $response['access_token'];
        $config['dropbox_token_expiry'] = time() + (int) ($response['expires_in'] ?? 14400);
        $provider->setConfig($config);
        $provider->save();

        return $response['access_token'];
    }

    // -------------------------------------------------------------------------
    // File operations
    // -------------------------------------------------------------------------

    public function upload(MysqlBackupStorageProvider $provider, string $path, mixed $stream): void
    {
        $dropboxPath = $this->dropboxPath($path);
        $size = $this->streamSize($stream);

        if ($size !== null && $size <= self::SINGLE_MAX_BYTES) {
            $body = stream_get_contents($stream);

            if ($body === false) {
                throw new RuntimeException('Could not read backup stream for the Dropbox upload.');
            }

            $this->singleUpload($provider, $dropboxPath, $body);

            return;
        }

        $this->sessionUpload($provider, $dropboxPath, $stream);
    }

    public function download(MysqlBackupStorageProvider $provider, string $path): mixed
    {
        $tmp = tmpfile();

        if ($tmp === false) {
            throw new RuntimeException('Cannot create a temporary file for the Dropbox download.');
        }

        $arg = json_encode(['path' => $this->dropboxPath($path)]);
        [$status, ] = $this->contentRequest($provider, self::DOWNLOAD_URL, $arg, '', $tmp);

        if ($status >= 400) {
            fclose($tmp);
            throw new RuntimeException('Dropbox download failed (HTTP ' . $status . ').');
        }

        rewind($tmp);

        return $tmp;
    }

    public function delete(MysqlBackupStorageProvider $provider, string $path): void
    {
        $token = $this->accessToken($provider);
        $this->postJson(self::DELETE_URL, $token, ['path' => $this->dropboxPath($path)]);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function singleUpload(MysqlBackupStorageProvider $provider, string $dropboxPath, string $body): void
    {
        $arg = json_encode(['path' => $dropboxPath, 'mode' => 'overwrite', 'mute' => true]);
        [$status, $raw] = $this->contentRequest($provider, self::UPLOAD_URL, $arg, $body);

        if ($status >= 400) {
            throw new RuntimeException('Dropbox upload failed (HTTP ' . $status . '): ' . mb_substr((string) $raw, 0, 1000));
        }
    }

    private function sessionUpload(MysqlBackupStorageProvider $provider, string $dropboxPath, mixed $stream): void
    {
        $first = $this->readChunk($stream);
        [$status, $raw] = $this->contentRequest($provider, self::SESSION_START, json_encode(['close' => false]), $first);

        if ($status >= 400) {
            throw new RuntimeException('Dropbox upload could not start (HTTP ' . $status . '): ' . mb_substr((string) $raw, 0, 1000));
        }

        $session = json_decode((string) $raw, true);
        $sessionId = $session['session_id'] ?? '';

        if ($sessionId === '') {
            throw new RuntimeException('Dropbox did not return an upload session id.');
        }

        $offset = strlen($first);

        while (!feof($stream)) {
            $chunk = $this->readChunk($stream);

            if ($chunk === '') {
                break;
            }

            $cursor = ['session_id' => $sessionId, 'offset' => $offset];
            [$status, $raw] = $this->contentRequest(
                $provider,
                self::SESSION_APPEND,
                json_encode(['cursor' => $cursor, 'close' => false]),
                $chunk
            );

            if ($status >= 400) {
                throw new RuntimeException('Dropbox chunk upload failed (HTTP ' . $status . '): ' . mb_substr((string) $raw, 0, 1000));
            }

            $offset += strlen($chunk);
        }

        $commit = ['path' => $dropboxPath, 'mode' => 'overwrite', 'mute' => true];
        $cursor = ['session_id' => $sessionId, 'offset' => $offset];
        [$status, $raw] = $this->contentRequest(
            $provider,
            self::SESSION_FINISH,
            json_encode(['cursor' => $cursor, 'commit' => $commit]),
            ''
        );

        if ($status >= 400) {
            throw new RuntimeException('Dropbox upload could not be finalised (HTTP ' . $status . '): ' . mb_substr((string) $raw, 0, 1000));
        }
    }

    /**
     * Perform a Dropbox content-API request, refreshing the token once on 401.
     * When $sink is a stream resource the response body is written to it.
     *
     * @return array{0:int,1:?string}
     */
    private function contentRequest(MysqlBackupStorageProvider $provider, string $url, string $apiArg, string $body, mixed $sink = null): array
    {
        $attempt = function (string $token) use ($url, $apiArg, $body, $sink): array {
            $ch = curl_init($url);
            $options = [
                CURLOPT_POST       => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $token,
                    'Dropbox-API-Arg: ' . $apiArg,
                    'Content-Type: application/octet-stream',
                ],
                CURLOPT_TIMEOUT    => 3600,
            ];

            if (is_resource($sink)) {
                $options[CURLOPT_FILE] = $sink;
                $options[CURLOPT_RETURNTRANSFER] = false;
            } else {
                $options[CURLOPT_RETURNTRANSFER] = true;
            }

            curl_setopt_array($ch, $options);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return [$status, is_string($raw) ? $raw : null];
        };

        [$status, $raw] = $attempt($this->accessToken($provider));

        if ($status === 401) {
            if (is_resource($sink)) {
                ftruncate($sink, 0);
                rewind($sink);
            }
            [$status, $raw] = $attempt($this->refreshAccessToken($provider));
        }

        return [$status, $raw];
    }

    private function dropboxPath(string $path): string
    {
        return '/' . ltrim(str_replace('\\', '/', $path), '/');
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
            throw new RuntimeException('Dropbox is not configured. Ask an administrator to add the Dropbox app in the admin settings.');
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

    private function postJson(string $url, string $token, array $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 60,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);

        return json_decode((string) $raw, true) ?? [];
    }
}
