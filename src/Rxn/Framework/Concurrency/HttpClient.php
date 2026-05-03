<?php declare(strict_types=1);

namespace Rxn\Framework\Concurrency;

/**
 * Tiny async HTTP client that submits curl handles to the
 * Scheduler. Synchronous in spirit (call returns a Promise that
 * settles to the body) but never blocks the calling fiber — the
 * fiber yields, the scheduler drives the curl_multi loop, and
 * the fiber resumes when the body arrives.
 *
 * Deliberately tiny:
 *
 *   $client = new HttpClient($scheduler);
 *   $body   = $client->getAsync('http://api.local/x')->wait();
 *
 * For the prototype, we expose the body as a string. A production
 * version would return a PSR-7 ResponseInterface; that's a
 * straight wrap, not a design question.
 */
final class HttpClient
{
    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly int $timeoutMs = 5_000,
    ) {}

    /**
     * Submit a GET request. The returned Promise settles to the
     * response body (status code is currently a TODO; the prototype
     * is about wall-clock parallelism, not response shape).
     *
     * @param array<string, string> $headers
     * @return Promise<string>
     */
    public function getAsync(string $url, array $headers = []): Promise
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new \InvalidArgumentException('Only http/https URLs are allowed.');
        }

        $handle = curl_init();
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $this->headerLines($headers),
            CURLOPT_TIMEOUT_MS     => $this->timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $this->timeoutMs,
        ];
        // CURLOPT_PROTOCOLS / CURLPROTO_* are not guaranteed on every libcurl build;
        // guard with defined() to avoid a fatal error when the constants are absent.
        // The parse_url() scheme check above is the primary security guard; these
        // curl options are an additional defence-in-depth layer only.
        if (
            defined('CURLOPT_PROTOCOLS')
            && defined('CURLOPT_REDIR_PROTOCOLS')
            && defined('CURLPROTO_HTTP')
            && defined('CURLPROTO_HTTPS')
        ) {
            $opts[CURLOPT_PROTOCOLS]       = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            $opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        curl_setopt_array($handle, $opts);
        return $this->scheduler->submitCurl($handle);
    }

    /**
     * @param array<string, string> $headers
     * @return list<string>
     */
    private function headerLines(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $out[] = $k . ': ' . $v;
        }
        return $out;
    }
}
