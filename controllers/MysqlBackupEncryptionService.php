<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier};

use RuntimeException;

class MysqlBackupEncryptionService
{
    private const MAGIC = "PMBAES1\n";
    private const CIPHER = 'aes-256-gcm';
    private const CHUNK_BYTES = 1048576;

    public function encryptFile(string $sourcePath): string
    {
        $targetPath = $sourcePath . '.enc';
        $in = fopen($sourcePath, 'rb');
        $out = fopen($targetPath, 'wb');

        if (!$in || !$out) {
            throw new RuntimeException('Unable to open backup stream for encryption.');
        }

        fwrite($out, self::MAGIC);

        try {
            while (!feof($in)) {
                $plain = fread($in, self::CHUNK_BYTES);

                if ($plain === '' || $plain === false) {
                    continue;
                }

                $iv = random_bytes(12);
                $tag = '';
                $cipher = openssl_encrypt($plain, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag);

                if ($cipher === false) {
                    throw new RuntimeException('OpenSSL failed to encrypt backup chunk.');
                }

                fwrite($out, pack('N', strlen($cipher)));
                fwrite($out, $iv);
                fwrite($out, $tag);
                fwrite($out, $cipher);
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        return $targetPath;
    }

    public function decryptFile(string $sourcePath): string
    {
        $targetPath = str_ends_with($sourcePath, '.enc')
            ? substr($sourcePath, 0, -4)
            : ($sourcePath . '.plain');
        $in = fopen($sourcePath, 'rb');
        $out = fopen($targetPath, 'wb');

        if (!$in || !$out) {
            throw new RuntimeException('Unable to open backup stream for decryption.');
        }

        try {
            if (fread($in, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new RuntimeException('Backup encryption header is invalid.');
            }

            while (!feof($in)) {
                $lengthBytes = fread($in, 4);

                if ($lengthBytes === '' || $lengthBytes === false) {
                    break;
                }

                if (strlen($lengthBytes) !== 4) {
                    throw new RuntimeException('Encrypted backup chunk length is invalid.');
                }

                $length = unpack('N', $lengthBytes)[1];
                $iv = fread($in, 12);
                $tag = fread($in, 16);
                $cipher = fread($in, $length);
                $plain = openssl_decrypt($cipher, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag);

                if ($plain === false) {
                    throw new RuntimeException('OpenSSL failed to decrypt backup chunk.');
                }

                fwrite($out, $plain);
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        return $targetPath;
    }

    /**
     * Derive the AES-256 encryption key.
     *
     * Uses SHA-256 of the configured key. Admins SHOULD set a dedicated
     * MYSQL_BACKUP_ENCRYPTION_KEY — without it, the Laravel app key is
     * used as a fallback, which means compromising the app key also
     * compromises all encrypted backups. A dedicated key keeps the two
     * secrets cryptographically independent.
     */
    private function key(): string
    {
        $key = (string) env('MYSQL_BACKUP_ENCRYPTION_KEY', config('app.key'));

        if ($key === '' || $key === 'null') {
            throw new RuntimeException(
                'MYSQL_BACKUP_ENCRYPTION_KEY is not set. Set it in your .env file to a random 32+ character string. ' .
                'Run: php -r "echo bin2hex(random_bytes(32));" and add the output as MYSQL_BACKUP_ENCRYPTION_KEY=...'
            );
        }

        return hash('sha256', $key, true);
    }
}
