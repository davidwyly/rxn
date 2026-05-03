<?php declare(strict_types=1);

namespace Rxn\Framework\Concurrency;

use Rxn\Framework\Http\Middleware\TraceContext;

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

        $headers = self::applyTraceContext($headers);

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

    /**
     * @param array<string, string> $headers
     */
    private static function hasHeaderCaseInsensitive(array $headers, string $name): bool
    {
        $lowered = strtolower($name);
        foreach ($headers as $k => $_) {
            if (strtolower($k) === $lowered) {
                return true;
            }
        }
        return false;
    }

    /**
     * Inject the request-scoped W3C Trace Context (if any) into an
     * outbound headers map. Caller-supplied `traceparent` /
     * `tracestate` keys win — apps that explicitly set their own
     * tracing on a per-call basis aren't overridden. This server
     * advances the parent-id (`withNewParent()`) so the receiving
     * service sees `parent = me`.
     *
     * Public so it can be exercised in isolation by unit tests
     * without spinning up curl. Stateful via `TraceContext::current()`
     * — same per-request scoping as `RequestId`.
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    public static function applyTraceContext(array $headers): array
    {
        $context = TraceContext::current();
        if ($context === null) {
            return $headers;
        }
        if (!self::hasHeaderCaseInsensitive($headers, 'traceparent')) {
            $headers['traceparent'] = $context->withNewParent()->toHeader();
        }
        // Defence in depth: the middleware sanitises tracestate on
        // ingress, but the static slot can be set from non-HTTP
        // entrypoints (CLI jobs, test harnesses) that bypass that
        // path. Strip CTL chars again here before letting the value
        // reach curl's raw `"$k: $v"` header builder, where CRLF
        // would smuggle extra headers into the outbound request.
        $state = TraceContext::currentTraceState();
        if (
            $state !== null
            && !self::hasHeaderCaseInsensitive($headers, 'tracestate')
            && preg_match('/[\x00-\x1f\x7f]/', $state) !== 1
            && strlen($state) <= 512
        ) {
            $headers['tracestate'] = $state;
        }
        return $headers;
    }
}
