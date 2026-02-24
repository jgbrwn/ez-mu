<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use App\Services\QueueService;
use App\Services\MusicLibrary;

class AppExtension extends AbstractExtension implements GlobalsInterface
{
    private QueueService $queueService;
    private MusicLibrary $musicLibrary;

    public function __construct(QueueService $queueService, MusicLibrary $musicLibrary)
    {
        $this->queueService = $queueService;
        $this->musicLibrary = $musicLibrary;
    }

    public function getGlobals(): array
    {
        return [
            'queue_stats' => $this->queueService->getStats(),
            'library_stats' => $this->musicLibrary->getStats(),
        ];
    }
}
