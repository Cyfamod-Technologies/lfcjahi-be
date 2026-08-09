<?php

use App\Http\Controllers\Api\PublicDirectoryController;
use App\Http\Controllers\Api\PublicMediaController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/messages/{id}', [PublicMediaController::class, 'share']);

// Keep the public read-only API available even when a production deployment
// does not load routes/api.php. These mirror the routes registered there.
Route::get('/api/media', [PublicMediaController::class, 'index']);
Route::get('/api/media/{id}/download', [PublicMediaController::class, 'download']);
Route::get('/api/districts', [PublicDirectoryController::class, 'index']);
