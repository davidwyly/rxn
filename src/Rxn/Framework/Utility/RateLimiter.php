<?php declare(strict_types=1);

namespace Rxn\Framework\Utility;

/**
 * Minimal fixed-window rate limiter, file-backed so it survives
 * across PHP-FPM workers without adding a Redis dependency. One
 * counter file per key; writes are locked with flock.
 *
 *   $rl = new RateLimiter('/tmp/rxn-rate', limit: 60, window: 60);
 *   if (!$rl->allow($client_ip)) { return 429; }
 *
 * For distributed deployments back this by Redis or similar; the API
 * surface is intentionally small so swapping implementations later
 * is trivial.
 */
class RateLimiter
{
    private string $directory;
    private int    $limit;
    private int    $window;

    public function __construct(string $directory, int $limit, int $window)
    {
        if ($limit < 1 || $window < 1) {
            throw new \InvalidArgumentException('RateLimiter limit and window must be positive integers');
        }
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException("Rate-limit directory unavailable: $directory");
        }
        $this->directory = rtrim($directory, '/');
        $this->limit     = $limit;
        $this->window    = $window;
    }

    /**
     * Record a hit for $key and return true when the caller is still
     * within its quota.
     */
    public function allow(string $key, ?int $now = null): bool
    {
        return $this->hit($key, $now) <= $this->limit;
    }

    /**
     * Record a hit for $key and return the current count in the
     * window. Exposed for tests and for callers that want to surface
     * a remaining-quota header.
     */
    public function hit(string $key, ?int $now = null): int
    {
        $now  = $now ?? time();
        $path = $this->directory . '/' . md5($key);
        $fp   = fopen($path, 'c+');
        if ($fp === false) {
            throw new \RuntimeException("Could not open rate-limit file '$path'");
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                throw new \RuntimeException("Could not lock rate-limit file '$path'");
            }
            $data  = stream_get_contents($fp);
            $state = $data !== '' ? json_decode($data, true) : null;
            if (!is_array($state)
                || !isset($state['start'], $state['count'])
                || ($now - (int)$state['start']) >= $this->window
            ) {
                $state = ['start' => $now, 'count' => 0];
            }
            $state['count'] = (int)$state['count'] + 1;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string)json_encode($state));
            return $state['count'];
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
