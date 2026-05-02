@php
    $settingsService = app('Pterodactyl\\BlueprintFramework\\Extensions\\{identifier}\\MysqlBackupAdminSettingsService');
    $storageManager = app('Pterodactyl\\BlueprintFramework\\Extensions\\{identifier}\\MysqlBackupStorageManager');
    $storageManager->localProvider();

    $settings = $settingsService->get();
    $limits = $settingsService->limitsResponse();
    $providerClass = 'Pterodactyl\\BlueprintFramework\\Extensions\\{identifier}\\MysqlBackupStorageProvider';
    $recordClass = 'Pterodactyl\\BlueprintFramework\\Extensions\\{identifier}\\MysqlBackupRecord';
    $auditClass = 'Pterodactyl\\BlueprintFramework\\Extensions\\{identifier}\\MysqlBackupAuditLog';
    $configClass = 'Pterodactyl\\BlueprintFramework\\Extensions\\{identifier}\\MysqlBackupConfiguration';
    $settingClass = 'Pterodactyl\\BlueprintFramework\\Extensions\\{identifier}\\MysqlBackupAdminSetting';
    $serverClass = 'Pterodactyl\\Models\\Server';

    $driverLabels = $storageManager->storageDriverLabels();
    $providers = $providerClass::query()->where('is_global', true)->where('enabled', true)->orderByDesc('is_default')->latest()->get();
    $recentBackups = $recordClass::query()->latest()->paginate(15, ['*'], 'backup_page');
    $recentAudits = $auditClass::query()->latest()->paginate(12, ['*'], 'audit_page');
    $serverConfigs = $configClass::query()->with('server')->latest()->limit(12)->get();
    $serverOverrides = $settingClass::query()->where('key', 'like', 'server:%')->latest()->limit(12)->get();

    $serverSearch = trim((string) request('server_search', ''));
    $serverList = $serverClass::query()
        ->when($serverSearch !== '', function ($query) use ($serverSearch) {
            $query->where(function ($builder) use ($serverSearch) {
                $builder->where('name', 'like', '%' . $serverSearch . '%')
                    ->orWhere('uuid', 'like', '%' . $serverSearch . '%');

                if (ctype_digit($serverSearch)) {
                    $builder->orWhere('id', (int) $serverSearch);
                }
            });
        })
        ->orderBy('name')
        ->limit(150)
        ->get(['id', 'uuid', 'name']);
    $overrideServerIds = $serverOverrides
        ->map(fn ($override) => (int) str_replace('server:', '', $override->key))
        ->filter()
        ->values();
    $overrideServers = $overrideServerIds->isEmpty()
        ? collect()
        : $serverClass::query()->whereIn('id', $overrideServerIds)->get(['id', 'uuid', 'name'])->keyBy('id');

    $storageUsed = (int) $recordClass::query()->whereIn('status', ['success', 'restored'])->sum('size_bytes');
    $runningCount = $recordClass::query()->whereIn('status', ['queued', 'running', 'restoring'])->count();
    $failedCount = $recordClass::query()->where('status', 'failed')->where('created_at', '>=', now()->subDay())->count();
    $successCount = $recordClass::query()->whereIn('status', ['success', 'restored'])->where('created_at', '>=', now()->subDay())->count();

    $icons = [
        'activity' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
        'alert-triangle' => '<path d="m21.7 18.3-8.5-14.8a1.4 1.4 0 0 0-2.4 0L2.3 18.3A1.4 1.4 0 0 0 3.5 20h17a1.4 1.4 0 0 0 1.2-1.7Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'check-circle' => '<path d="M22 11.1V12a10 10 0 1 1-5.9-9.1"/><path d="m9 11 3 3L22 4"/>',
        'cloud-upload' => '<path d="M12 13v8"/><path d="m16 17-4-4-4 4"/><path d="M20.4 16.2A5 5 0 0 0 18 7h-1.3A8 8 0 1 0 4 14.9"/>',
        'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14c0 1.7 4 3 9 3s9-1.3 9-3V5"/><path d="M3 12c0 1.7 4 3 9 3s9-1.3 9-3"/>',
        'external-link' => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
        'hard-drive' => '<path d="M22 12H2"/><path d="M5.5 5.1 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.5-6.9A2 2 0 0 0 16.8 4H7.2a2 2 0 0 0-1.7 1.1Z"/><path d="M6 16h.01"/><path d="M10 16h.01"/>',
        'key-round' => '<path d="M2 18v3h3l9.6-9.6"/><circle cx="16" cy="8" r="6"/><path d="M15 6h.01"/>',
        'list-checks' => '<path d="m3 17 2 2 4-4"/><path d="m3 7 2 2 4-4"/><path d="M13 6h8"/><path d="M13 12h8"/><path d="M13 18h8"/>',
        'save' => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8"/><path d="M7 3v5h8"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'server' => '<rect width="20" height="8" x="2" y="2" rx="2"/><rect width="20" height="8" x="2" y="14" rx="2"/><path d="M6 6h.01"/><path d="M6 18h.01"/>',
        'shield' => '<path d="M20 13c0 5-3.5 7.5-7.7 8.9a1 1 0 0 1-.6 0C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.2-2.7a1.2 1.2 0 0 1 1.6 0C14.5 3.8 17 5 19 5a1 1 0 0 1 1 1Z"/>',
        'sliders' => '<path d="M21 4h-7"/><path d="M10 4H3"/><path d="M21 12h-9"/><path d="M8 12H3"/><path d="M21 20h-5"/><path d="M12 20H3"/><path d="M14 2v4"/><path d="M8 10v4"/><path d="M16 18v4"/>',
    ];
    $icon = fn (string $name, string $class = '') => '<svg class="lucide ' . $class . '" viewBox="0 0 24 24" aria-hidden="true">' . ($icons[$name] ?? '') . '</svg>';
@endphp

<style>
    .mysql-backup-admin { color: #d1d5db; max-width: 1240px; }
    .mysql-backup-admin * { box-sizing: border-box; }
    .mysql-backup-admin .lucide { fill: none; stroke: currentColor; stroke-linecap: round; stroke-linejoin: round; stroke-width: 2; height: 18px; width: 18px; }
    .mysql-backup-admin .page-hero { align-items: flex-start; background: #111827; border: 1px solid #374151; border-radius: 6px; display: flex; gap: 16px; justify-content: space-between; margin-bottom: 16px; padding: 18px; }
    .mysql-backup-admin .eyebrow { color: #60a5fa; font-size: 11px; font-weight: 800; letter-spacing: .06em; margin-bottom: 6px; text-transform: uppercase; }
    .mysql-backup-admin h2 { color: #fff; font-size: 24px; font-weight: 800; line-height: 1.2; margin: 0; }
    .mysql-backup-admin h3 { align-items: center; color: #f9fafb; display: flex; gap: 8px; font-size: 15px; font-weight: 800; margin: 0; }
    .mysql-backup-admin p { margin: 0; }
    .mysql-backup-admin a { color: #60a5fa; text-decoration: none; }
    .mysql-backup-admin a:hover { color: #93c5fd; }
    .mysql-backup-admin .hero-copy { color: #9ca3af; line-height: 1.5; margin-top: 8px; max-width: 780px; }
    .mysql-backup-admin .hero-actions { align-items: flex-end; display: flex; flex-direction: column; gap: 8px; min-width: 210px; }
    .mysql-backup-admin .panel-box { background: #111827; border: 1px solid #374151; border-radius: 6px; margin-bottom: 16px; }
    .mysql-backup-admin .panel-box-header { align-items: center; border-bottom: 1px solid #374151; display: flex; gap: 12px; justify-content: space-between; padding: 14px 16px; }
    .mysql-backup-admin .panel-box-body { padding: 16px; }
    .mysql-backup-admin .grid { display: grid; gap: 14px; grid-template-columns: repeat(4, minmax(0, 1fr)); }
    .mysql-backup-admin .grid-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .mysql-backup-admin .grid-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .mysql-backup-admin .scope-grid { display: grid; gap: 14px; grid-column: 1 / -1; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .mysql-backup-admin .metric-grid { display: grid; gap: 12px; grid-template-columns: repeat(4, minmax(0, 1fr)); }
    .mysql-backup-admin .metric { background: #0b1220; border: 1px solid #273244; border-radius: 6px; padding: 14px; }
    .mysql-backup-admin .metric-top { align-items: center; color: #9ca3af; display: flex; justify-content: space-between; }
    .mysql-backup-admin .metric strong { color: #fff; display: block; font-size: 26px; line-height: 1; margin-top: 12px; }
    .mysql-backup-admin .metric span { color: #9ca3af; display: block; font-size: 12px; margin-top: 6px; }
    .mysql-backup-admin label { color: #9ca3af; display: block; font-size: 11px; font-weight: 800; margin-bottom: 6px; text-transform: uppercase; }
    .mysql-backup-admin input[type="text"],
    .mysql-backup-admin input[type="number"],
    .mysql-backup-admin input[type="password"],
    .mysql-backup-admin select,
    .mysql-backup-admin textarea { background: #0b1220; border: 1px solid #374151; border-radius: 4px; color: #e5e7eb; outline: none; padding: 9px 10px; width: 100%; }
    .mysql-backup-admin textarea { min-height: 120px; resize: vertical; }
    .mysql-backup-admin input:focus, .mysql-backup-admin select:focus, .mysql-backup-admin textarea:focus { border-color: #3b82f6; box-shadow: 0 0 0 1px rgba(59, 130, 246, .35); }
    .mysql-backup-admin .hint { color: #9ca3af; font-size: 12px; line-height: 1.45; margin-top: 7px; }
    .mysql-backup-admin .inline-form { align-items: end; display: grid; gap: 10px; grid-template-columns: minmax(260px, 1fr) auto; margin-bottom: 14px; }
    .mysql-backup-admin .check-row { align-items: center; background: #0b1220; border: 1px solid #273244; border-radius: 4px; display: flex; gap: 9px; min-height: 39px; padding: 9px 10px; }
    .mysql-backup-admin .check-row label { color: #d1d5db; font-size: 13px; font-weight: 700; margin: 0; text-transform: none; }
    .mysql-backup-admin .provider-grid { display: grid; gap: 8px; grid-template-columns: repeat(4, minmax(0, 1fr)); margin-top: 12px; }
    .mysql-backup-admin .actions { align-items: center; display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px; }
    .mysql-backup-admin .btn { align-items: center; border: 0; border-radius: 4px; cursor: pointer; display: inline-flex; gap: 7px; font-weight: 800; min-height: 36px; padding: 8px 12px; }
    .mysql-backup-admin .btn-primary { background: #2563eb; color: #fff; }
    .mysql-backup-admin .btn-danger { background: #dc2626; color: #fff; }
    .mysql-backup-admin .btn-secondary { background: #374151; color: #f9fafb; }
    .mysql-backup-admin .notice { align-items: flex-start; border-radius: 4px; display: flex; gap: 9px; margin-bottom: 14px; padding: 11px 12px; }
    .mysql-backup-admin .notice-success { background: #064e3b; color: #d1fae5; }
    .mysql-backup-admin .notice-error { background: #7f1d1d; color: #fee2e2; }
    .mysql-backup-admin .chip { align-items: center; border-radius: 999px; display: inline-flex; font-size: 11px; font-weight: 800; gap: 5px; padding: 4px 8px; text-transform: uppercase; white-space: nowrap; }
    .mysql-backup-admin .chip-success { background: #064e3b; color: #a7f3d0; }
    .mysql-backup-admin .chip-failed { background: #7f1d1d; color: #fecaca; }
    .mysql-backup-admin .chip-running { background: #1e3a8a; color: #bfdbfe; }
    .mysql-backup-admin .chip-muted { background: #374151; color: #d1d5db; }
    .mysql-backup-admin .provider-row { align-items: center; background: #0b1220; border: 1px solid #273244; border-radius: 6px; display: flex; gap: 14px; justify-content: space-between; margin-bottom: 8px; padding: 12px; }
    .mysql-backup-admin .provider-title { color: #f9fafb; font-weight: 800; }
    .mysql-backup-admin .muted { color: #9ca3af; font-size: 12px; }
    .mysql-backup-admin .table-wrap { overflow-x: auto; }
    .mysql-backup-admin table { border-collapse: collapse; width: 100%; }
    .mysql-backup-admin th, .mysql-backup-admin td { border-bottom: 1px solid #273244; font-size: 13px; padding: 10px 9px; text-align: left; white-space: nowrap; }
    .mysql-backup-admin th { color: #9ca3af; font-size: 11px; text-transform: uppercase; }
    .mysql-backup-admin td { color: #d1d5db; }
    .mysql-backup-admin .override-list { display: grid; gap: 10px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .mysql-backup-admin .override-card { background: #0b1220; border: 1px solid #273244; border-radius: 6px; padding: 12px; }
    .mysql-backup-admin .override-card strong { color: #fff; display: block; margin-bottom: 6px; }
    .mysql-backup-admin .override-meta { display: grid; gap: 7px; grid-template-columns: repeat(2, minmax(0, 1fr)); margin-top: 10px; }
    .mysql-backup-admin .override-meta span { color: #9ca3af; display: block; font-size: 11px; text-transform: uppercase; }
    .mysql-backup-admin .override-meta b { color: #e5e7eb; display: block; font-size: 13px; margin-top: 2px; }
    .mysql-backup-admin .pager { display: flex; gap: 8px; justify-content: flex-end; margin-top: 12px; }
    .mysql-backup-admin .pager a, .mysql-backup-admin .pager span { background: #0b1220; border: 1px solid #374151; border-radius: 4px; color: #d1d5db; padding: 6px 10px; text-decoration: none; }
    @media (max-width: 980px) {
        .mysql-backup-admin .page-hero { flex-direction: column; }
        .mysql-backup-admin .hero-actions { align-items: flex-start; }
        .mysql-backup-admin .grid,
        .mysql-backup-admin .grid-2,
        .mysql-backup-admin .grid-3,
        .mysql-backup-admin .metric-grid,
        .mysql-backup-admin .provider-grid,
        .mysql-backup-admin .override-list,
        .mysql-backup-admin .inline-form,
        .mysql-backup-admin .scope-grid { grid-template-columns: 1fr; }
        .mysql-backup-admin .provider-row { align-items: flex-start; flex-direction: column; }
    }
</style>

<div class="mysql-backup-admin">
    <div class="page-hero">
        <div>
            <div class="eyebrow">Blueprint Extension</div>
            <h2>MySQL Backup Manager</h2>
            <p class="hero-copy">
                Queue-driven server database backups with per-server limits, encrypted storage credentials, user-owned cloud providers, restore safety, and audit visibility.
                Made by <a href="https://github.com/najuaircrack" target="_blank" rel="noopener noreferrer">@najuaircrack</a>.
            </p>
        </div>
        <div class="hero-actions">
            <span class="chip chip-success">{!! $icon('check-circle') !!} Panel native</span>
            <span class="chip chip-muted">{!! $icon('cloud-upload') !!} User Drive ready</span>
        </div>
    </div>

    @if(session('success'))
        <div class="notice notice-success">{!! $icon('check-circle') !!}<div>{{ session('success') }}</div></div>
    @endif

    @if($errors->any())
        <div class="notice notice-error">
            {!! $icon('alert-triangle') !!}
            <div>
                @foreach($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        </div>
    @endif

    @if(session('error'))
        <div class="notice notice-error">{!! $icon('alert-triangle') !!}<div>{{ session('error') }}</div></div>
    @endif

    <div class="metric-grid">
        <div class="metric">
            <div class="metric-top">{!! $icon('activity') !!}<span>Active</span></div>
            <strong>{{ $runningCount }}</strong>
            <span>Queued, running, or restoring</span>
        </div>
        <div class="metric">
            <div class="metric-top">{!! $icon('check-circle') !!}<span>24h success</span></div>
            <strong>{{ $successCount }}</strong>
            <span>Completed in the last day</span>
        </div>
        <div class="metric">
            <div class="metric-top">{!! $icon('alert-triangle') !!}<span>24h failures</span></div>
            <strong>{{ $failedCount }}</strong>
            <span>Failed in the last day</span>
        </div>
        <div class="metric">
            <div class="metric-top">{!! $icon('hard-drive') !!}<span>Tracked size</span></div>
            <strong>{{ number_format($storageUsed / 1024 / 1024, 1) }} MB</strong>
            <span>Successful backup records</span>
        </div>
    </div>

    <div class="panel-box" style="margin-top: 16px;">
        <div class="panel-box-header">
            <h3>{!! $icon('search') !!} Server Override Lookup</h3>
            <span class="muted">Search by name, id, or UUID before saving server-specific limits.</span>
        </div>
        <div class="panel-box-body">
            <form class="inline-form" method="GET">
                <div>
                    <label>Find server</label>
                    <input type="text" name="server_search" value="{{ $serverSearch }}" placeholder="Search server name, id, or UUID">
                </div>
                <button class="btn btn-secondary" type="submit">{!! $icon('search') !!} Search</button>
            </form>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.extensions.mysql-backups.settings') }}">
        @csrf

        <div class="panel-box">
            <div class="panel-box-header">
                <h3>{!! $icon('sliders') !!} Policy Scope And Limits</h3>
                <span class="muted">Leave scope global, or choose a server from the list to save server-side limits.</span>
            </div>
            <div class="panel-box-body">
                <div class="grid">
                    <div class="scope-grid">
                        <div>
                            <label>Settings scope</label>
                            <select name="scope_server_id">
                                <option value="">Global defaults for new servers</option>
                                @foreach($serverList as $server)
                                    <option value="{{ $server->id }}" @selected((string) old('scope_server_id') === (string) $server->id)>
                                        #{{ $server->id }} - {{ $server->name }} ({{ substr($server->uuid, 0, 8) }})
                                    </option>
                                @endforeach
                            </select>
                            <p class="hint">The list shows up to 150 servers. Use search above for large panels.</p>
                        </div>
                        <div class="check-row" style="align-self: end;">
                            <input type="checkbox" name="policy[default_enabled]" value="1" @checked(old('policy.default_enabled', $settings['policy']['default_enabled']))>
                            <label>Enable backups by default for this scope</label>
                        </div>
                    </div>

                    <div>
                        <label>Default interval minutes</label>
                        <input type="number" name="policy[default_interval_minutes]" value="{{ old('policy.default_interval_minutes', $settings['policy']['default_interval_minutes']) }}" min="1">
                    </div>
                    <div>
                        <label>Minimum interval minutes</label>
                        <input type="number" name="policy[min_interval_minutes]" value="{{ old('policy.min_interval_minutes', $settings['policy']['min_interval_minutes']) }}" min="1">
                    </div>
                    <div>
                        <label>Maximum interval minutes</label>
                        <input type="number" name="policy[max_interval_minutes]" value="{{ old('policy.max_interval_minutes', $settings['policy']['max_interval_minutes']) }}" min="1">
                    </div>
                    <div>
                        <label>Max concurrent server jobs</label>
                        <input type="number" name="policy[max_concurrent_server_jobs]" value="{{ old('policy.max_concurrent_server_jobs', $settings['policy']['max_concurrent_server_jobs']) }}" min="1" max="25">
                    </div>

                    <div>
                        <label>Default keep last</label>
                        <input type="number" name="policy[default_retention_count]" value="{{ old('policy.default_retention_count', $settings['policy']['default_retention_count']) }}" min="1">
                    </div>
                    <div>
                        <label>Maximum keep last</label>
                        <input type="number" name="policy[max_retention_count]" value="{{ old('policy.max_retention_count', $settings['policy']['max_retention_count']) }}" min="1">
                    </div>
                    <div>
                        <label>Default max age days</label>
                        <input type="number" name="policy[default_retention_days]" value="{{ old('policy.default_retention_days', $settings['policy']['default_retention_days']) }}" min="1" placeholder="No age limit">
                    </div>
                    <div>
                        <label>Maximum age days</label>
                        <input type="number" name="policy[max_retention_days]" value="{{ old('policy.max_retention_days', $settings['policy']['max_retention_days']) }}" min="1">
                    </div>

                    <div>
                        <label>History list limit</label>
                        <input type="number" name="policy[max_history_items]" value="{{ old('policy.max_history_items', $settings['policy']['max_history_items']) }}" min="25" max="1000">
                    </div>
                    <div>
                        <label>Manual cooldown minutes</label>
                        <input type="number" name="policy[manual_cooldown_minutes]" value="{{ old('policy.manual_cooldown_minutes', $settings['policy']['manual_cooldown_minutes']) }}" min="0">
                    </div>
                    <div>
                        <label>Server quota MB</label>
                        <input type="number" name="policy[server_quota_mb]" value="{{ old('policy.server_quota_mb', $settings['policy']['server_quota_mb']) }}" min="0">
                    </div>
                    <div>
                        <label>User quota MB</label>
                        <input type="number" name="policy[user_quota_mb]" value="{{ old('policy.user_quota_mb', $settings['policy']['user_quota_mb']) }}" min="0" placeholder="No per-user quota">
                    </div>
                </div>

                <div class="provider-grid">
                    <div class="check-row">
                        <input type="checkbox" name="policy[default_encrypt]" value="1" @checked(old('policy.default_encrypt', $settings['policy']['default_encrypt']))>
                        <label>Encrypt by default</label>
                    </div>
                    <div class="check-row">
                        <input type="checkbox" name="policy[force_encrypt]" value="1" @checked(old('policy.force_encrypt', $settings['policy']['force_encrypt']))>
                        <label>Force encryption</label>
                    </div>
                    <div class="check-row">
                        <input type="checkbox" name="policy[pre_restore_safety_backup]" value="1" @checked(old('policy.pre_restore_safety_backup', $settings['policy']['pre_restore_safety_backup']))>
                        <label>Safety backup before restore</label>
                    </div>
                    <div class="check-row">
                        <input type="checkbox" name="policy[verify_after_upload]" value="1" @checked(old('policy.verify_after_upload', $settings['policy']['verify_after_upload']))>
                        <label>Verify after upload</label>
                    </div>
                </div>
            </div>
        </div>

        <div class="panel-box">
            <div class="panel-box-header">
                <h3>{!! $icon('cloud-upload') !!} Build Storage Defaults</h3>
                <span class="muted">Google Drive can be user-owned through encrypted per-server rclone config.</span>
            </div>
            <div class="panel-box-body">
                <div class="grid grid-2">
                    <div>
                        <label>Default global storage</label>
                        <select name="providers[default_storage_provider_id]">
                            <option value="">Panel local fallback</option>
                            @foreach($providers as $provider)
                                <option value="{{ $provider->id }}" @selected(old('providers.default_storage_provider_id', $settings['providers']['default_storage_provider_id']) == $provider->id)>
                                    {{ $provider->name }} ({{ strtoupper($provider->driver) }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="check-row" style="align-self: end;">
                        <input type="checkbox" name="providers[allow_server_providers]" value="1" @checked(old('providers.allow_server_providers', $settings['providers']['allow_server_providers']))>
                        <label>Allow users to add server storage providers</label>
                    </div>
                </div>

                <div class="provider-grid">
                    <div class="check-row">
                        <input type="checkbox" name="providers[allow_local]" value="1" @checked(old('providers.allow_local', $settings['providers']['allow_local']))>
                        <label>Allow local fallback</label>
                    </div>
                    @foreach(array_diff(array_keys($driverLabels), ['local']) as $driver)
                        <div class="check-row">
                            <input type="checkbox" name="providers[allow_{{ $driver }}]" value="1" @checked(old('providers.allow_' . $driver, $settings['providers']['allow_' . $driver] ?? false))>
                            <label>{{ $driverLabels[$driver] }}</label>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="grid grid-2">
            <div class="panel-box">
                <div class="panel-box-header">
                    <h3>{!! $icon('key-round') !!} Dump Credentials</h3>
                </div>
                <div class="panel-box-body">
                    <div class="grid grid-2">
                        <div>
                            <label>Credential mode</label>
                            <select name="runtime[dump_credential_mode]">
                                <option value="database" @selected(old('runtime.dump_credential_mode', $settings['runtime']['dump_credential_mode']) === 'database')>Pterodactyl database user</option>
                                <option value="backup_user" @selected(old('runtime.dump_credential_mode', $settings['runtime']['dump_credential_mode']) === 'backup_user')>Dedicated backup user</option>
                            </select>
                        </div>
                        <div>
                            <label>Backup username</label>
                            <input type="text" name="runtime[dump_username]" value="{{ old('runtime.dump_username', $settings['runtime']['dump_username']) }}" placeholder="backup_user">
                        </div>
                        <div>
                            <label>Backup password</label>
                            <input type="password" name="runtime[dump_password]" value="" placeholder="{{ empty($settings['runtime']['dump_password']) ? 'Not set' : 'Stored securely' }}">
                        </div>
                        <div>
                            <label>Host override</label>
                            <input type="text" name="runtime[dump_host]" value="{{ old('runtime.dump_host', $settings['runtime']['dump_host']) }}" placeholder="Leave blank to use DB host">
                        </div>
                    </div>
                </div>
            </div>

            <div class="panel-box">
                <div class="panel-box-header">
                    <h3>{!! $icon('shield') !!} Runtime Guardrails</h3>
                </div>
                <div class="panel-box-body">
                    <div class="grid grid-2">
                        <div>
                            <label>Dump timeout seconds</label>
                            <input type="number" name="runtime[dump_timeout_seconds]" value="{{ old('runtime.dump_timeout_seconds', $settings['runtime']['dump_timeout_seconds']) }}" min="60">
                        </div>
                        <div>
                            <label>Restore timeout seconds</label>
                            <input type="number" name="runtime[restore_timeout_seconds]" value="{{ old('runtime.restore_timeout_seconds', $settings['runtime']['restore_timeout_seconds']) }}" min="60">
                        </div>
                        <div>
                            <label>Reconcile interval minutes</label>
                            <input type="number" name="runtime[reconcile_minutes]" value="{{ old('runtime.reconcile_minutes', $settings['runtime']['reconcile_minutes']) }}" min="1" max="60">
                        </div>
                        <div>
                            <label>Local fallback root</label>
                            <input type="text" name="runtime[local_root]" value="{{ old('runtime.local_root', $settings['runtime']['local_root']) }}">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="actions">
            <button class="btn btn-primary" type="submit">{!! $icon('save') !!} Save Backup Settings</button>
        </div>
    </form>

    <div class="panel-box">
        <div class="panel-box-header">
            <h3>{!! $icon('cloud-upload') !!} Global Storage Providers</h3>
            <span class="muted">{{ $providers->count() }} enabled</span>
        </div>
        <div class="panel-box-body">
            @forelse($providers as $provider)
                <div class="provider-row">
                    <div>
                        <div class="provider-title">{{ $provider->name }}</div>
                        <div class="muted">{{ $driverLabels[$provider->driver] ?? strtoupper($provider->driver) }}{{ $settings['providers']['default_storage_provider_id'] == $provider->id ? ' | default' : '' }}</div>
                        <div class="muted">{{ $provider->last_test_status ? 'Last test: ' . $provider->last_test_status : 'Not tested yet' }}</div>
                    </div>
                    <form method="POST" action="{{ route('admin.extensions.mysql-backups.storage-providers.delete', ['provider' => $provider->id]) }}">
                        @csrf
                        @method('DELETE')
                        <button class="btn btn-secondary" type="submit" form="test-provider-{{ $provider->id }}">{!! $icon('activity') !!} Test</button>
                        <button class="btn btn-danger" type="submit">Disable</button>
                    </form>
                    <form id="test-provider-{{ $provider->id }}" method="POST" action="{{ route('admin.extensions.mysql-backups.storage-providers.test', ['provider' => $provider->id]) }}" style="display: none;">
                        @csrf
                    </form>
                </div>
            @empty
                <p class="muted">No global providers configured. The panel local fallback remains available.</p>
            @endforelse

            <form method="POST" action="{{ route('admin.extensions.mysql-backups.storage-providers.store') }}" style="margin-top: 18px;">
                @csrf
                <div class="grid grid-3">
                    <div>
                        <label>Name</label>
                        <input type="text" name="name" placeholder="Primary R2 bucket" required>
                    </div>
                    <div>
                        <label>Driver</label>
                        <select name="driver">
                            @foreach(array_values(array_diff($limits['allowed_drivers'], ['local'])) as $driver)
                                <option value="{{ $driver }}">{{ $driverLabels[$driver] ?? strtoupper($driver) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="check-row" style="align-self: end;">
                        <input type="checkbox" name="is_default" value="1">
                        <label>Make default global provider</label>
                    </div>
                </div>

                <div class="grid grid-3" style="margin-top: 14px;">
                    <div>
                        <label>Root, bucket, or remote</label>
                        <input type="text" name="config[root]" placeholder="/var/lib/pterodactyl/backups/databases">
                        <input type="text" name="config[bucket]" placeholder="bucket-name" style="margin-top: 8px;">
                        <input type="text" name="config[remote]" placeholder="gdrive:pterodactyl/mysql" style="margin-top: 8px;">
                    </div>
                    <div>
                        <label>Endpoint or host</label>
                        <input type="text" name="config[endpoint]" placeholder="https://account.r2.cloudflarestorage.com">
                        <input type="text" name="config[host]" placeholder="storage.example.com" style="margin-top: 8px;">
                        <input type="number" name="config[port]" placeholder="21 or 22" style="margin-top: 8px;">
                    </div>
                    <div>
                        <label>Credentials</label>
                        <input type="text" name="config[key]" placeholder="S3 access key">
                        <input type="password" name="config[secret]" placeholder="S3 secret key" style="margin-top: 8px;">
                        <input type="text" name="config[username]" placeholder="FTP/SFTP username" style="margin-top: 8px;">
                        <input type="password" name="config[password]" placeholder="FTP/SFTP password" style="margin-top: 8px;">
                    </div>
                </div>

                <div style="margin-top: 14px;">
                    <label>Optional rclone config</label>
                    <textarea name="config[rclone_config]" placeholder="[gdrive]&#10;type = drive&#10;token = {...}"></textarea>
                    <p class="hint">Stored encrypted. Use this for user-owned Google Drive style providers when the remote is not configured globally on the panel host.</p>
                </div>

                <div class="grid grid-3" style="margin-top: 14px;">
                    <div>
                        <label>Region</label>
                        <input type="text" name="config[region]" value="auto">
                    </div>
                    <div class="actions" style="grid-column: span 2;">
                        <button class="btn btn-secondary" type="submit">{!! $icon('cloud-upload') !!} Add Provider</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="grid grid-2">
        <div class="panel-box">
            <div class="panel-box-header">
                <h3>{!! $icon('server') !!} Per-Server Backup Policies</h3>
            </div>
            <div class="panel-box-body table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Server</th>
                            <th>Status</th>
                            <th>Interval</th>
                            <th>Keep</th>
                            <th>Next Run</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($serverConfigs as $config)
                            <tr>
                                <td>#{{ $config->server_id }} {{ $config->server?->name ?: '-' }}</td>
                                <td><span class="chip chip-{{ $config->enabled ? 'success' : 'muted' }}">{{ $config->enabled ? 'enabled' : 'disabled' }}</span></td>
                                <td>{{ $config->interval_minutes }} min</td>
                                <td>{{ $config->retention_count }}</td>
                                <td>{{ $config->next_run_at ?: '-' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="muted">No per-server backup policies have been saved yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panel-box">
            <div class="panel-box-header">
                <h3>{!! $icon('list-checks') !!} Server Limit Overrides</h3>
            </div>
            <div class="panel-box-body">
                <div class="override-list">
                    @forelse($serverOverrides as $override)
                        @php
                            $serverId = (int) str_replace('server:', '', $override->key);
                            $server = $overrideServers->get($serverId);
                            $value = $override->value;
                            $policy = $value['policy'] ?? [];
                            $providerSettings = $value['providers'] ?? [];
                        @endphp
                        <div class="override-card">
                            <strong>#{{ $serverId }} {{ $server?->name ?: 'Unknown server' }}</strong>
                            <span class="muted">{{ $server?->uuid ? substr($server->uuid, 0, 8) : 'server override' }}</span>
                            <div class="override-meta">
                                <div><span>Interval</span><b>{{ ($policy['min_interval_minutes'] ?? '-') }}-{{ ($policy['max_interval_minutes'] ?? '-') }} min</b></div>
                                <div><span>Default</span><b>{{ ($policy['default_interval_minutes'] ?? '-') }} min</b></div>
                                <div><span>Keep</span><b>{{ ($policy['default_retention_count'] ?? '-') }} / {{ ($policy['max_retention_count'] ?? '-') }}</b></div>
                                <div><span>Quota</span><b>{{ ($policy['server_quota_mb'] ?? 0) ? number_format($policy['server_quota_mb']) . ' MB' : 'Unlimited' }}</b></div>
                            </div>
                            <div class="hint">{{ ($providerSettings['allow_server_providers'] ?? false) ? 'Users can add server providers.' : 'Global/local providers only.' }}</div>
                        </div>
                    @empty
                        <p class="muted">No server-specific admin limits have been saved yet.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <div class="panel-box">
        <div class="panel-box-header">
            <h3>{!! $icon('database') !!} Recent Backup Jobs</h3>
        </div>
        <div class="panel-box-body table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Database</th>
                        <th>Status</th>
                        <th>Stage</th>
                        <th>Size</th>
                        <th>Verified</th>
                        <th>Completed</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentBackups as $backup)
                        <tr>
                            <td>{{ $backup->database_name }}</td>
                            <td>
                                <span class="chip chip-{{ $backup->status === 'success' || $backup->status === 'restored' ? 'success' : (in_array($backup->status, ['queued', 'running', 'restoring']) ? 'running' : ($backup->status === 'failed' ? 'failed' : 'muted')) }}">
                                    {{ $backup->status }}
                                </span>
                            </td>
                            <td>{{ $backup->stage ?: '-' }}</td>
                            <td>{{ number_format(($backup->size_bytes ?: 0) / 1024 / 1024, 2) }} MB</td>
                            <td>{{ $backup->verified ? 'yes' : 'no' }}</td>
                            <td>{{ $backup->completed_at ?: $backup->created_at }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="muted">No backup jobs yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="pager">
                {{ $recentBackups->appends(request()->except('backup_page'))->links() }}
            </div>
        </div>
    </div>

    <div class="panel-box">
        <div class="panel-box-header">
            <h3>{!! $icon('shield') !!} Audit Trail</h3>
        </div>
        <div class="panel-box-body table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>Server</th>
                        <th>User</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recentAudits as $audit)
                        <tr>
                            <td>{{ $audit->event }}</td>
                            <td>{{ $audit->server_id ?: '-' }}</td>
                            <td>{{ $audit->user_id ?: '-' }}</td>
                            <td>{{ $audit->created_at }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="muted">No audit events yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="pager">
                {{ $recentAudits->appends(request()->except('audit_page'))->links() }}
            </div>
        </div>
    </div>
</div>
