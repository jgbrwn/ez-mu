<?php
/**
 * EZ-MU - Music Grabber PHP/HTMX Edition
 * Front controller
 */

declare(strict_types=1);

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();  // Don't throw if .env is missing

// Build DI container
$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(__DIR__ . '/../config/container.php');
$container = $containerBuilder->build();

// Create app with container
AppFactory::setContainer($container);
$app = AppFactory::create();

// Add middleware
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->addErrorMiddleware(true, true, true);

// Background job processor - processes queued downloads on page requests
// Essential for shared hosting where cron/workers aren't available
$app->add(new \App\Middleware\BackgroundProcessorMiddleware(
    $container->get(\App\Services\DownloadService::class)
));

// Register routes
(require __DIR__ . '/../config/routes.php')($app);

$app->run();
