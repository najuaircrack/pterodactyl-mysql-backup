<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier};

class MysqlBackupVerificationService
{
    public function verify(MysqlBackupRecord $record, MysqlBackupStorageManager $storage): bool
    {
        $stream = $storage->readStream($record->storageProvider, $record->path);
        $context = hash_init('sha256');
        $bytes = 0;

        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);

                if ($chunk === false || $chunk === '') {
                    continue;
                }

                $bytes += strlen($chunk);
                hash_update($context, $chunk);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $checksum = hash_final($context);
        $verified = $bytes === $record->size_bytes && hash_equals((string) $record->checksum_sha256, $checksum);

        $record->forceFill([
            'verified' => $verified,
            'verified_at' => now(),
            'metadata' => array_merge($record->metadata ?: [], [
                'verified_size_bytes' => $bytes,
                'verified_checksum_sha256' => $checksum,
            ]),
        ])->save();

        return $verified;
    }
}
