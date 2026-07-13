<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier};

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Models\Permission;
use Pterodactyl\Models\Server;

class DataBaseBackupController extends ClientApiController
{
    public function databases(Request $request, Server $server): JsonResponse
    {
        $this->authorizeServer($request, $server, 'ACTION_DATABASE_READ', 'database.read');

        return response()->json($server->databases()->with('host')->get()->map(fn ($database) => [
            'id' => $database->id,
            'name' => $database->database,
            'username' => $database->username,
            'host' => $database->host?->host,
            'port' => $database->host?->port,
        ]));
    }

    public function index(
        Request $request,
        Server $server,
        MysqlBackupStorageManager $storage,
        MysqlBackupAdminSettingsService $adminSettings,
        MysqlBackupLegacyImportService $legacyImport,
    ): JsonResponse {
        $this->authorizeServer($request, $server, 'ACTION_DATABASE_READ', 'database.read');

        app(MysqlBackupSchedulerService::class)->reconcile();
        $legacyImport->importForServer($server, $storage);

        $perPage = min(100, max(10, (int) $request->query('per_page', 25)));
        $paginator = MysqlBackupRecord::query()
            ->where('server_id', $server->id)
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (MysqlBackupRecord $record) => $this->recordResponse($record, $server))->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function config(
        Request $request,
        Server $server,
        MysqlBackupStorageManager $storage,
        MysqlBackupAdminSettingsService $adminSettings,
    ): JsonResponse {
        $this->authorizeServer($request, $server, 'ACTION_DATABASE_READ', 'database.read');

        $configuration = MysqlBackupConfiguration::query()->firstOrCreate(
            ['server_id' => $server->id],
            $adminSettings->defaultConfigurationAttributes($server)
        );
        $storage->providerFor($server, $configuration);

        return response()->json([
            'configuration' => $this->configurationResponse($configuration),
            'storage_providers' => $this->storageProviders($server)->map(fn (MysqlBackupStorageProvider $provider) => $this->providerResponse($provider))->values(),
            'limits' => $adminSettings->limitsResponse($server),
            'quota' => app(MysqlBackupQuotaService::class)->response($server, $request->user(), $adminSettings),
            'capabilities' => [
                'can_configure' => $this->canServer($request, $server, 'ACTION_DATABASE_UPDATE', 'database.update'),
                'can_restore' => $this->canServer($request, $server, 'ACTION_DATABASE_UPDATE', 'database.update'),
                'is_admin' => (bool) $request->user()->root_admin,
            ],
        ]);
    }

    public function updateConfig(
        Request $request,
        Server $server,
        MysqlBackupSchedulerService $scheduler,
        MysqlBackupAdminSettingsService $adminSettings,
    ): JsonResponse {
        $this->authorizeServer($request, $server, 'ACTION_DATABASE_UPDATE', 'database.update');

        $databaseIds = $server->databases()->pluck('id')->all();
        $providerIds = $this->storageProviders($server)->pluck('id')->all();
        $limits = $adminSettings->limitsResponse($server);
        $validator = Validator::make($request->all(), [
            'enabled' => ['required', 'boolean'],
            'database_ids' => ['nullable', 'array'],
            'database_ids.*' => ['integer', Rule::in($databaseIds)],
            'frequency_type' => ['nullable', Rule::in(['interval'])],
            'interval_minutes' => ['nullable', 'integer', 'min:' . $limits['min_interval_minutes'], 'max:' . $limits['max_interval_minutes']],
            'retention_count' => ['required', 'integer', 'min:1', 'max:' . $limits['max_retention_count']],
            'retention_days' => ['nullable', 'integer', 'min:1', 'max:' . $limits['max_retention_days']],
            'storage_provider_id' => ['nullable', 'integer', Rule::in($providerIds)],
            'compress' => ['boolean'],
            'encrypt' => ['boolean'],
            'notifications' => ['nullable', 'array'],
            'notifications.webhook_url' => ['nullable', 'url', 'max:2048'],
            'notifications.success' => ['boolean'],
            'notifications.failure' => ['boolean'],
            'notifications.discord_embed' => ['boolean'],
            'notifications.attach_backup' => ['boolean'],
        ]);
        $data = $validator->validate();

        $webhookUrl = $data['notifications']['webhook_url'] ?? null;
        if ($webhookUrl && !$this->webhookUrlIsAllowed($webhookUrl)) {
            return response()->json([
                'error' => 'Webhook URL must resolve to a public address. Set MYSQL_BACKUP_ALLOW_PRIVATE_WEBHOOKS=true only if you intentionally need internal webhooks.',
            ], 422);
        }

        $configuration = MysqlBackupConfiguration::query()->firstOrCreate(['server_id' => $server->id]);
        $configuration = $scheduler->saveAndSchedule($configuration, [
            'enabled' => $data['enabled'],
            'database_ids' => $data['database_ids'] ?? null,
            'frequency_type' => 'interval',
            'interval_minutes' => $data['interval_minutes'] ?? 360,
            'retention_count' => $data['retention_count'],
            'retention_days' => $data['retention_days'] ?? null,
            'storage_provider_id' => $data['storage_provider_id'] ?? null,
            'compress' => true,
            'encrypt' => $limits['force_encrypt'] || ($data['encrypt'] ?? false),
            'notifications' => $data['notifications'] ?? null,
        ]);

        return response()->json(['configuration' => $this->configurationResponse($configuration)]);
    }

    public function storeProvider(Request $request, Server $server): JsonResponse
    {
        $this->authorizeServer($request, $server, 'ACTION_DATABASE_UPDATE', 'database.update');
        $adminSettings = app(MysqlBackupAdminSettingsService::class);
        $limits = $adminSettings->limitsResponse($server);

        if (!$limits['allow_server_providers']) {
            return response()->json(['error' => 'Server-level storage providers are disabled by the administrator.'], 403);
        }

        if ($request->input('driver') === 'local') {
            return response()->json(['error' => 'Local storage is the built-in default and cannot be added per server.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'driver' => ['required', Rule::in(array_values(array_diff($limits['allowed_drivers'], ['local'])))],
            'is_global' => ['boolean'],
            'is_default' => ['boolean'],
            'config' => ['required', 'array'],
        ]);
        $data = $validator->validate();

        $isGlobal = (bool) ($data['is_global'] ?? false);
        if ($isGlobal && !$request->user()->root_admin) {
            throw new AuthorizationException();
        }

        if (!$isGlobal && MysqlBackupStorageProvider::query()->where('server_id', $server->id)->where('enabled', true)->count() >= 10) {
            return response()->json(['error' => 'This server already has the maximum of 10 custom storage providers.'], 422);
        }

        $config = $this->sanitizeProviderConfig($data['driver'], $data['config']);
        if ($message = $this->providerConfigError($data['driver'], $config)) {
            return response()->json(['error' => $message], 422);
        }

        $provider = new MysqlBackupStorageProvider([
            'server_id' => $isGlobal ? null : $server->id,
            'name' => $data['name'],
            'driver' => $data['driver'],
            'is_global' => $isGlobal,
            'is_default' => (bool) ($data['is_default'] ?? false),
            'enabled' => true,
        ]);
        $provider->setConfig($config);
        $provider->save();

        if ($provider->is_default) {
            MysqlBackupStorageProvider::query()
                ->where('id', '!=', $provider->id)
                ->where('server_id', $provider->server_id)
                ->where('is_global', $provider->is_global)
                ->update(['is_default' => false]);
        }

        return response()->json(['provider' => $this->providerResponse($provider)], 201);
    }

    /**
     * Supported one-click OAuth providers and their service resolvers.
     *
     * @return array<string, callable>
     */
    private function oauthServices(): array
    {
        return [
            'google_drive' => fn () => app(GoogleDriveOAuthService::class),
            'dropbox'      => fn () => app(DropboxOAuthService::class),
            'onedrive'     => fn () => app(OneDriveOAuthService::class),
        ];
    }

    /**
     * Step 1 — redirect user to the provider's OAuth consent screen.
     * The provider name and server UUID are encoded into the state param
     * so we can create the provider record after the callback.
     *
     * Generic version: works for google_drive, dropbox, onedrive.
     */
    public function oauthPrepare(Request $request, Server $server, string $provider): JsonResponse
    {
        $this->authorizeServer($request, $server, 'ACTION_DATABASE_UPDATE', 'database.update');

        $services = $this->oauthServices();
        if (!isset($services[$provider])) {
            return response()->json(['error' => 'Unsupported OAuth provider.'], 422);
        }

        $adminSettings = app(MysqlBackupAdminSettingsService::class);
        $app = $adminSettings->oauthApp($provider);
        if (!$app) {
            return response()->json(['error' => 'This provider has not been configured by an administrator. Ask an admin to add the app credentials in the admin settings.'], 422);
        }

        $data = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
        ])->validate();

        $state = base64_encode(json_encode([
            'provider' => $provider,
            'server'   => $server->uuid,
            'name'     => $data['name'],
            'csrf'     => csrf_token(),
        ]));

        $redirectUri = route('client.api.extension.mysql-backups.oauth-callback');
        $service = $services[$provider]();

        $url = $provider === 'onedrive'
            ? $service->buildAuthUrl($app['client_id'], $redirectUri, $state, $app['tenant'])
            : $service->buildAuthUrl($app['client_id'], $redirectUri, $state);

        return response()->json(['redirect_url' => $url]);
    }

    /**
     * Step 2 — the provider redirects back here with ?code=...&state=...
     * Exchange the code for tokens and save the provider record.
     *
     * Generic version: dispatches by state.provider.
     */
    public function oauthCallback(Request $request): \Illuminate\Http\RedirectResponse
    {
        $code  = $request->query('code', '');
        $state = $request->query('state', '');

        if ($code === '' || $state === '') {
            return redirect('/')->with('error', 'OAuth failed: missing code or state.');
        }

        $stateData = json_decode(base64_decode($state), true);

        if (!$stateData || ($stateData['csrf'] ?? '') !== $request->session()->token()) {
            return redirect('/')->with('error', 'OAuth failed: invalid state.');
        }

        $provider = (string) ($stateData['provider'] ?? 'google_drive');
        $services = $this->oauthServices();
        if (!isset($services[$provider])) {
            return redirect('/')->with('error', 'OAuth failed: unknown provider.');
        }

        $server = \Pterodactyl\Models\Server::where('uuid', $stateData['server'])->firstOrFail();

        $adminSettings = app(MysqlBackupAdminSettingsService::class);
        $app = $adminSettings->oauthApp($provider);
        if (!$app) {
            return redirect('/')->with('error', 'OAuth failed: the admin app for this provider is no longer configured.');
        }

        $redirectUri = route('client.api.extension.mysql-backups.oauth-callback');
        $service = $services[$provider]();

        try {
            $tokens = $provider === 'onedrive'
                ? $service->exchangeCode($app['client_id'], $app['client_secret'], $code, $redirectUri, $app['tenant'])
                : $service->exchangeCode($app['client_id'], $app['client_secret'], $code, $redirectUri);
        } catch (\RuntimeException $e) {
            return redirect('/')->with('error', 'OAuth failed: ' . $e->getMessage());
        }

        $config = $this->oauthProviderConfig($provider, $app, $tokens);

        $providerModel = new MysqlBackupStorageProvider([
            'server_id'  => $server->id,
            'name'       => $stateData['name'],
            'driver'     => $provider,
            'is_global'  => false,
            'is_default' => false,
            'enabled'    => true,
        ]);
        $providerModel->setConfig($config);
        $providerModel->save();

        $label = $this->oauthProviderLabel($provider);

        return redirect('/server/' . $server->uuid . '#mysql-backups')
            ->with('success', $label . ' connected successfully.');
    }

    /**
     * Build the per-provider config array that stores only the user's tokens.
     * The admin-owned client id/secret are never stored on the provider record.
     */
    private function oauthProviderConfig(string $provider, array $app, array $tokens): array
    {
        return match ($provider) {
            'google_drive' => [
                'gdrive_refresh_token' => $tokens['refresh_token'],
                'gdrive_access_token'  => $tokens['access_token'],
                'gdrive_token_expiry'  => time() + (int) ($tokens['expires_in'] ?? 3600),
            ],
            'dropbox' => [
                'dropbox_refresh_token' => $tokens['refresh_token'],
                'dropbox_access_token'  => $tokens['access_token'],
                'dropbox_token_expiry'  => time() + (int) ($tokens['expires_in'] ?? 14400),
            ],
            'onedrive' => [
                'onedrive_refresh_token' => $tokens['refresh_token'],
                'onedrive_access_token'  => $tokens['access_token'],
                'onedrive_token_expiry'  => time() + (int) ($tokens['expires_in'] ?? 3600),
            ],
            default => [],
        };
    }

    private function oauthProviderLabel(string $provider): string
    {
        return match ($provider) {
            'google_drive' => 'Google Drive',
            'dropbox'      => 'Dropbox',
            'onedrive'     => 'OneDrive',
            default        => ucfirst($provider),
        };
    }

    // -------------------------------------------------------------------------
    // Backward-compatible Google Drive aliases (keep old routes working)
    // -------------------------------------------------------------------------

    public function googleOAuthRedirect(Request $request, Server $server): JsonResponse
    {
        return $this->oauthPrepare($request, $server, 'google_drive');
    }

    public function googleOAuthPrepare(Request $request, Server $server): JsonResponse
    {
        return $this->oauthPrepare($request, $server, 'google_drive');
    }

    public function googleOAuthCallback(Request $request): \Illuminate\Http\RedirectResponse
    {
        // The legacy Google callback may receive state without a "provider"
        // key — inject it so the generic callback can dispatch.
        $state = $request->query('state', '');

        if ($state !== '') {
            $decoded = json_decode(base64_decode($state), true);
            if (is_array($decoded) && !isset($decoded['provider'])) {
                $decoded['provider'] = 'google_drive';
                $request->merge(['state' => base64_encode(json_encode($decoded))]);
            }
        }

        return $this->oauthCallback($request);
    }

    public function deleteProvider(Request $request, Server $server, int $provider): JsonResponse
    {
        $this->authorizeServer($request, $server, 'ACTION_DATABASE_UPDATE', 'database.update');

        $storageProvider = MysqlBackupStorageProvider::query()
            ->where('id', $provider)
            ->where('server_id', $server->id)
            ->where('is_global', false)
            ->firstOrFail();

        // If this provider is currently selected in the server's configuration, unset it
        MysqlBackupConfiguration::query()
            ->where('server_id', $server->id)
            ->where('storage_provider_id', $storageProvider->id)
            ->update(['storage_provider_id' => null]);

        $storageProvider->delete();

        app(MysqlBackupAuditService::class)->record('server_provider_deleted', [
            'server_id'     => $server->id,
            'provider_id'   => $provider,
            'provider_name' => $storageProvider->name,
            'driver'        => $storageProvider->driver,
        ], $request);

        return response()->json(['deleted' => true]);
    }

    public function manual(Request $request, Server $server): JsonResponse
    {
        $this->authorizeServer($request, $server, 'ACTION_DATABASE_UPDATE', 'database.update');
        $adminSettings = app(MysqlBackupAdminSettingsService::class);
        $limits = $adminSettings->limitsResponse($server);

        $configuration = MysqlBackupConfiguration::query()->firstOrCreate(
            ['server_id' => $server->id],
            $adminSettings->defaultConfigurationAttributes($server)
        );
        try {
            app(MysqlBackupQuotaService::class)->assertCanQueue($server, $request->user(), $adminSettings);
        } catch (\RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }
        if ($limits['manual_cooldown_minutes'] > 0) {
            $recentManual = MysqlBackupRecord::query()
                ->where('server_id', $server->id)
                ->where('requested_by', $request->user()->id)
                ->where('manual', true)
                ->where('created_at', '>=', now()->subMinutes($limits['manual_cooldown_minutes']))
                ->exists();

            if ($recentManual) {
                return response()->json([
                    'error' => 'A manual backup was queued recently. Please wait before starting another one.',
                ], 429);
            }
        }
        $databaseIds = $server->databases()->pluck('id')->all();
        $data = Validator::make($request->all(), [
            'database_ids' => ['nullable', 'array'],
            'database_ids.*' => ['integer', Rule::in($databaseIds)],
        ])->validate();

        $records = app(MysqlBackupQueueService::class)->queueServer(
            $configuration,
            $server,
            $request->user()->id,
            $data['database_ids'] ?? null,
            true,
        );
        app(MysqlBackupAuditService::class)->record('manual_backup_queued', [
            'server_id' => $server->id,
            'database_ids' => $data['database_ids'] ?? null,
            'backup_count' => $records->count(),
        ], $request);

        return response()->json([
            'status' => 'queued',
            'records' => $records->map(fn (MysqlBackupRecord $record) => $this->recordResponse($record, $server))->values(),
        ], 202);
    }

    public function download(
        Request $request,
        Server $server,
        string $backup,
        MysqlBackupStorageManager $storage,
    ) {
        $this->authorizeServer($request, $server, 'ACTION_DATABASE_READ', 'database.read');

        $record = MysqlBackupRecord::query()
            ->where('server_id', $server->id)
            ->where('uuid', $backup)
            ->whereIn('status', [MysqlBackupStatus::SUCCESS, MysqlBackupStatus::RESTORED])
            ->firstOrFail();

        $stream = $storage->readStream($record->storageProvider, $record->path);
        app(MysqlBackupAuditService::class)->record('backup_downloaded', [
            'server_id' => $server->id,
            'backup_uuid' => $record->uuid,
        ], $request, $record);

        return response()->streamDownload(function () use ($stream) {
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, $record->filename, [
            'Content-Type' => 'application/gzip',
        ]);
    }

    public function restore(Request $request, Server $server, string $backup): JsonResponse
    {
        $this->authorizeServer($request, $server, 'ACTION_DATABASE_UPDATE', 'database.update');

        $record = MysqlBackupRecord::query()
            ->where('server_id', $server->id)
            ->where('uuid', $backup)
            ->whereIn('status', [MysqlBackupStatus::SUCCESS, MysqlBackupStatus::RESTORED])
            ->firstOrFail();
        $databaseIds = $server->databases()->pluck('id')->all();
        $data = Validator::make($request->all(), [
            'target_database_id' => ['required', 'integer', Rule::in($databaseIds)],
            'confirm_overwrite' => ['accepted'],
            'mode' => ['nullable', Rule::in(['full'])],
        ])->validate();

        RestoreMysqlBackupJob::dispatch(
            $record->id,
            $data['target_database_id'],
            $request->user()->id,
            true,
        );
        app(MysqlBackupAuditService::class)->record('restore_queued', [
            'server_id' => $server->id,
            'backup_uuid' => $record->uuid,
            'target_database_id' => $data['target_database_id'],
        ], $request, $record);

        return response()->json(['status' => 'restore_queued'], 202);
    }

    public function logs(Request $request, Server $server, string $backup): JsonResponse
    {
        $this->authorizeServer($request, $server, 'ACTION_DATABASE_READ', 'database.read');

        $record = MysqlBackupRecord::query()
            ->where('server_id', $server->id)
            ->where('uuid', $backup)
            ->firstOrFail();

        return response()->json($record->logs()->latest()->limit(100)->get()->map(fn (MysqlBackupLog $log) => [
            'level' => $log->level,
            'message' => $log->message,
            'context' => $log->context,
            'created_at' => $log->created_at?->toIso8601String(),
        ]));
    }

    public function destroy(
        Request $request,
        Server $server,
        string $backup,
        MysqlBackupStorageManager $storage,
    ): JsonResponse {
        $this->authorizeServer($request, $server, 'ACTION_DATABASE_UPDATE', 'database.update');

        $record = MysqlBackupRecord::query()
            ->where('server_id', $server->id)
            ->where('uuid', $backup)
            ->firstOrFail();

        if ($record->status === MysqlBackupStatus::RUNNING || $record->status === MysqlBackupStatus::RESTORING) {
            return response()->json(['error' => 'Cannot delete a backup that is currently running or restoring.'], 422);
        }

        if ($record->storageProvider && in_array($record->status, [MysqlBackupStatus::SUCCESS, MysqlBackupStatus::RESTORED], true)) {
            try {
                $storage->delete($record->storageProvider, $record->path);
            } catch (\Throwable $e) {
                // Log but still remove the record — the file may already be gone
            }
        }

        $record->delete();

        app(MysqlBackupAuditService::class)->record('backup_deleted', [
            'server_id' => $server->id,
            'backup_uuid' => $record->uuid,
            'database_name' => $record->database_name,
        ], $request, $record);

        return response()->json(['deleted' => true]);
    }

    private function storageProviders(Server $server)
    {
        return MysqlBackupStorageProvider::query()
            ->where('enabled', true)
            ->where(function ($query) use ($server) {
                $query->where('server_id', $server->id)->orWhere('is_global', true);
            })
            ->orderByDesc('server_id')
            ->orderByDesc('is_default')
            ->get();
    }

    private function configurationResponse(MysqlBackupConfiguration $configuration): array
    {
        return [
            'enabled' => $configuration->enabled,
            'database_ids' => $configuration->database_ids ?: [],
            'frequency_type' => 'interval',
            'interval_minutes' => $configuration->interval_minutes,
            'retention_count' => $configuration->retention_count,
            'retention_days' => $configuration->retention_days,
            'storage_provider_id' => $configuration->storage_provider_id,
            'compress' => true,
            'encrypt' => $configuration->encrypt,
            'notifications' => $configuration->notifications ?: [],
            'next_run_at' => $configuration->next_run_at?->toIso8601String(),
        ];
    }

    private function providerResponse(MysqlBackupStorageProvider $provider): array
    {
        return [
            'id' => $provider->id,
            'name' => $provider->name,
            'driver' => $provider->driver,
            'is_global' => $provider->is_global,
            'is_default' => $provider->is_default,
        ];
    }

    private function recordResponse(MysqlBackupRecord $record, Server $server): array
    {
        return [
            'uuid' => $record->uuid,
            'database_name' => $record->database_name,
            'status' => $record->status,
            'filename' => $record->filename,
            'size_bytes' => $record->size_bytes,
            'progress' => $record->progress,
            'compressed' => $record->compressed,
            'encrypted' => $record->encrypted,
            'verified' => $record->verified,
            'stage' => $record->stage,
            'duration_ms' => $record->duration_ms,
            'safety_backup' => $record->safety_backup,
            'manual' => $record->manual,
            'error_message' => $record->error_message,
            'created_at' => $record->created_at?->toIso8601String(),
            'started_at' => $record->started_at?->toIso8601String(),
            'completed_at' => $record->completed_at?->toIso8601String(),
            'download_url' => route('client.api.extension.mysql-backups.download', [
                'server' => $server->uuid,
                'backup' => $record->uuid,
            ]),
        ];
    }

    private function sanitizeProviderConfig(string $driver, array $config): array
    {
        $allowed = match ($driver) {
            's3', 'aws_s3', 'cloudflare_r2', 'minio', 'wasabi', 'backblaze_b2', 'digitalocean_spaces', 'linode_object_storage', 'vultr_object_storage', 'scaleway_object_storage', 'oracle_object_storage', 'google_cloud_storage' => ['key', 'secret', 'region', 'bucket', 'endpoint', 'path_style'],
            'ftp', 'ftps' => ['host', 'username', 'password', 'port', 'root', 'ssl', 'passive', 'timeout'],
            'sftp' => ['host', 'username', 'password', 'private_key', 'passphrase', 'port', 'root', 'timeout'],
            'google_drive' => ['gdrive_client_id', 'gdrive_client_secret', 'gdrive_refresh_token', 'gdrive_access_token', 'gdrive_token_expiry'],
            'dropbox' => ['dropbox_refresh_token', 'dropbox_access_token', 'dropbox_token_expiry'],
            'onedrive' => ['onedrive_refresh_token', 'onedrive_access_token', 'onedrive_token_expiry'],
            'webdav' => ['url', 'username', 'password'],
            'box', 'mega', 'pcloud', 'yandex_disk', 'rclone' => ['remote', 'rclone_config'],
            default => ['root'],
        };

        return array_intersect_key($config, array_flip($allowed));
    }

    private function providerConfigError(string $driver, array $config): ?string
    {
        if (in_array($driver, ['google_drive', 'dropbox', 'onedrive'], true)) {
            $tokenKey = match ($driver) {
                'google_drive' => 'gdrive_refresh_token',
                'dropbox'      => 'dropbox_refresh_token',
                'onedrive'     => 'onedrive_refresh_token',
            };

            if (empty($config[$tokenKey])) {
                $label = $this->oauthProviderLabel($driver);
                return $label . ' must be connected via OAuth. Use the "Connect ' . $label . '" button.';
            }

            return null;
        }

        if ($driver === 'webdav') {
            if (trim((string) ($config['url'] ?? '')) === '') {
                return 'Enter the WebDAV base URL, for example https://dav.example.com/backups.';
            }

            return null;
        }

        if (in_array($driver, ['box', 'mega', 'pcloud', 'yandex_disk', 'rclone'], true)) {
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
            'box' => ['box'],
            'mega' => ['mega'],
            'pcloud' => ['pcloud'],
            'yandex_disk' => ['yandex'],
            'rclone' => ['drive', 'onedrive', 'dropbox', 'box', 'mega', 'pcloud', 'yandex', 'webdav'],
            default => ['drive', 'onedrive', 'dropbox', 'box', 'mega', 'pcloud', 'yandex', 'webdav'],
        };

        return in_array(strtolower($typeMatch[1]), $allowedTypes, true)
            ? null
            : 'The rclone config type does not match the selected storage driver.';
    }

    private function webhookUrlIsAllowed(string $url): bool
    {
        if (filter_var(env('MYSQL_BACKUP_ALLOW_PRIVATE_WEBHOOKS', false), FILTER_VALIDATE_BOOLEAN)) {
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

    private function authorizeServer(Request $request, Server $server, string $constant, string $fallbackAbility): void
    {
        if (!$this->canServer($request, $server, $constant, $fallbackAbility)) {
            throw new AuthorizationException();
        }
    }

    private function canServer(Request $request, Server $server, string $constant, string $fallbackAbility): bool
    {
        if ((bool) $request->user()->root_admin) {
            return true;
        }

        $ability = defined(Permission::class . '::' . $constant)
            ? constant(Permission::class . '::' . $constant)
            : $fallbackAbility;

        return $request->user()->can($ability, $server);
    }
}
