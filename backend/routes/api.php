<?php

use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\InviteAcceptanceController;
use App\Http\Controllers\Api\V1\ManualLibraryController;
use App\Http\Controllers\Api\V1\MediaEventController;
use App\Http\Controllers\Api\V1\PlayerController;
use App\Http\Controllers\Api\V1\StatusController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('web')->group(function (): void {
    Route::get('/status', StatusController::class);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/invites/accept', InviteAcceptanceController::class);

    Route::middleware('auth')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/dashboard', DashboardController::class);
        Route::post('/alerts/{alert}/read', [AlertController::class, 'read']);
        Route::post('/alerts/read-all', [AlertController::class, 'readAll']);
        Route::get('/media-events', [MediaEventController::class, 'index']);
        Route::get('/media-events/recent', [MediaEventController::class, 'recent']);
        Route::get('/library/movies/{movie}', [ManualLibraryController::class, 'showMovie']);
        Route::get('/library/shows/{show}', [ManualLibraryController::class, 'showShow']);
        Route::get('/library/episodes/{episode}', [ManualLibraryController::class, 'showEpisode']);
        Route::post('/library/movies/{movie}/watch', [ManualLibraryController::class, 'watchMovie']);
        Route::post('/library/episodes/{episode}/watch', [ManualLibraryController::class, 'watchEpisode']);
        Route::delete('/library/movies/{movie}/watch', [ManualLibraryController::class, 'unwatchMovie']);
        Route::delete('/library/episodes/{episode}/watch', [ManualLibraryController::class, 'unwatchEpisode']);
        Route::post('/library/movies/{movie}/rating', [ManualLibraryController::class, 'rateMovie']);
        Route::post('/library/shows/{show}/rating', [ManualLibraryController::class, 'rateShow']);
        Route::post('/library/episodes/{episode}/rating', [ManualLibraryController::class, 'rateEpisode']);
        Route::delete('/library/movies/{movie}/rating', [ManualLibraryController::class, 'clearMovieRating']);
        Route::delete('/library/shows/{show}/rating', [ManualLibraryController::class, 'clearShowRating']);
        Route::delete('/library/episodes/{episode}/rating', [ManualLibraryController::class, 'clearEpisodeRating']);
        Route::post('/library/movies/{movie}/notes', [ManualLibraryController::class, 'noteMovie']);
        Route::post('/library/shows/{show}/notes', [ManualLibraryController::class, 'noteShow']);
        Route::post('/library/episodes/{episode}/notes', [ManualLibraryController::class, 'noteEpisode']);
        Route::patch('/library/notes/{note}', [ManualLibraryController::class, 'updateNote']);
        Route::delete('/library/notes/{note}', [ManualLibraryController::class, 'deleteNote']);
        Route::get('/player/sources', [PlayerController::class, 'sources']);
        Route::post('/player/sources', [PlayerController::class, 'storeSource']);
        Route::patch('/player/sources/{source}', [PlayerController::class, 'updateSource']);
        Route::delete('/player/sources/{source}', [PlayerController::class, 'destroySource']);
        Route::get('/player/items', [PlayerController::class, 'items']);
        Route::post('/player/sources/{source}/items', [PlayerController::class, 'storeItem']);
        Route::get('/player/link-targets', [PlayerController::class, 'linkTargets']);
        Route::post('/player/items/{item}/play', [PlayerController::class, 'play']);
        Route::post('/player/items/{item}/link', [PlayerController::class, 'link']);
        Route::delete('/player/items/{item}/link', [PlayerController::class, 'unlink']);
        Route::patch('/player/sessions/{session}', [PlayerController::class, 'updateSession']);
    });
});
