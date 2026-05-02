<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier};

use Throwable;

class MysqlBackupProviderTestService
{
    public function test(MysqlBackupStorageProvider $provider, MysqlBackupStorageManager $storage): array
    {
        $path = '.pterodactyl-mysql-backup-test-' . now()->format('YmdHis') . '.txt';
        $message = 'ok';
        $status = 'success';

        try {
            if ($provider->driver === 'local') {
                $root = $storage->ensureLocalRoot($provider);
                $message = 'Local root is writable: ' . $root;
            }

            $stream = fopen('php://temp', 'rb+');
            fwrite($stream, 'pterodactyl mysql backup provider test');
            rewind($stream);

            $storage->putStream($provider, $path, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            $read = $storage->readStream($provider, $path);
            $contents = stream_get_contents($read);

            if (is_resource($read)) {
                fclose($read);
            }

            if ($contents !== 'pterodactyl mysql backup provider test') {
                throw new \RuntimeException('Provider write succeeded but read verification failed.');
            }

            $storage->delete($provider, $path);

            if ($provider->driver !== 'local') {
                $message = 'ok';
            }
        } catch (Throwable $exception) {
            $status = 'failed';
            $message = $exception->getMessage();
        }

        $provider->forceFill([
            'last_tested_at' => now(),
            'last_test_status' => $status,
            'last_test_message' => mb_substr($message, 0, 4000),
        ])->save();

        return [
            'status' => $status,
            'message' => $message,
        ];
    }
}
