<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\QueueService;
use App\Services\MusicLibrary;
use App\Services\SettingsService;

class HomeController
{
    private Twig $twig;
    private QueueService $queueService;
    private MusicLibrary $library;
    private SettingsService $settings;

    public function __construct(
        Twig $twig,
        QueueService $queueService,
        MusicLibrary $library,
        SettingsService $settings
    ) {
        $this->twig = $twig;
        $this->queueService = $queueService;
        $this->library = $library;
        $this->settings = $settings;
    }

    public function index(Request $request, Response $response): Response
    {
        $queueStats = $this->queueService->getStats();
        $libraryStats = $this->library->getStats();
        $settings = $this->settings->getAll();

        return $this->twig->render($response, 'home.twig', [
            'queue_stats' => $queueStats,
            'library_stats' => $libraryStats,
            'settings' => $settings,
        ]);
    }

    public function resultsPartial(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'partials/results.twig', [
            'results' => [],
            'message' => 'Search for music to see results',
        ]);
    }
}
