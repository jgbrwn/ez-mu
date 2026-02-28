<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use App\Services\QueueService;
use App\Services\MusicLibrary;
use App\Middleware\CsrfMiddleware;
use App\Middleware\AuthMiddleware;

class AppExtension extends AbstractExtension implements GlobalsInterface
{
    private QueueService $queueService;
    private MusicLibrary $musicLibrary;
    private AuthMiddleware $authMiddleware;

    public function __construct(
        QueueService $queueService, 
        MusicLibrary $musicLibrary,
        AuthMiddleware $authMiddleware
    ) {
        $this->queueService = $queueService;
        $this->musicLibrary = $musicLibrary;
        $this->authMiddleware = $authMiddleware;
    }

    public function getGlobals(): array
    {
        return [
            'queue_stats' => $this->queueService->getStats(),
            'library_stats' => $this->musicLibrary->getStats(),
            'csrf_token' => CsrfMiddleware::getToken(),
            'csrf_token_name' => CsrfMiddleware::getTokenName(),
            'auth_enabled' => $this->authMiddleware->isEnabled(),
            'is_authenticated' => $this->authMiddleware->isAuthenticated(),
            'current_user' => $this->authMiddleware->getUsername(),
        ];
    }
}
