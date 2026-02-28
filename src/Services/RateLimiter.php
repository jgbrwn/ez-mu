<?php

namespace App\Services;

/**
 * Simple rate limiter with per-source tracking
 */
class RateLimiter
{
    private array $requests = [];
    private array $lastRequest = [];

    /**
     * Wait if rate limit would be exceeded
     * 
     * @param string $source Source identifier (monochrome, soundcloud, youtube)
     * @param int $maxRequests Maximum requests per window
     * @param int $windowSeconds Window size in seconds
     */
    public function wait(string $source, int $maxRequests, int $windowSeconds): void
    {
        $now = microtime(true);
        
        // Initialize tracking for this source
        if (!isset($this->requests[$source])) {
            $this->requests[$source] = [];
        }

        // Remove old requests outside the window
        $windowStart = $now - $windowSeconds;
        $this->requests[$source] = array_filter(
            $this->requests[$source],
            fn($time) => $time > $windowStart
        );

        // If at limit, wait until oldest request expires
        if (count($this->requests[$source]) >= $maxRequests) {
            $oldestRequest = min($this->requests[$source]);
            $waitTime = ($oldestRequest + $windowSeconds) - $now;
            if ($waitTime > 0) {
                usleep((int)($waitTime * 1000000));
            }
            // Remove the oldest after waiting
            $this->requests[$source] = array_filter(
                $this->requests[$source],
                fn($time) => $time > (microtime(true) - $windowSeconds)
            );
        }

        // Also enforce minimum delay between requests (courteous pacing)
        $minDelays = [
            'monochrome' => 0.5,   // 500ms between Monochrome requests
            'soundcloud' => 1.0,   // 1s between SoundCloud requests  
            'youtube' => 0.5,      // 500ms between YouTube requests
        ];

        $minDelay = $minDelays[$source] ?? 0.3;
        if (isset($this->lastRequest[$source])) {
            $elapsed = $now - $this->lastRequest[$source];
            if ($elapsed < $minDelay) {
                usleep((int)(($minDelay - $elapsed) * 1000000));
            }
        }

        // Record this request
        $this->requests[$source][] = microtime(true);
        $this->lastRequest[$source] = microtime(true);
    }

    /**
     * Check if we can make a request without waiting
     */
    public function canRequest(string $source, int $maxRequests, int $windowSeconds): bool
    {
        $now = microtime(true);
        $windowStart = $now - $windowSeconds;
        
        if (!isset($this->requests[$source])) {
            return true;
        }

        $recentRequests = array_filter(
            $this->requests[$source],
            fn($time) => $time > $windowStart
        );

        return count($recentRequests) < $maxRequests;
    }
    
    /**
     * Rate limit with IP component
     * Combines action-based and IP-based limiting
     * 
     * @param string $action The action being performed
     * @param string $ip Client IP address
     * @param int $maxPerAction Max requests per action per window
     * @param int $maxPerIp Max total requests per IP per window
     * @param int $windowSeconds Window size in seconds
     * @return bool True if allowed, false if rate limited
     */
    public function checkLimit(
        string $action, 
        string $ip, 
        int $maxPerAction = 30, 
        int $maxPerIp = 100,
        int $windowSeconds = 60
    ): bool {
        // Check action-specific limit
        $actionKey = "action:{$action}";
        if (!$this->canRequest($actionKey, $maxPerAction, $windowSeconds)) {
            return false;
        }
        
        // Check IP-based limit
        $ipKey = "ip:{$ip}";
        if (!$this->canRequest($ipKey, $maxPerIp, $windowSeconds)) {
            return false;
        }
        
        // Record both
        $this->recordRequest($actionKey);
        $this->recordRequest($ipKey);
        
        return true;
    }
    
    /**
     * Record a request without waiting
     */
    private function recordRequest(string $source): void
    {
        if (!isset($this->requests[$source])) {
            $this->requests[$source] = [];
        }
        $this->requests[$source][] = microtime(true);
        $this->lastRequest[$source] = microtime(true);
    }
}
