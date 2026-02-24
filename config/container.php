<?php
/**
 * DI Container Configuration
 */

use Psr\Container\ContainerInterface;
use Slim\Views\Twig;
use App\Services\Database;
use App\Services\SearchService;
use App\Services\DownloadService;
use App\Services\QueueService;
use App\Services\SettingsService;
use App\Services\MusicLibrary;
use App\Services\RateLimiter;
use App\Services\MonochromeService;
use App\Services\PlaylistService;
use App\Services\MetadataService;
use App\Controllers\SettingsController;

return [
    // Configuration
    'settings' => [
        'app_name' => 'EZ-MU',
        'version' => '1.1.0',
        'music_dir' => __DIR__ . '/../music',
        'data_dir' => __DIR__ . '/../data',
        'db_path' => __DIR__ . '/../data/ez-mu.db',
        'singles_dir' => 'Singles',
    ],

    // Twig View
    Twig::class => function (ContainerInterface $c) {
        $twig = Twig::create(__DIR__ . '/../templates', [
            'cache' => false,
        ]);
        
        $settings = $c->get('settings');
        $twig->getEnvironment()->addGlobal('app_name', $settings['app_name']);
        $twig->getEnvironment()->addGlobal('version', $settings['version']);
        
        return $twig;
    },

    // Database
    Database::class => function (ContainerInterface $c) {
        $settings = $c->get('settings');
        return new Database($settings['db_path']);
    },

    // Rate Limiter (singleton)
    RateLimiter::class => function () {
        return new RateLimiter();
    },

    // Services
    SettingsService::class => function (ContainerInterface $c) {
        return new SettingsService($c->get(Database::class));
    },

    MonochromeService::class => function (ContainerInterface $c) {
        return new MonochromeService(
            $c->get(RateLimiter::class),
            $c->get(SettingsService::class)
        );
    },

    SearchService::class => function (ContainerInterface $c) {
        return new SearchService(
            $c->get(Database::class),
            $c->get(RateLimiter::class),
            $c->get(MonochromeService::class),
            $c->get(SettingsService::class)
        );
    },

    DownloadService::class => function (ContainerInterface $c) {
        $settings = $c->get('settings');
        return new DownloadService(
            $c->get(Database::class),
            $c->get(MonochromeService::class),
            $c->get(RateLimiter::class),
            $c->get(MetadataService::class),
            $settings['music_dir'],
            $settings['singles_dir']
        );
    },

    QueueService::class => function (ContainerInterface $c) {
        return new QueueService($c->get(Database::class));
    },

    MusicLibrary::class => function (ContainerInterface $c) {
        $settings = $c->get('settings');
        return new MusicLibrary(
            $c->get(Database::class),
            $settings['music_dir'],
            $settings['singles_dir']
        );
    },

    PlaylistService::class => function () {
        return new PlaylistService();
    },

    MetadataService::class => function (ContainerInterface $c) {
        return new MetadataService($c->get(Database::class));
    },

    SettingsController::class => function (ContainerInterface $c) {
        return new SettingsController(
            $c->get(\Slim\Views\Twig::class),
            $c->get(SettingsService::class),
            $c->get(MusicLibrary::class),
            $c
        );
    },
];
