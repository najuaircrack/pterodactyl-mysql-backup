#!/bin/bash
set -euo pipefail

# Optional Flysystem adapters used by remote storage providers. The extension does not
# install or register any cron scripts; all backup work is dispatched through Laravel queues.
composer require league/flysystem-ftp league/flysystem-sftp-v3

echo "For Google Drive, OneDrive, Dropbox, Box, Mega, pCloud and other rclone-backed providers, install rclone on the panel host. Users may paste their own encrypted rclone config from the server storage UI."
