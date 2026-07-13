import React, { FormEvent, useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import tw from 'twin.macro';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faCheckCircle,
    faCloudUploadAlt,
    faDatabase,
    faDownload,
    faExclamationTriangle,
    faPlay,
    faSearch,
    faSlidersH,
    faSyncAlt,
    faTrash,
    faUndo,
} from '@fortawesome/free-solid-svg-icons';
import { ServerContext } from '@/state/server';
import FlashMessageRender from '@/components/FlashMessageRender';
import Spinner from '@/components/elements/Spinner';
import Fade from '@/components/elements/Fade';
import GreyRowBox from '@/components/elements/GreyRowBox';
import Button from '@/components/elements/Button';

type BackupStatus = 'queued' | 'running' | 'success' | 'failed' | 'restoring' | 'restored';

interface ServerDatabase {
    id: number;
    name: string;
    username: string;
    host: string;
    port: number;
}

interface StorageProvider {
    id: number;
    name: string;
    driver: string;
    is_global: boolean;
    is_default: boolean;
}

interface BackupConfiguration {
    enabled: boolean;
    database_ids: number[];
    frequency_type: 'interval';
    interval_minutes: number;
    retention_count: number;
    retention_days: number | null;
    storage_provider_id: number | null;
    compress: boolean;
    encrypt: boolean;
    notifications: {
        webhook_url?: string;
        success?: boolean;
        failure?: boolean;
        discord_embed?: boolean;
        attach_backup?: boolean;
    };
    next_run_at: string | null;
}

interface BackupRecord {
    uuid: string;
    database_name: string;
    status: BackupStatus;
    filename: string;
    size_bytes: number;
    progress: number;
    compressed: boolean;
    encrypted: boolean;
    verified: boolean;
    stage: string | null;
    duration_ms: number | null;
    safety_backup: boolean;
    manual: boolean;
    error_message: string | null;
    created_at: string;
    started_at: string | null;
    completed_at: string | null;
    download_url: string;
}

interface BackupLimits {
    min_interval_minutes: number;
    max_interval_minutes: number;
    max_retention_count: number;
    max_retention_days: number;
    force_encrypt: boolean;
    max_history_items: number;
    manual_cooldown_minutes: number;
    server_quota_mb: number;
    user_quota_mb: number | null;
    pre_restore_safety_backup: boolean;
    verify_after_upload: boolean;
    max_concurrent_server_jobs: number;
    allow_server_providers: boolean;
    allowed_drivers: string[];
    oauth_providers: string[];
}

interface BackupQuota {
    server_used_bytes: number;
    server_quota_bytes: number;
    user_used_bytes: number;
    user_quota_bytes: number | null;
}

interface BackupPagination {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

const inputStyle = tw`w-full rounded border border-neutral-700 bg-neutral-900 px-3 py-2 text-sm text-neutral-100 outline-none transition-colors focus:border-primary-500`;
const labelStyle = tw`mb-1 block text-xs font-semibold uppercase text-neutral-400`;
const panelStyle = tw`rounded border border-neutral-700 bg-neutral-800 p-4`;
const s3LikeDrivers = [
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
const rcloneDrivers = [
    'box',
    'mega',
    'pcloud',
    'yandex_disk',
    'rclone',
];
const oauthDrivers = [
    'google_drive',
    'dropbox',
    'onedrive',
];
const webdavDriver = 'webdav';
const storageDriverLabels: Record<string, string> = {
    google_drive: 'Google Drive',
    onedrive: 'OneDrive',
    dropbox: 'Dropbox',
    box: 'Box',
    mega: 'MEGA',
    pcloud: 'pCloud',
    yandex_disk: 'Yandex Disk',
    webdav: 'WebDAV',
    rclone: 'Other rclone remote',
    s3: 'S3 compatible',
    aws_s3: 'AWS S3',
    cloudflare_r2: 'Cloudflare R2',
    minio: 'MinIO',
    wasabi: 'Wasabi',
    backblaze_b2: 'Backblaze B2',
    digitalocean_spaces: 'DigitalOcean Spaces',
    linode_object_storage: 'Linode Object Storage',
    vultr_object_storage: 'Vultr Object Storage',
    scaleway_object_storage: 'Scaleway Object Storage',
    oracle_object_storage: 'Oracle Object Storage',
    google_cloud_storage: 'Google Cloud Storage',
    ftp: 'FTP',
    ftps: 'FTPS',
    sftp: 'SFTP',
};

const statusColor = (status: BackupStatus) => {
    switch (status) {
        case 'success':
        case 'restored':
            return tw`text-green-400`;
        case 'failed':
            return tw`text-red-400`;
        case 'running':
        case 'restoring':
            return tw`text-primary-400`;
        default:
            return tw`text-yellow-300`;
    }
};

const formatSize = (bytes: number): string => {
    if (!bytes) return '0 B';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    if (bytes < 1024 * 1024 * 1024) return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
    return `${(bytes / 1024 / 1024 / 1024).toFixed(2)} GB`;
};

const sameDayKey = (value: string | null): string => {
    if (!value) return 'Queued';
    return new Date(value).toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' });
};

const MysqlAutoBackupComponent = () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const baseUrl = `/api/client/extensions/mysqlautobackup/${uuid}/mysql-backups`;

    const [databases, setDatabases] = useState<ServerDatabase[]>([]);
    const [providers, setProviders] = useState<StorageProvider[]>([]);
    const [config, setConfig] = useState<BackupConfiguration | null>(null);
    const [limits, setLimits] = useState<BackupLimits | null>(null);
    const [quota, setQuota] = useState<BackupQuota | null>(null);
    const [records, setRecords] = useState<BackupRecord[]>([]);
    const [pagination, setPagination] = useState<BackupPagination | null>(null);
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [working, setWorking] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState<'all' | BackupStatus>('all');
    const [restoreTarget, setRestoreTarget] = useState<Record<string, number | ''>>({});
    const [providerFormOpen, setProviderFormOpen] = useState(false);
    const [deletingProviderId, setDeletingProviderId] = useState<number | null>(null);
    const [deletingBackupUuid, setDeletingBackupUuid] = useState<string | null>(null);
    const [providerForm, setProviderForm] = useState({
        name: '',
        driver: 'google_drive',
        root: '/',
        bucket: '',
        endpoint: '',
        region: 'auto',
        key: '',
        secret: '',
        host: '',
        port: '',
        username: '',
        password: '',
        remote: 'gdrive:pterodactyl/mysql-backups',
        rclone_config: '',
        webdav_url: '',
        oauth_connecting: false,
    });

    const loadAll = () => {
        setError(null);
        return Promise.all([
            axios.get<ServerDatabase[]>(`${baseUrl}/databases`),
            axios.get(`${baseUrl}/config`),
            axios.get(`${baseUrl}?page=${page}&per_page=25`),
        ])
            .then(([dbRes, configRes, backupRes]) => {
                setDatabases(dbRes.data);
                setConfig(configRes.data.configuration);
                setLimits(configRes.data.limits);
                setQuota(configRes.data.quota);
                setProviders(configRes.data.storage_providers || []);
                setRecords(Array.isArray(backupRes.data) ? backupRes.data : backupRes.data.data);
                setPagination(Array.isArray(backupRes.data) ? null : backupRes.data.pagination);
            })
            .catch((error) => setError(error.response?.data?.error || 'Unable to load MySQL backup data.'))
            .finally(() => setLoading(false));
    };

    useEffect(() => {
        setLoading(true);
        loadAll();

        const interval = window.setInterval(() => {
            axios.get(`${baseUrl}?page=${page}&per_page=25`)
                .then((res) => {
                    setRecords(Array.isArray(res.data) ? res.data : res.data.data);
                    setPagination(Array.isArray(res.data) ? null : res.data.pagination);
                })
                .catch(() => undefined);
        }, 5000);

        return () => window.clearInterval(interval);
    }, [uuid, page]);

    useEffect(() => {
        const drivers = (limits?.allowed_drivers || [])
            .filter((d) => d !== 'local')
            .filter((d) => !oauthDrivers.includes(d) || (limits?.oauth_providers || []).includes(d));
        if (!limits || drivers.includes(providerForm.driver)) return;

        setProviderForm({ ...providerForm, driver: drivers[0] || 'google_drive' });
    }, [limits, providerForm.driver]);

    const selectedDatabaseIds = config?.database_ids?.length ? config.database_ids : databases.map((database) => database.id);

    const filteredRecords = useMemo(() => {
        return records.filter((record) => {
            const matchesSearch = record.database_name.toLowerCase().includes(search.toLowerCase()) ||
                record.filename.toLowerCase().includes(search.toLowerCase());
            const matchesStatus = statusFilter === 'all' || record.status === statusFilter;
            return matchesSearch && matchesStatus;
        });
    }, [records, search, statusFilter]);

    const groupedRecords = useMemo(() => {
        return filteredRecords.reduce<Record<string, BackupRecord[]>>((groups, record) => {
            const key = sameDayKey(record.completed_at || record.created_at);
            groups[key] = groups[key] || [];
            groups[key].push(record);
            return groups;
        }, {});
    }, [filteredRecords]);
    const quotaPercent = quota?.server_quota_bytes
        ? Math.min(100, Math.round((quota.server_used_bytes / quota.server_quota_bytes) * 100))
        : 0;
    const addableDrivers = (limits?.allowed_drivers || [])
        .filter((driver) => driver !== 'local')
        .filter((driver) => !oauthDrivers.includes(driver) || (limits?.oauth_providers || []).includes(driver));

    const updateConfig = (patch: Partial<BackupConfiguration>) => {
        if (!config) return;
        setConfig({ ...config, ...patch });
    };

    const toggleDatabase = (id: number) => {
        if (!config) return;
        const current = selectedDatabaseIds;
        const database_ids = current.includes(id) ? current.filter((value) => value !== id) : [...current, id];
        updateConfig({ database_ids: database_ids.length === databases.length ? [] : database_ids });
    };

    const saveConfig = (event: FormEvent) => {
        event.preventDefault();
        if (!config || !limits) return;

        setSaving(true);
        axios
            .put(`${baseUrl}/config`, {
                ...config,
                frequency_type: 'interval',
                compress: true,
                encrypt: limits.force_encrypt || config.encrypt,
            })
            .then((res) => setConfig(res.data.configuration))
            .catch((error) => setError(error.response?.data?.error || 'Unable to save backup configuration.'))
            .finally(() => setSaving(false));
    };

    const queueManualBackup = () => {
        setWorking(true);
        axios
            .post(baseUrl, { database_ids: config?.database_ids?.length ? config.database_ids : null })
            .then(() => {
                setPage(1);
                return loadAll();
            })
            .catch((error) => setError(error.response?.data?.error || 'Unable to queue manual backup.'))
            .finally(() => setWorking(false));
    };

    const queueRestore = (record: BackupRecord) => {
        const target = restoreTarget[record.uuid];
        if (!target) {
            setError('Select a target database before restoring.');
            return;
        }

        const confirmed = window.confirm(`Restore ${record.database_name} into the selected database? Existing data may be overwritten.`);
        if (!confirmed) return;

        setWorking(true);
        axios
            .post(`${baseUrl}/${record.uuid}/restore`, { target_database_id: target, confirm_overwrite: true, mode: 'full' })
            .then(() => loadAll())
            .catch((error) => setError(error.response?.data?.error || 'Unable to queue restore.'))
            .finally(() => setWorking(false));
    };

    const deleteProvider = (providerId: number) => {
        if (deletingProviderId === providerId) {
            // Second click — confirmed, do the delete
            axios
                .delete(`${baseUrl}/storage-providers/delete/${providerId}`)
                .then(() => {
                    setDeletingProviderId(null);
                    return loadAll();
                })
                .catch((error) => setError(error.response?.data?.error || 'Failed to delete provider.'));
        } else {
            // First click — ask for confirmation
            setDeletingProviderId(providerId);
        }
    };

    const deleteBackup = (record: BackupRecord) => {
        if (deletingBackupUuid === record.uuid) {
            // Second click — confirmed, do the delete
            axios
                .delete(`${baseUrl}/${record.uuid}`)
                .then(() => {
                    setDeletingBackupUuid(null);
                    return loadAll();
                })
                .catch((error) => setError(error.response?.data?.error || 'Failed to delete backup.'));
        } else {
            // First click — ask for confirmation
            setDeletingBackupUuid(record.uuid);
        }
    };

    const connectOAuth = (event: FormEvent) => {
        event.preventDefault();
        if (!providerForm.name) {
            setError('Enter a provider name before connecting.');
            return;
        }
        setProviderForm((f) => ({ ...f, oauth_connecting: true }));
        setSaving(true);
        axios
            .post(`${baseUrl}/oauth/${providerForm.driver}/prepare`, {
                name: providerForm.name,
            })
            .then(({ data }) => {
                const popup = window.open(data.redirect_url, 'oauth_popup', 'width=600,height=700');
                const timer = setInterval(() => {
                    if (popup && popup.closed) {
                        clearInterval(timer);
                        setProviderForm((f) => ({ ...f, oauth_connecting: false }));
                        setSaving(false);
                        setProviderFormOpen(false);
                        loadAll();
                    }
                }, 500);
            })
            .catch((error) => {
                setError(error.response?.data?.error || 'Failed to start OAuth.');
                setProviderForm((f) => ({ ...f, oauth_connecting: false }));
                setSaving(false);
            });
    };

    const saveProvider = (event: FormEvent) => {
        event.preventDefault();
        const driver = providerForm.driver;
        const configPayload =
            s3LikeDrivers.includes(driver)
                ? {
                      bucket: providerForm.bucket,
                      endpoint: providerForm.endpoint,
                      region: providerForm.region,
                      key: providerForm.key,
                      secret: providerForm.secret,
                      path_style: true,
                  }
                : driver === webdavDriver
                ? {
                      url: providerForm.webdav_url,
                      username: providerForm.username,
                      password: providerForm.password,
                  }
                : rcloneDrivers.includes(driver)
                ? { remote: providerForm.remote, rclone_config: providerForm.rclone_config }
                : {
                      host: providerForm.host,
                      port: Number(providerForm.port || (driver === 'sftp' ? 22 : 21)),
                      username: providerForm.username,
                      password: providerForm.password,
                      root: providerForm.root,
                  };

        setSaving(true);
        axios
            .post(`${baseUrl}/storage-providers`, {
                name: providerForm.name,
                driver,
                is_global: false,
                is_default: false,
                config: configPayload,
            })
            .then(() => {
                setProviderFormOpen(false);
                return loadAll();
            })
            .catch((error) => setError(error.response?.data?.error || 'Unable to save storage provider.'))
            .finally(() => setSaving(false));
    };

    if (loading || !config || !limits) {
        return <Spinner size={'large'} centered />;
    }

    return (
        <>
            <FlashMessageRender byKey={'database backups'} css={tw`mb-4`} />
            <Fade timeout={150}>
                <div css={tw`mt-6 space-y-6`}>
                    <div css={tw`flex flex-col gap-3 md:flex-row md:items-center md:justify-between`}>
                        <div>
                            <h2 css={tw`text-xl font-semibold text-white`}>MySQL Backups</h2>
                        </div>
                        <div css={tw`flex gap-2`}>
                            <Button color={'primary'} isSecondary onClick={loadAll}>
                                <FontAwesomeIcon icon={faSyncAlt} fixedWidth css={tw`mr-1`} />
                                Refresh
                            </Button>
                            <Button color={'green'} disabled={working || databases.length === 0} onClick={queueManualBackup}>
                                <FontAwesomeIcon icon={faPlay} fixedWidth css={tw`mr-1`} />
                                Manual Backup
                            </Button>
                        </div>
                    </div>

                    {error && (
                        <div css={tw`rounded border border-red-700 bg-red-900 p-3 text-sm text-red-100`}>
                            <div css={tw`mb-1 flex items-center font-semibold`}>
                                <FontAwesomeIcon icon={faExclamationTriangle} fixedWidth css={tw`mr-2`} />
                                Backup action failed
                            </div>
                            <div css={tw`whitespace-pre-wrap text-red-200`}>{error}</div>
                        </div>
                    )}

                    {quota && (
                        <div css={panelStyle}>
                            <div css={tw`flex flex-col gap-2 md:flex-row md:items-center md:justify-between`}>
                                <div>
                                    <div css={tw`font-semibold text-white`}>Storage Usage</div>
                                    <div css={tw`mt-1 text-xs text-neutral-400`}>
                                        {formatSize(quota.server_used_bytes)} used
                                        {quota.server_quota_bytes > 0 ? ` of ${formatSize(quota.server_quota_bytes)}` : ' with no server quota'}
                                    </div>
                                </div>
                                <div css={tw`h-2 w-full overflow-hidden rounded bg-neutral-700 md:w-64`}>
                                    <div css={tw`h-full bg-primary-500`} style={{ width: `${quotaPercent}%` }} />
                                </div>
                            </div>
                        </div>
                    )}

                    <form css={panelStyle} onSubmit={saveConfig}>
                        <div css={tw`mb-4 flex items-center justify-between`}>
                            <div css={tw`flex items-center text-white`}>
                                <FontAwesomeIcon icon={faSlidersH} fixedWidth css={tw`mr-2 text-primary-400`} />
                                <span css={tw`font-semibold`}>Server Backup Policy</span>
                            </div>
                            <label css={tw`flex items-center gap-2 text-sm text-neutral-200`}>
                                <input
                                    type={'checkbox'}
                                    checked={config.enabled}
                                    onChange={(event) => updateConfig({ enabled: event.target.checked })}
                                />
                                Enabled
                            </label>
                        </div>

                        <div css={tw`grid gap-4 md:grid-cols-4`}>
                            <div>
                                <label css={labelStyle}>Interval minutes</label>
                                <input
                                    css={inputStyle}
                                    type={'number'}
                                    min={limits.min_interval_minutes}
                                    max={limits.max_interval_minutes}
                                    value={config.interval_minutes}
                                    onChange={(event) => updateConfig({ interval_minutes: Number(event.target.value) })}
                                />
                            </div>
                            <div>
                                <label css={labelStyle}>Keep last</label>
                                <input
                                    css={inputStyle}
                                    type={'number'}
                                    min={1}
                                    max={limits.max_retention_count}
                                    value={config.retention_count}
                                    onChange={(event) => updateConfig({ retention_count: Number(event.target.value) })}
                                />
                            </div>
                            <div>
                                <label css={labelStyle}>Max age days</label>
                                <input
                                    css={inputStyle}
                                    type={'number'}
                                    min={1}
                                    max={limits.max_retention_days}
                                    value={config.retention_days || ''}
                                    placeholder={'Off'}
                                    onChange={(event) =>
                                        updateConfig({
                                            retention_days: event.target.value ? Number(event.target.value) : null,
                                        })
                                    }
                                />
                            </div>
                            <div>
                                <label css={labelStyle}>Storage</label>
                                <select
                                    css={inputStyle}
                                    value={config.storage_provider_id || ''}
                                    onChange={(event) =>
                                        updateConfig({
                                            storage_provider_id: event.target.value ? Number(event.target.value) : null,
                                        })
                                    }
                                >
                                    <option value={''}>Default provider</option>
                                    {providers.map((provider) => (
                                        <option key={provider.id} value={provider.id}>
                                            {provider.name} ({provider.driver})
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        <div css={tw`mt-4 grid gap-4 md:grid-cols-3`}>
                            <label css={tw`flex items-center gap-2 text-sm text-neutral-200`}>
                                <input
                                    type={'checkbox'}
                                    checked={limits.force_encrypt || config.encrypt}
                                    disabled={limits.force_encrypt}
                                    onChange={(event) => updateConfig({ encrypt: event.target.checked })}
                                />
                                AES-256 encryption{limits.force_encrypt ? ' required' : ''}
                            </label>
                            <label css={tw`flex items-center gap-2 text-sm text-neutral-200`}>
                                <input
                                    type={'checkbox'}
                                    checked={!!config.notifications?.success}
                                    onChange={(event) =>
                                        updateConfig({ notifications: { ...config.notifications, success: event.target.checked } })
                                    }
                                />
                                Notify on success
                            </label>
                            <label css={tw`flex items-center gap-2 text-sm text-neutral-200`}>
                                <input
                                    type={'checkbox'}
                                    checked={!!config.notifications?.failure}
                                    onChange={(event) =>
                                        updateConfig({ notifications: { ...config.notifications, failure: event.target.checked } })
                                    }
                                />
                                Notify on failure
                            </label>
                        </div>

                        <div css={tw`mt-4`}>
                            <label css={labelStyle}>Webhook URL</label>
                            <input
                                css={inputStyle}
                                value={config.notifications?.webhook_url || ''}
                                placeholder={'https://discord.com/api/webhooks/...'}
                                onChange={(event) =>
                                    updateConfig({ notifications: { ...config.notifications, webhook_url: event.target.value } })
                                }
                            />
                        </div>

                        {(config.notifications?.webhook_url || '').toLowerCase().includes('discord') && (
                            <div css={tw`mt-4 grid gap-4 md:grid-cols-2`}>
                                <label css={tw`flex items-center gap-2 text-sm text-neutral-200`}>
                                    <input
                                        type={'checkbox'}
                                        checked={!!config.notifications?.discord_embed}
                                        onChange={(event) =>
                                            updateConfig({ notifications: { ...config.notifications, discord_embed: event.target.checked } })
                                        }
                                    />
                                    Send Discord embed
                                </label>
                                <label css={tw`flex items-center gap-2 text-sm text-neutral-200`}>
                                    <input
                                        type={'checkbox'}
                                        checked={!!config.notifications?.attach_backup}
                                        onChange={(event) =>
                                            updateConfig({ notifications: { ...config.notifications, attach_backup: event.target.checked } })
                                        }
                                    />
                                    Attach compressed backup
                                </label>
                            </div>
                        )}

                        <div css={tw`mt-4`}>
                            <label css={labelStyle}>Databases</label>
                            <div css={tw`grid gap-2 md:grid-cols-2`}>
                                {databases.map((database) => (
                                    <label
                                        key={database.id}
                                        css={tw`flex min-w-0 items-center gap-3 rounded border border-neutral-700 bg-neutral-900 px-3 py-2 text-sm text-neutral-200`}
                                    >
                                        <input
                                            type={'checkbox'}
                                            checked={selectedDatabaseIds.includes(database.id)}
                                            onChange={() => toggleDatabase(database.id)}
                                        />
                                        <FontAwesomeIcon icon={faDatabase} fixedWidth css={tw`text-neutral-500`} />
                                        <span css={tw`min-w-0 truncate`} title={`${database.name} on ${database.host}`}>
                                            {database.name}
                                        </span>
                                    </label>
                                ))}
                            </div>
                        </div>

                        <div css={tw`mt-4 flex items-center justify-between`}>
                            <p css={tw`text-xs text-neutral-500`}>
                                Next run: {config.next_run_at ? new Date(config.next_run_at).toLocaleString() : 'not scheduled'}
                            </p>
                            <Button type={'submit'} disabled={saving}>
                                Save Policy
                            </Button>
                        </div>
                    </form>

                    <div css={panelStyle}>
                        <div css={tw`flex flex-col gap-3 md:flex-row md:items-center md:justify-between`}>
                            <div css={tw`font-semibold text-white`}>
                                <FontAwesomeIcon icon={faCloudUploadAlt} fixedWidth css={tw`mr-2 text-primary-400`} />
                                Storage Providers
                            </div>
                            <Button
                                isSecondary
                                disabled={!limits.allow_server_providers || addableDrivers.length === 0}
                                onClick={() => setProviderFormOpen(!providerFormOpen)}
                            >
                                {providerFormOpen ? 'Close' : 'Add Provider'}
                            </Button>
                        </div>

                        <div css={tw`mt-3 grid gap-2 md:grid-cols-3`}>
                            {providers.map((provider) => (
                                <div key={provider.id} css={tw`rounded border border-neutral-700 bg-neutral-900 p-3 text-sm`}>
                                    <div css={tw`flex items-start justify-between gap-2`}>
                                        <div>
                                            <div css={tw`font-semibold text-neutral-100`}>{provider.name}</div>
                                            <div css={tw`mt-1 text-xs uppercase text-neutral-500`}>
                                                {provider.driver} {provider.is_global ? 'global' : 'server'}
                                            </div>
                                        </div>
                                        {!provider.is_global && (
                                            <button
                                                type={'button'}
                                                css={[
                                                    tw`ml-auto  rounded px-2 py-1 text-xs font-medium transition-colors`,
                                                    deletingProviderId === provider.id
                                                        ? tw`bg-red-600 text-white`
                                                        : tw`bg-neutral-700 text-neutral-300 hover:bg-red-700 hover:text-white`,
                                                ]}
                                                onClick={() => deleteProvider(provider.id)}
                                                title={deletingProviderId === provider.id ? 'Click again to confirm deletion' : 'Remove provider'}
                                            >
                                                {deletingProviderId === provider.id ? (
                                                    'Confirm?'
                                                ) : (
                                                    <FontAwesomeIcon icon={faTrash} fixedWidth />
                                                )}
                                            </button>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>

                        {(!limits.allow_server_providers || addableDrivers.length === 0) && (
                            <p css={tw`mt-3 text-sm text-neutral-400`}>
                                Server-level remote storage providers are disabled by an administrator. Local storage is always available as the built-in default.
                            </p>
                        )}

                        {providerFormOpen && limits.allow_server_providers && addableDrivers.length > 0 && (
                            <form css={tw`mt-4 grid gap-3 md:grid-cols-3`} onSubmit={saveProvider}>
                                <input
                                    css={inputStyle}
                                    placeholder={'Provider name'}
                                    value={providerForm.name}
                                    onChange={(event) => setProviderForm({ ...providerForm, name: event.target.value })}
                                />
                                <select
                                    css={inputStyle}
                                    value={providerForm.driver}
                                    onChange={(event) => setProviderForm({ ...providerForm, driver: event.target.value })}
                                >
                                    {addableDrivers.map((driver) => (
                                        <option key={driver} value={driver}>
                                            {storageDriverLabels[driver] || driver}
                                        </option>
                                    ))}
                                </select>
                                {/* OAuth drivers: one-click connect, no manual fields */}
                                {oauthDrivers.includes(providerForm.driver) && (
                                    <div css={tw`md:col-span-3`}>
                                        <div css={tw`mb-3 text-xs text-neutral-400`}>
                                            Click the button below and authorise the app. Your backups will be uploaded to your own {storageDriverLabels[providerForm.driver]} account — no client ID or secret needed.
                                        </div>
                                        <Button
                                            type={'button'}
                                            disabled={saving || !providerForm.name}
                                            onClick={connectOAuth}
                                        >
                                            {providerForm.oauth_connecting
                                                ? 'Connecting…'
                                                : `Connect ${storageDriverLabels[providerForm.driver]}`}
                                        </Button>
                                    </div>
                                )}

                                {/* WebDAV: URL + credentials */}
                                {providerForm.driver === webdavDriver && (
                                    <>
                                        <input
                                            css={inputStyle}
                                            placeholder={'WebDAV URL (https://dav.example.com/backups)'}
                                            value={providerForm.webdav_url}
                                            onChange={(event) => setProviderForm({ ...providerForm, webdav_url: event.target.value })}
                                        />
                                        <input
                                            css={inputStyle}
                                            placeholder={'Username'}
                                            value={providerForm.username}
                                            onChange={(event) => setProviderForm({ ...providerForm, username: event.target.value })}
                                        />
                                        <input
                                            css={inputStyle}
                                            type={'password'}
                                            placeholder={'Password'}
                                            value={providerForm.password}
                                            onChange={(event) => setProviderForm({ ...providerForm, password: event.target.value })}
                                        />
                                        <div css={tw`md:col-span-3`}>
                                            <Button type={'submit'} disabled={saving || !providerForm.name || !providerForm.webdav_url}>
                                                Save Provider
                                            </Button>
                                        </div>
                                    </>
                                )}

                                {/* S3-like + FTP/FTPS/SFTP: manual credential fields */}
                                {!oauthDrivers.includes(providerForm.driver) &&
                                    providerForm.driver !== webdavDriver &&
                                    !rcloneDrivers.includes(providerForm.driver) && (
                                    <>
                                        <input
                                            css={inputStyle}
                                            placeholder={s3LikeDrivers.includes(providerForm.driver) ? 'Bucket' : 'Root path'}
                                            value={s3LikeDrivers.includes(providerForm.driver) ? providerForm.bucket : providerForm.root}
                                            onChange={(event) =>
                                                setProviderForm(
                                                    s3LikeDrivers.includes(providerForm.driver)
                                                        ? { ...providerForm, bucket: event.target.value }
                                                        : { ...providerForm, root: event.target.value }
                                                )
                                            }
                                        />
                                        <input
                                            css={inputStyle}
                                            placeholder={s3LikeDrivers.includes(providerForm.driver) ? 'Endpoint' : 'Host'}
                                            value={s3LikeDrivers.includes(providerForm.driver) ? providerForm.endpoint : providerForm.host}
                                            onChange={(event) =>
                                                setProviderForm(
                                                    s3LikeDrivers.includes(providerForm.driver)
                                                        ? { ...providerForm, endpoint: event.target.value }
                                                        : { ...providerForm, host: event.target.value }
                                                )
                                            }
                                        />
                                        <input
                                            css={inputStyle}
                                            placeholder={s3LikeDrivers.includes(providerForm.driver) ? 'Access key' : 'Username'}
                                            value={s3LikeDrivers.includes(providerForm.driver) ? providerForm.key : providerForm.username}
                                            onChange={(event) =>
                                                setProviderForm(
                                                    s3LikeDrivers.includes(providerForm.driver)
                                                        ? { ...providerForm, key: event.target.value }
                                                        : { ...providerForm, username: event.target.value }
                                                )
                                            }
                                        />
                                        <input
                                            css={inputStyle}
                                            type={'password'}
                                            placeholder={s3LikeDrivers.includes(providerForm.driver) ? 'Secret key' : 'Password'}
                                            value={s3LikeDrivers.includes(providerForm.driver) ? providerForm.secret : providerForm.password}
                                            onChange={(event) =>
                                                setProviderForm(
                                                    s3LikeDrivers.includes(providerForm.driver)
                                                        ? { ...providerForm, secret: event.target.value }
                                                        : { ...providerForm, password: event.target.value }
                                                )
                                            }
                                        />
                                        <div css={tw`md:col-span-3`}>
                                            <Button type={'submit'} disabled={saving || !providerForm.name}>
                                                Save Provider
                                            </Button>
                                        </div>
                                    </>
                                )}

                                {/* rclone-only drivers: remote + config */}
                                {rcloneDrivers.includes(providerForm.driver) && (
                                    <>
                                        <input
                                            css={inputStyle}
                                            placeholder={'rclone remote:path'}
                                            value={providerForm.remote}
                                            onChange={(event) => setProviderForm({ ...providerForm, remote: event.target.value })}
                                        />
                                        <textarea
                                            css={[inputStyle, tw`h-32 md:col-span-3`]}
                                            placeholder={'Optional encrypted rclone config block'}
                                            value={providerForm.rclone_config}
                                            onChange={(event) => setProviderForm({ ...providerForm, rclone_config: event.target.value })}
                                        />
                                        <div css={tw`md:col-span-3`}>
                                            <Button type={'submit'} disabled={saving || !providerForm.name}>
                                                Save Provider
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </form>
                        )}
                    </div>

                    <div css={panelStyle}>
                        <div css={tw`mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between`}>
                            <div css={tw`font-semibold text-white`}>Backup History</div>
                            <div css={tw`grid gap-2 md:grid-cols-2`}>
                                <div css={tw`relative`}>
                                    <FontAwesomeIcon icon={faSearch} fixedWidth css={tw`absolute left-3 top-3 text-neutral-500`} />
                                    <input
                                        css={[inputStyle, tw`pl-9`]}
                                        placeholder={'Search database or file'}
                                        value={search}
                                        onChange={(event) => setSearch(event.target.value)}
                                    />
                                </div>
                                <select
                                    css={inputStyle}
                                    value={statusFilter}
                                    onChange={(event) => setStatusFilter(event.target.value as 'all' | BackupStatus)}
                                >
                                    <option value={'all'}>All statuses</option>
                                    <option value={'queued'}>Queued</option>
                                    <option value={'running'}>Running</option>
                                    <option value={'success'}>Success</option>
                                    <option value={'failed'}>Failed</option>
                                    <option value={'restoring'}>Restoring</option>
                                    <option value={'restored'}>Restored</option>
                                </select>
                            </div>
                        </div>

                        {filteredRecords.length === 0 ? (
                            <p css={tw`text-sm text-neutral-400`}>No backups match the current filters.</p>
                        ) : (
                            Object.entries(groupedRecords).map(([date, backups]) => (
                                <div key={date} css={tw`mb-5 last:mb-0`}>
                                    <h3 css={tw`mb-2 border-b border-neutral-700 pb-1 text-sm font-semibold uppercase text-neutral-400`}>
                                        {date}
                                    </h3>
                                    <div css={tw`space-y-2`}>
                                        {backups.map((record) => (
                                            <GreyRowBox key={record.uuid}>
                                                <div css={tw`flex w-full flex-col gap-3 md:flex-row md:items-center`}>
                                                    <div css={tw`min-w-0 flex-1`}>
                                                        <div css={tw`flex min-w-0 items-center gap-2`}>
                                                            <FontAwesomeIcon
                                                                icon={record.status === 'failed' ? faExclamationTriangle : faCheckCircle}
                                                                fixedWidth
                                                                css={statusColor(record.status)}
                                                            />
                                                            <p css={tw`truncate font-semibold text-white`} title={record.database_name}>
                                                                {record.database_name}
                                                            </p>
                                                        </div>
                                                        <p css={tw`mt-1 text-xs text-neutral-400`}>
                                                            {formatSize(record.size_bytes)} | {record.stage || record.status} |{' '}
                                                            {record.completed_at
                                                                ? new Date(record.completed_at).toLocaleString()
                                                                : new Date(record.created_at).toLocaleString()}
                                                            {record.encrypted ? ' | encrypted' : ''}
                                                            {record.verified ? ' | verified' : ''}
                                                            {record.safety_backup ? ' | safety backup' : ''}
                                                        </p>
                                                        {(record.status === 'running' || record.status === 'queued' || record.status === 'restoring') && (
                                                            <div css={tw`mt-2 h-1.5 overflow-hidden rounded bg-neutral-700`}>
                                                                <div
                                                                    css={tw`h-full bg-primary-500 transition-all`}
                                                                    style={{ width: `${Math.max(record.progress, record.status === 'queued' ? 5 : 0)}%` }}
                                                                />
                                                            </div>
                                                        )}
                                                        {record.error_message && (
                                                            <div css={tw`mt-2 rounded border border-red-800 bg-red-900 p-2 text-xs text-red-100`}>
                                                                <div css={tw`mb-1 font-semibold`}>Failure details</div>
                                                                <div css={tw`whitespace-pre-wrap text-red-200`}>{record.error_message}</div>
                                                            </div>
                                                        )}
                                                    </div>

                                                    <div css={tw`grid gap-2 md:w-64 md:grid-cols-1`}>
                                                        <select
                                                            css={inputStyle}
                                                            value={restoreTarget[record.uuid] || ''}
                                                            onChange={(event) =>
                                                                setRestoreTarget({
                                                                    ...restoreTarget,
                                                                    [record.uuid]: event.target.value ? Number(event.target.value) : '',
                                                                })
                                                            }
                                                        >
                                                            <option value={''}>Restore target</option>
                                                            {databases.map((database) => (
                                                                <option key={database.id} value={database.id}>
                                                                    {database.name}
                                                                </option>
                                                            ))}
                                                        </select>
                                                    </div>

                                                    <div css={tw`flex gap-2 md:ml-auto`}>
                                                        <a href={record.download_url} target={'_blank'} rel={'noopener noreferrer'}>
                                                            <Button isSecondary disabled={record.status !== 'success' && record.status !== 'restored'}>
                                                                <FontAwesomeIcon icon={faDownload} fixedWidth />
                                                            </Button>
                                                        </a>
                                                        <Button
                                                            color={'red'}
                                                            isSecondary
                                                            disabled={working || (record.status !== 'success' && record.status !== 'restored')}
                                                            onClick={() => queueRestore(record)}
                                                        >
                                                            <FontAwesomeIcon icon={faUndo} fixedWidth />
                                                        </Button>
                                                        <button
                                                            type={'button'}
                                                            css={[
                                                                tw`rounded px-2 py-1 text-xs font-medium transition-colors`,
                                                                deletingBackupUuid === record.uuid
                                                                    ? tw`bg-red-600 text-white`
                                                                    : tw`bg-neutral-700 text-neutral-300 hover:bg-red-700 hover:text-white`,
                                                                (record.status === 'running' || record.status === 'restoring') && tw`opacity-40 cursor-not-allowed`,
                                                            ]}
                                                            onClick={() => deleteBackup(record)}
                                                            disabled={record.status === 'running' || record.status === 'restoring'}
                                                            title={deletingBackupUuid === record.uuid ? 'Click again to confirm deletion' : 'Delete backup'}
                                                        >
                                                            {deletingBackupUuid === record.uuid ? (
                                                                'Confirm?'
                                                            ) : (
                                                                <FontAwesomeIcon icon={faTrash} fixedWidth />
                                                            )}
                                                        </button>
                                                    </div>
                                                </div>
                                            </GreyRowBox>
                                        ))}
                                    </div>
                                </div>
                            ))
                        )}

                        {pagination && pagination.last_page > 1 && (
                            <div css={tw`mt-4 flex items-center justify-between border-t border-neutral-700 pt-4 text-sm text-neutral-400`}>
                                <span>
                                    Page {pagination.current_page} of {pagination.last_page} | {pagination.total} backups
                                </span>
                                <div css={tw`flex gap-2`}>
                                    <Button
                                        isSecondary
                                        disabled={pagination.current_page <= 1}
                                        onClick={() => setPage(Math.max(1, page - 1))}
                                    >
                                        Previous
                                    </Button>
                                    <Button
                                        isSecondary
                                        disabled={pagination.current_page >= pagination.last_page}
                                        onClick={() => setPage(Math.min(pagination.last_page, page + 1))}
                                    >
                                        Next
                                    </Button>
                                </div>
                            </div>
                        )}
                    </div>

                    <div css={tw`text-center text-xs text-neutral-500`}>
                        Made by{' '}
                        <a
                            href={'https://github.com/najuaircrack'}
                            target={'_blank'}
                            rel={'noopener noreferrer'}
                            css={tw`text-primary-400 hover:text-primary-300`}
                        >
                            @najuaircrack
                        </a>
                    </div>
                </div>
            </Fade>
        </>
    );
};

export default MysqlAutoBackupComponent;