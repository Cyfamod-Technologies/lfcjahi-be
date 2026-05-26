<?php

use App\Http\Controllers\Api\Admin\BlogPostController;
use App\Http\Controllers\Api\Admin\CategoryController;
use App\Http\Controllers\Api\Admin\ChunkedUploadController;
use App\Http\Controllers\Api\Admin\DistrictController;
use App\Http\Controllers\Api\Admin\DistrictZoneController;
use App\Http\Controllers\Api\Admin\EventController;
use App\Http\Controllers\Api\Admin\HomeCellController;
use App\Http\Controllers\Api\Admin\MediaItemController;
use App\Http\Controllers\Api\Admin\SpeakerController;
use App\Http\Controllers\Api\Admin\ThemeSettingController;
use App\Http\Controllers\Api\PublicDirectoryController;
use App\Http\Controllers\Api\PublicMediaController;
use Illuminate\Support\Facades\Route;

Route::get('media', [PublicMediaController::class, 'index']);
Route::get('media/{id}/download', [PublicMediaController::class, 'download']);
Route::get('districts', [PublicDirectoryController::class, 'index']);

Route::prefix('admin')->group(function (): void {
    Route::get('categories', [CategoryController::class, 'index']);
    Route::post('categories', [CategoryController::class, 'store']);
    Route::put('categories/{category}', [CategoryController::class, 'update']);
    Route::delete('categories/{category}', [CategoryController::class, 'destroy']);

    Route::post('categories/{category}/subcategories', [CategoryController::class, 'storeSubcategory']);
    Route::put('subcategories/{subcategory}', [CategoryController::class, 'updateSubcategory']);
    Route::delete('subcategories/{subcategory}', [CategoryController::class, 'destroySubcategory']);

    Route::post('upload/chunk', [ChunkedUploadController::class, 'uploadChunk']);
    Route::post('upload/finalize', [ChunkedUploadController::class, 'finalizeUpload']);

    Route::apiResource('media', MediaItemController::class);
    Route::patch('media/{id}/publish', [MediaItemController::class, 'updatePublishStatus']);
    Route::apiResource('speakers', SpeakerController::class)->except(['show']);
    Route::apiResource('events', EventController::class);
    Route::apiResource('blog-posts', BlogPostController::class);
    Route::apiResource('districts', DistrictController::class)->except(['show']);
    Route::post('districts/{district}/zones', [DistrictZoneController::class, 'store']);
    Route::put('district-zones/{zone}', [DistrictZoneController::class, 'update']);
    Route::delete('district-zones/{zone}', [DistrictZoneController::class, 'destroy']);
    Route::post('district-zones/{zone}/cells', [HomeCellController::class, 'store']);
    Route::put('home-cells/{cell}', [HomeCellController::class, 'update']);
    Route::delete('home-cells/{cell}', [HomeCellController::class, 'destroy']);

    Route::get('theme-settings', [ThemeSettingController::class, 'show']);
    Route::put('theme-settings', [ThemeSettingController::class, 'update']);
});
