<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\SettingsService;
use App\Services\Environment;
use App\Services\MusicLibrary;
use Psr\Container\ContainerInterface;

class SettingsController
{
    private Twig $twig;
    private SettingsService $settings;
    private MusicLibrary $library;
    private array $appSettings;

    public function __construct(Twig $twig, SettingsService $settings, MusicLibrary $library, ContainerInterface $container)
    {
        $this->twig = $twig;
        $this->settings = $settings;
        $this->library = $library;
        $this->appSettings = $container->get('settings');
    }

    public function index(Request $request, Response $response): Response
    {
        $settings = $this->settings->getAll();
        $environment = Environment::getSummary();

        return $this->twig->render($response, 'settings.twig', [
            'settings' => $settings,
            'environment' => $environment,
        ]);
    }

    public function save(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        
        $allowedSettings = ['convert_to_flac', 'organize_by_artist', 'theme', 'youtube_enabled', 'enable_musicbrainz', 'autoplay_next'];
        $updates = [];
        
        foreach ($allowedSettings as $key) {
            if (isset($data[$key])) {
                $updates[$key] = $data[$key];
            }
        }

        // Handle checkboxes
        foreach (['convert_to_flac', 'organize_by_artist', 'youtube_enabled', 'enable_musicbrainz', 'autoplay_next'] as $checkbox) {
            if (!isset($data[$checkbox])) {
                $updates[$checkbox] = '0';
            }
        }

        $this->settings->updateAll($updates);

        // Handle YouTube cookies upload
        if (!empty($data['youtube_cookies'])) {
            $cookiesContent = trim($data['youtube_cookies']);
            if (!empty($cookiesContent)) {
                $this->settings->saveYouTubeCookies($cookiesContent);
            }
        }

        return $this->twig->render($response, 'partials/settings_saved.twig', [
            'message' => 'Settings saved successfully',
        ]);
    }

    public function config(Request $request, Response $response): Response
    {
        $config = [
            'version' => $this->appSettings['version'],
            'app_name' => $this->appSettings['app_name'],
            'settings' => $this->settings->getAll(),
        ];

        $response->getBody()->write(json_encode($config));
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    /**
     * Validate library integrity - check for orphaned records and missing files
     */
    public function validateLibrary(Request $request, Response $response): Response
    {
        $issues = $this->library->validateIntegrity();
        
        return $this->twig->render($response, 'partials/library_validation.twig', [
            'issues' => $issues,
        ]);
    }
    
    /**
     * Fix library integrity issues
     */
    public function fixLibrary(Request $request, Response $response): Response
    {
        $result = $this->library->fixIntegrityIssues();
        
        return $this->twig->render($response, 'partials/library_fixed.twig', [
            'result' => $result,
        ]);
    }
}
