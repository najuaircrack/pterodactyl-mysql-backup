<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\MysqlBackupAdminController;

Route::middleware(['web', 'auth'])->prefix('/admin/extensions/mysqlautobackup')->group(function () {
    Route::post('/settings', [MysqlBackupAdminController::class, 'settings'])
        ->name('admin.extensions.mysql-backups.settings');
    Route::post('/storage-providers', [MysqlBackupAdminController::class, 'storeProvider'])
        ->name('admin.extensions.mysql-backups.storage-providers.store');
    Route::delete('/storage-providers/{provider}', [MysqlBackupAdminController::class, 'deleteProvider'])
        ->name('admin.extensions.mysql-backups.storage-providers.delete');
    Route::post('/storage-providers/{provider}/test', [MysqlBackupAdminController::class, 'testProvider'])
        ->name('admin.extensions.mysql-backups.storage-providers.test');
});
