<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\BlueprintFramework\Extensions\{identifier}\DataBaseBackupController;

Route::prefix('/{server}/mysql-backups')->group(function () {
    Route::get('/databases', [DataBaseBackupController::class, 'databases']);
    Route::get('/config', [DataBaseBackupController::class, 'config']);
    Route::put('/config', [DataBaseBackupController::class, 'updateConfig']);
    Route::post('/storage-providers', [DataBaseBackupController::class, 'storeProvider']);
    Route::delete('/storage-providers/delete/{provider}', [DataBaseBackupController::class, 'deleteProvider']);
    Route::get('/', [DataBaseBackupController::class, 'index']);
    Route::post('/', [DataBaseBackupController::class, 'manual']);
    Route::get('/{backup}/download', [DataBaseBackupController::class, 'download'])
        ->name('client.api.extension.mysql-backups.download');
    Route::post('/{backup}/restore', [DataBaseBackupController::class, 'restore']);
    Route::get('/{backup}/logs', [DataBaseBackupController::class, 'logs']);

    // Google Drive OAuth
    Route::post('/google-oauth/prepare', [DataBaseBackupController::class, 'googleOAuthPrepare'])
        ->name('client.api.extension.mysql-backups.google-oauth-prepare');
});

// OAuth callback lives outside the server prefix — Google redirects here
Route::get('/mysql-backups/google-oauth/callback', [DataBaseBackupController::class, 'googleOAuthCallback'])
    ->name('client.api.extension.mysql-backups.google-oauth-callback');