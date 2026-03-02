<?php
/**
 * EZ-MU - Music Grabber PHP/HTMX Edition
 * Front controller
 */

declare(strict_types=1);

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use App\Middleware\ErrorHandlerMiddleware;
use App\Middleware\MethodOverrideMiddleware;

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

// Add middleware (order matters - last added is first executed)
$app->addBodyParsingMiddleware();

// Method override - allows POST with _METHOD=DELETE for shared hosting
// Must be added before routing middleware
$app->add(new MethodOverrideMiddleware());

$app->addRoutingMiddleware();

// Error middleware - use APP_DEBUG from environment (defaults to false for security)
$debug = filter_var($_ENV['APP_DEBUG'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
$errorMiddleware = $app->addErrorMiddleware($debug, true, true);
$errorMiddleware->setDefaultErrorHandler(new ErrorHandlerMiddleware($debug));

// Security headers middleware
$app->add(new SecurityHeadersMiddleware());

// CSRF protection middleware
$app->add($container->get(CsrfMiddleware::class));

// Authentication middleware
$app->add($container->get(AuthMiddleware::class));

// Background job processor - processes queued downloads on page requests
// Essential for shared hosting where cron/workers aren't available
$app->add(new \App\Middleware\BackgroundProcessorMiddleware(
    $container->get(\App\Services\DownloadService::class),
    $container->get(\App\Services\QueueService::class)
));

// Register routes
(require __DIR__ . '/../config/routes.php')($app);

$app->run();
