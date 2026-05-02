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
- Supports local, S3-compatible, FTP, FTPS, SFTP, Google Drive (OAuth), OneDrive, Dropbox, Box, MEGA, pCloud, Yandex Disk, WebDAV, and generic rclone storage.

## Architecture

The extension is fully panel integrated:

- `MysqlBackupSchedulerService` reconciles due schedules from the database.
- `MysqlBackupQueueService` creates backup records and dispatches database jobs.
- `ProcessMysqlDatabaseBackupJob` runs `mysqldump`, compresses, encrypts when enabled, uploads, verifies, notifies, and enforces retention.
- `MysqlBackupStorageManager` abstracts local, S3-style, FTP/SFTP, rclone, and Google Drive (native OAuth) storage.
- `GoogleDriveOAuthService` handles Google Drive OAuth token exchange, refresh, and direct Drive REST API uploads — no rclone required for Google Drive.
- `MysqlBackupAdminSettingsService` manages global and per-server admin defaults.
- React server UI handles policy, provider setup, manual backup, restore, download, progress, and history.
- Blade admin UI handles operational controls, provider testing, server limit overrides, and audits.

## Requirements

- Pterodactyl panel with Blueprint, target `beta-2026-01`.
- Working Laravel queue worker, normally `pteroq`.
- `mysqldump` and `mysql` available on the panel host.
- Optional: `rclone` for OneDrive, Dropbox, Box, MEGA, pCloud, WebDAV, and similar providers. **Not required for Google Drive.**
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

## Setting Up Google Drive OAuth (No rclone Needed)

Users connect their own Google Drive directly through OAuth. Backups upload straight to their Drive via the Google Drive REST API. No rclone installation or config files required.

### Step 1 — Create a Google Cloud Project

1. Go to [https://console.cloud.google.com](https://console.cloud.google.com) and sign in.
2. Click the project dropdown at the top → **New Project** → give it a name → **Create**.
3. Make sure your new project is selected in the dropdown.

### Step 2 — Enable the Google Drive API

1. In the left sidebar go to **APIs & Services → Library**.
2. Search for **Google Drive API** and click it.
3. Click **Enable**.

### Step 3 — Configure the OAuth Consent Screen

1. Go to **APIs & Services → OAuth consent screen**.
2. Choose **External** → **Create**.
3. Fill in:
   - **App name** — anything, e.g. `Pterodactyl Backup`
   - **User support email** — your email
   - **Developer contact email** — your email
4. Click **Save and Continue** through the Scopes and Test Users steps (no changes needed).
5. Click **Back to Dashboard**.

> If your app stays in **Testing** mode, only Google accounts you add as test users can connect. To allow any Google account, click **Publish App** → **Confirm**.

### Step 4 — Create OAuth 2.0 Credentials

1. Go to **APIs & Services → Credentials**.
2. Click **+ Create Credentials → OAuth 2.0 Client ID**.
3. Set **Application type** to **Web application**.
4. Give it a name, e.g. `Pterodactyl Backup Client`.
5. Under **Authorized redirect URIs**, click **+ Add URI** and enter:

```
https://game.example.in/api/client/extensions/mysqlautobackup/google-oauth/callback
```

> Replace `game.example.in` with your actual panel domain. Do **not** add a trailing slash.

6. Click **Create**.
7. Copy the **Client ID** and **Client Secret** shown — you will need both in the next step.

### Step 5 — Connect Google Drive in the Panel

1. Open your server in the Pterodactyl panel and go to the **MySQL Backups** tab.
2. Scroll to **Storage Providers** and click **Add Provider**.
3. Select **Google Drive** from the driver dropdown.
4. Enter a **Name** for this provider, e.g. `My Google Drive`.
5. Paste your **Client ID** and **Client Secret** from Step 4.
6. Click **Connect Google Drive**.
7. A Google consent popup will open — sign in with the Google account you want backups saved to and click **Allow**.
8. The popup closes automatically and the provider appears in your list.

### Step 6 — Select the Provider for Backups

1. Still on the MySQL Backups tab, go to **Configuration**.
2. Set **Storage Provider** to the Google Drive provider you just added.
3. Save the configuration.

Backups will now upload directly to a folder called `pterodactyl-mysql-backups` in that Google account's Drive, organized as `servers/{server-uuid}/{year}/{month}/{day}/{database}_{time}.sql.gz`.

### Retention

Retention works automatically. When a backup is pruned by the retention count or retention days policy, the extension calls the Google Drive API to delete the file from Drive as well. No manual cleanup needed.

### Troubleshooting Google Drive

**"redirect_uri_mismatch" error from Google**

The redirect URI in your Google Cloud credentials does not match exactly. Check that you entered:
```
https://game.example.in/api/client/extensions/mysqlautobackup/mysql-backups/google-oauth/callback
```
with no trailing slash and using `https`, not `http`.

**"Google did not return a refresh token"**

This happens if the consent screen was already approved once without `prompt=consent`. Go to [https://myaccount.google.com/permissions](https://myaccount.google.com/permissions), remove the app's access, then try connecting again.

**Upload fails after a long backup**

The access token may have expired mid-upload on very large databases. The extension retries once with a fresh token automatically. If it still fails, check your panel PHP timeout settings.

**Popup is blocked**

Allow popups for your panel domain in your browser, then click Connect again.

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

## User-Owned Storage via rclone (non-Google Drive)

For OneDrive, Dropbox, Box, MEGA, pCloud, Yandex Disk, and WebDAV, users supply an rclone config block:

1. Admin enables `Allow users to add server storage providers` and the desired provider.
2. User creates a remote with rclone on a trusted machine:

```bash
rclone config
rclone config show myremote
```

3. User adds the provider in the panel, sets a remote path like `myremote:pterodactyl/mysql-backups`, and pastes the rclone config block.

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
- Google Drive OAuth tokens are auto-refreshed server-side; the client secret never leaves the server after initial setup.
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

**rclone upload fails (non-Google Drive providers)**

Confirm `rclone` is installed, the remote name in the path matches the pasted config block, and the config block includes the correct `type` section.