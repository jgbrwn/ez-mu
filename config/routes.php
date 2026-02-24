<?php
/**
 * Application Routes
 */

use Slim\App;
use Slim\Views\Twig;
use App\Controllers\HomeController;
use App\Controllers\SearchController;
use App\Controllers\DownloadController;
use App\Controllers\QueueController;
use App\Controllers\LibraryController;
use App\Controllers\SettingsController;
use App\Controllers\StreamController;
use App\Controllers\ImportController;
use App\Controllers\WatchedController;

return function (App $app) {
    // Main UI routes
    $app->get('/', [HomeController::class, 'index']);
    
    // HTMX partial routes
    $app->get('/partials/results', [HomeController::class, 'resultsPartial']);
    $app->get('/partials/queue', [QueueController::class, 'queuePartial']);
    $app->get('/partials/library', [LibraryController::class, 'libraryPartial']);
    
    // Search API (returns HTMX partials)
    $app->post('/search', [SearchController::class, 'search']);
    
    // Download actions
    $app->post('/download', [DownloadController::class, 'queue']);
    $app->post('/download/process', [DownloadController::class, 'process']);
    
    // Queue management
    $app->get('/queue', [QueueController::class, 'index']);
    $app->post('/queue/{id}/retry', [QueueController::class, 'retry']);
    $app->delete('/queue/{id}', [QueueController::class, 'delete']);
    $app->post('/queue/clear', [QueueController::class, 'clear']);
    
    // Library / Player
    $app->get('/library', [LibraryController::class, 'index']);
    $app->get('/stream/{id}', [StreamController::class, 'stream']);
    $app->post('/library/download', [LibraryController::class, 'downloadSelected']);
    $app->delete('/library/{id}', [LibraryController::class, 'deleteTrack']);
    
    // Import
    $app->get('/import', [ImportController::class, 'index']);
    $app->post('/import/fetch', [ImportController::class, 'fetchPlaylist']);
    $app->post('/import/tracks', [ImportController::class, 'importTracks']);
    $app->post('/import/batch/{id}', [ImportController::class, 'importBatch']);
    
    // Settings
    $app->get('/settings', [SettingsController::class, 'index']);
    $app->post('/settings', [SettingsController::class, 'save']);
    $app->post('/settings/validate-library', [SettingsController::class, 'validateLibrary']);
    $app->post('/settings/fix-library', [SettingsController::class, 'fixLibrary']);
    
    // Watched Playlists
    $app->get('/watched', [WatchedController::class, 'index']);
    $app->post('/watched/add', [WatchedController::class, 'add']);
    $app->get('/watched/{id}', [WatchedController::class, 'view']);
    $app->delete('/watched/{id}', [WatchedController::class, 'delete']);
    $app->post('/watched/{id}/toggle', [WatchedController::class, 'toggle']);
    $app->post('/watched/{id}/refresh', [WatchedController::class, 'refresh']);
    $app->post('/watched/refresh-all', [WatchedController::class, 'refreshAll']);
    $app->post('/watched/{id}/queue', [WatchedController::class, 'queueTracks']);
    $app->post('/watched/{id}/retry', [WatchedController::class, 'retryFailed']);
    $app->post('/watched/{id}/m3u', [WatchedController::class, 'generateM3u']);
    $app->get('/watched/{id}/status', [WatchedController::class, 'status']);
    $app->post('/watched/{id}/queue-batch', [WatchedController::class, 'queueBatch']);

    // API endpoints for background processing
    $app->get('/api/queue/status', [QueueController::class, 'status']);
    $app->get('/api/config', [SettingsController::class, 'config']);
    
    // Cron endpoint - can be called by external cron services (e.g., cron-job.org)
    // Processes multiple jobs per call. Add ?key=YOUR_SECRET for basic auth.
    $app->get('/cron/process', [DownloadController::class, 'cronProcess']);
};
