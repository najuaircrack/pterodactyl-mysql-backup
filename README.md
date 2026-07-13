# Pterodactyl MySQL Backup Manager

Queue-driven, panel-native MySQL backups for Pterodactyl, packaged as a Blueprint extension.

Made by [@najuaircrack](https://github.com/najuaircrack).

## What It Does

- Runs MySQL backups through Laravel queues, not external cron scripts.
- Creates one queued backup record per database before the worker starts, so manual and scheduled backups are visible immediately.
- Supports per-server backup policies, intervals in minutes, retention limits, quotas, restore safety backups, and manual cooldowns.
- Streams `mysqldump` output directly into compressed `.sql.gz` files.
- Optionally encrypts backups using AES-256-GCM, producing `.sql.gz.enc`.
- Imports and lists legacy local backups from existing backup folders.
- Lets users download, restore, filter, search, and monitor backup progress from the server panel.
- Gives admins global defaults, server-specific limits, provider health checks, audit logs, and backup history.
- Supports local, S3-compatible, FTP, FTPS, SFTP, Google Drive, Dropbox, OneDrive (one-click OAuth), WebDAV (native), and Box, MEGA, pCloud, Yandex Disk, generic rclone (advanced).

## Architecture

The extension is fully panel integrated:

- `MysqlBackupSchedulerService` reconciles due schedules from the database.
- `MysqlBackupQueueService` creates backup records and dispatches database jobs.
- `ProcessMysqlDatabaseBackupJob` runs `mysqldump`, compresses, encrypts when enabled, uploads, verifies, notifies, and enforces retention.
- `MysqlBackupStorageManager` abstracts local, S3-style, FTP/SFTP, native WebDAV, rclone, and one-click OAuth (Google Drive, Dropbox, OneDrive) storage.
- `GoogleDriveOAuthService`, `DropboxOAuthService`, `OneDriveOAuthService` handle OAuth token exchange, refresh, and direct API uploads — no rclone required for these providers.
- `MysqlBackupAdminSettingsService` manages global and per-server admin defaults, including admin-owned OAuth app credentials.
- React server UI handles policy, provider setup, manual backup, restore, download, progress, and history.
- Blade admin UI handles operational controls, provider testing, server limit overrides, and audits.

## Requirements

- Pterodactyl panel with Blueprint, target `beta-2026-01`.
- Working Laravel queue worker, normally `pteroq`.
- `mysqldump` and `mysql` available on the panel host.
- Optional: `rclone` for Box, MEGA, pCloud, Yandex Disk, and generic rclone remotes. **Not required for Google Drive, Dropbox, OneDrive, or WebDAV.**
- Optional: Flysystem FTP/SFTP adapters, installed by `private/install.sh`.

## Installation

Place the extension in your Blueprint extensions directory as `mysqlautobackup`, then run:

```bash
blueprint -build
php artisan migrate --force
php artisan optimize:clear
php artisan queue:restart
systemctl restart pteroq
```

If you use FTP, FTPS, or SFTP storage, install the adapters:

```bash
composer require league/flysystem-ftp league/flysystem-sftp-v3
```

---

## Setting Up One-Click Cloud Storage (Google Drive, Dropbox, OneDrive)

The admin registers **one OAuth app per provider** in the admin settings. Users then click a single "Connect" button — they never see a client ID or secret. Backups upload to each user's own cloud account.

### How It Works

1. **Admin** creates an OAuth app at the provider's developer console (Google Cloud, Dropbox, Azure).
2. **Admin** enters the app's client ID and secret in **Admin → MySQL Auto Backup → One-Click Cloud Apps**.
3. **Users** open their server's MySQL Backups tab, click **Add Provider**, select the provider, and click **Connect {Provider}**.
4. A consent popup opens; the user authorises the app and the popup closes automatically.
5. Backups upload to the user's own cloud storage — no server-side rclone or per-user OAuth apps.

### Redirect URI

Every OAuth app must whitelist this exact redirect URI (shown in the admin panel):

```
https://your-panel-domain/api/client/extensions/mysqlautobackup/mysql-backups/oauth/callback
```

> Replace `your-panel-domain` with your actual panel domain. No trailing slash. Use `https`, not `http`.

### Google Drive Setup

1. Go to [Google Cloud Console](https://console.cloud.google.com) → create or select a project.
2. Enable the **Google Drive API** (APIs & Services → Library).
3. Configure the **OAuth consent screen** (External). Add your email as a test user, or click **Publish App** to allow any Google account.
4. Go to **Credentials → + Create Credentials → OAuth 2.0 Client ID** (Web application).
5. Add the redirect URI above under **Authorized redirect URIs**.
6. Copy the **Client ID** and **Client Secret** into the admin panel's Google Drive section.

> Google shows an "unverified app" warning until you verify the app or publish it. Unverified apps are capped at 100 users.

### Dropbox Setup

1. Go to [Dropbox App Console](https://www.dropbox.com/developers/apps) → **Create app**.
2. Choose **Scoped access** → **Full Dropbox** (or app folder if you prefer).
3. Under **Permissions**, grant: `files.content.write`, `files.content.read`, `files.metadata.write`.
4. Under **Settings**, add the redirect URI above to **Redirect URIs**.
5. Copy the **App key** (client ID) and **App secret** into the admin panel's Dropbox section.

### OneDrive Setup

1. Go to [Azure Portal → App Registrations](https://portal.azure.com/#blade/Microsoft_AAD_RegisteredApps/ApplicationsListBlade) → **New registration**.
2. Under **Authentication**, add the redirect URI above as a **Web** platform redirect URI.
3. Under **API Permissions**, add **Microsoft Graph → Delegated**: `Files.ReadWrite`, `offline_access`.
4. Under **Certificates & secrets**, create a **New client secret** and copy the value.
5. Copy the **Application (client) ID** and the **Client Secret** into the admin panel's OneDrive section.
6. Set **Tenant** to `common` (allows any Microsoft account) or your org's tenant ID to restrict to your org.

### Connecting as a User

1. Open a server in the panel → **MySQL Backups** tab.
2. Click **Add Provider**, select the provider (Google Drive / Dropbox / OneDrive).
3. Enter a name (e.g. `My Drive`) and click **Connect {Provider}**.
4. Authorise in the popup — it closes automatically.
5. Set the provider as the storage target in the backup configuration.

### Retention

Retention works automatically. When a backup is pruned by the retention policy, the extension calls the provider's API to delete the file. No manual cleanup needed.

### Troubleshooting One-Click Cloud

**"redirect_uri_mismatch"** — The redirect URI in the provider's console doesn't match exactly. Copy it from the admin panel's One-Click Cloud Apps section.

**"did not return a refresh token"** — Revoke the app's access in your account settings, then connect again. Google: [myaccount.google.com/permissions](https://myaccount.google.com/permissions). Dropbox: [dropbox.com/account/connected_apps](https://www.dropbox.com/account/connected_apps).

**"admin has not configured this provider"** — The admin hasn't entered the OAuth app credentials yet, or the provider is not in the allowed drivers list.

**Popup is blocked** — Allow popups for your panel domain, then click Connect again.

---

## Environment

```env
MYSQL_BACKUP_LOCAL_ROOT=/var/lib/pterodactyl/backups/databases
MYSQL_BACKUP_MYSQLDUMP_PATH=mysqldump
MYSQL_BACKUP_MYSQL_PATH=mysql
MYSQL_BACKUP_RCLONE_PATH=rclone
MYSQL_BACKUP_ENCRYPTION_KEY=
MYSQL_BACKUP_DUMP_USERNAME=
MYSQL_BACKUP_DUMP_PASSWORD=
MYSQL_BACKUP_DUMP_HOST=
MYSQL_BACKUP_DUMP_IDLE_TIMEOUT=300
MYSQL_BACKUP_DISCORD_MAX_ATTACHMENT_BYTES=26214400
MYSQL_BACKUP_ALLOW_PRIVATE_WEBHOOKS=false
```

Most runtime limits are configured in the admin panel. Environment values are used for binary paths, optional defaults, and security overrides.

## Advanced Storage via rclone (Box, MEGA, pCloud, Yandex Disk)

For providers that don't have a native one-click integration, users supply an rclone config block:

1. Admin enables `Allow users to add server storage providers` and the desired provider (Box, MEGA, pCloud, Yandex Disk, or generic rclone).
2. User creates a remote with rclone on a trusted machine:

```bash
rclone config
rclone config show myremote
```

3. User adds the provider in the panel, sets a remote path like `myremote:pterodactyl/mysql-backups`, and pastes the rclone config block.

> Google Drive, Dropbox, OneDrive, and WebDAV do **not** need rclone — they have native integrations. This section is only for Box, MEGA, pCloud, Yandex Disk, and generic rclone remotes.

The config is stored encrypted in the database and written to a temporary `0600` file only while a job runs.

## MySQL Permissions

If Pterodactyl database users cannot connect from the panel host, configure a dedicated dump user:

```sql
CREATE USER 'ptero_backup'@'PANEL_HOST_OR_IP' IDENTIFIED BY 'strong-password';
GRANT SELECT, SHOW VIEW, TRIGGER, EVENT ON `database_name`.* TO 'ptero_backup'@'PANEL_HOST_OR_IP';
FLUSH PRIVILEGES;
```

## Security Notes

- Database passwords and storage provider credentials are never sent to the frontend.
- Storage provider configs including OAuth tokens are encrypted with Laravel `Crypt`.
- Google Drive / Dropbox / OneDrive OAuth tokens are auto-refreshed server-side. The admin-owned client secret is encrypted and never sent to the frontend.
- Optional backup encryption uses AES-256-GCM.
- Webhook URLs are blocked if they resolve to private or reserved IP ranges unless `MYSQL_BACKUP_ALLOW_PRIVATE_WEBHOOKS=true`.
- Rclone remotes must use named remotes like `gdrive:path`; inline remotes and parent traversal are rejected.
- Generic rclone is disabled by default for new installs.

## Operations

After changing code or rebuilding:

```bash
php artisan optimize:clear
php artisan queue:restart
systemctl restart pteroq
```

Useful checks:

```bash
systemctl status pteroq
php artisan queue:failed
php artisan queue:retry all
```

## Troubleshooting

**`mysqldump: Got error: 1045 Access denied`**

Use a dedicated backup user and ensure the MySQL host allows that user from the panel host IP.

**Manual backups are queued but never complete**

Check `pteroq`, failed Laravel jobs, and that `mysqldump` is available to the panel user.

**Local provider test fails**

```bash
chown -R www-data:www-data /var/lib/pterodactyl/backups/databases
chmod -R 750 /var/lib/pterodactyl/backups/databases
```

**rclone upload fails (Box, MEGA, pCloud, Yandex Disk, generic rclone)**

Confirm `rclone` is installed, the remote name in the path matches the pasted config block, and the config block includes the correct `type` section. Google Drive, Dropbox, OneDrive, and WebDAV do not use rclone.