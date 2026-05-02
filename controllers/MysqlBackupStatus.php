<?php

namespace Pterodactyl\BlueprintFramework\Extensions\{identifier};

final class MysqlBackupStatus
{
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const SUCCESS = 'success';
    public const FAILED = 'failed';
    public const RESTORING = 'restoring';
    public const RESTORED = 'restored';

    public const TERMINAL = [
        self::SUCCESS,
        self::FAILED,
        self::RESTORED,
    ];
}
