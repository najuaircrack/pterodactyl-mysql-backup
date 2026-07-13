#!/bin/bash
set -euo pipefail

# Optional Flysystem adapters for FTP/FTPS/SFTP storage providers.
composer require league/flysystem-ftp league/flysystem-sftp-v3

echo "==> MySQL Auto Backup extension installed."
echo ""
echo "One-click cloud storage (Google Drive, Dropbox, OneDrive):"
echo "  1. Go to Admin → MySQL Auto Backup → One-Click Cloud Apps"
echo "  2. Enter the OAuth app credentials for each provider you want"
echo "  3. Users will see a 'Connect' button — no client ID or secret on their side"
echo ""
echo "WebDAV: native (no extra software). Users enter URL + username + password."
echo ""
echo "Advanced (Box, MEGA, pCloud, Yandex Disk, generic rclone):"
echo "  Install the rclone binary on the panel host if you need these providers."
echo "  MYSQL_BACKUP_RCLONE_PATH=rclone (or set the full path)"
