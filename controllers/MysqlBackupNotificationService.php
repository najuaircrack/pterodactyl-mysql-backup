<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier};

use Illuminate\Support\Facades\Http;
use Throwable;

class MysqlBackupNotificationService
{
    public function send(
        MysqlBackupConfiguration $configuration,
        MysqlBackupRecord $record,
        string $event,
        ?string $filePath = null
    ): void {
        $notifications = $configuration->notifications ?: [];
        $url = $notifications['webhook_url'] ?? null;

        if (!$url || empty($notifications[$event])) {
            return;
        }

        try {
            if ($this->isDiscordWebhook($url)) {
                $this->sendDiscord($url, $record, $event, $notifications, $filePath);
                return;
            }

            Http::timeout(5)->post($url, [
                'event' => 'mysql_backup.' . $event,
                'server_id' => $record->server_id,
                'database' => $record->database_name,
                'status' => $record->status,
                'size_bytes' => $record->size_bytes,
                'backup_uuid' => $record->uuid,
                'completed_at' => $record->completed_at?->toIso8601String(),
                'error' => $record->error_message,
            ]);
        } catch (Throwable) {
            // Notification failures should never change backup status.
        }
    }

    private function sendDiscord(string $url, MysqlBackupRecord $record, string $event, array $notifications, ?string $filePath): void
    {
        $payload = !empty($notifications['discord_embed'])
            ? ['embeds' => [$this->discordEmbed($record, $event)]]
            : ['content' => $this->discordContent($record, $event)];
        $attachBackup = !empty($notifications['attach_backup']) && $filePath && file_exists($filePath);
        $maxAttachmentBytes = (int) env('MYSQL_BACKUP_DISCORD_MAX_ATTACHMENT_BYTES', 25 * 1024 * 1024);

        if ($attachBackup && filesize($filePath) <= $maxAttachmentBytes) {
            $handle = fopen($filePath, 'rb');

            try {
                Http::timeout(30)
                    ->attach('file', $handle, basename($filePath))
                    ->post($url, ['payload_json' => json_encode($payload)]);
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }

            return;
        }

        if ($attachBackup && !empty($payload['embeds'][0])) {
            $payload['embeds'][0]['fields'][] = [
                'name' => 'Attachment',
                'value' => 'Skipped because the compressed backup is larger than the Discord upload limit.',
                'inline' => false,
            ];
        }

        Http::timeout(5)->post($url, $payload);
    }

    private function discordEmbed(MysqlBackupRecord $record, string $event): array
    {
        return [
            'title' => 'MySQL Backup ' . ucfirst($event),
            'color' => $record->status === MysqlBackupStatus::SUCCESS ? 5763719 : 15548997,
            'fields' => [
                ['name' => 'Database', 'value' => $record->database_name, 'inline' => true],
                ['name' => 'Status', 'value' => $record->status, 'inline' => true],
                ['name' => 'Size', 'value' => number_format($record->size_bytes) . ' bytes', 'inline' => true],
            ],
            'footer' => ['text' => 'Server ID: ' . $record->server_id],
            'timestamp' => now()->toIso8601String(),
        ];
    }

    private function discordContent(MysqlBackupRecord $record, string $event): string
    {
        return sprintf(
            'MySQL backup %s for `%s` is `%s` (%s bytes).',
            $event,
            $record->database_name,
            $record->status,
            number_format($record->size_bytes)
        );
    }

    private function isDiscordWebhook(string $url): bool
    {
        $url = strtolower($url);

        return str_contains($url, 'discord.com/api/webhooks') || str_contains($url, 'discordapp.com/api/webhooks');
    }
}
