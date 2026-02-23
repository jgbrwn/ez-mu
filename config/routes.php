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
    
    // Settings
    $app->get('/settings', [SettingsController::class, 'index']);
    $app->post('/settings', [SettingsController::class, 'save']);
    
    // API endpoints for background processing
    $app->get('/api/queue/status', [QueueController::class, 'status']);
    $app->get('/api/config', [SettingsController::class, 'config']);
};
