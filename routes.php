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
    Route::delete('/{backup}', [DataBaseBackupController::class, 'destroy'])
        ->name('client.api.extension.mysql-backups.destroy');
    Route::get('/{backup}/logs', [DataBaseBackupController::class, 'logs']);

    // One-click OAuth — works for google_drive, dropbox, onedrive
    Route::post('/oauth/{provider}/prepare', [DataBaseBackupController::class, 'oauthPrepare'])
        ->name('client.api.extension.mysql-backups.oauth-prepare')
        ->where('provider', 'google_drive|dropbox|onedrive');

    // Backward-compatible Google Drive alias (pre-existing integrations)
    Route::post('/google-oauth/prepare', [DataBaseBackupController::class, 'googleOAuthPrepare'])
        ->name('client.api.extension.mysql-backups.google-oauth-prepare');
});

// OAuth callback lives outside the server prefix — the provider redirects here
Route::get('/mysql-backups/oauth/callback', [DataBaseBackupController::class, 'oauthCallback'])
    ->name('client.api.extension.mysql-backups.oauth-callback');

// Backward-compatible Google Drive callback alias
Route::get('/mysql-backups/google-oauth/callback', [DataBaseBackupController::class, 'googleOAuthCallback'])
    ->name('client.api.extension.mysql-backups.google-oauth-callback');