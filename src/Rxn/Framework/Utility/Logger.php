<?php declare(strict_types=1);

namespace Rxn\Framework\Utility;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

/**
 * Minimal append-only JSON-lines logger. One line per event:
 * ISO-8601 UTC timestamp, level, message, and an optional context
 * array. Implements PSR-3 `LoggerInterface` so it drops into any
 * library that type-hints the standard interface — Monolog
 * adapters, framework integrations, etc. — without an adapter.
 *
 * The PSR-3 level-specific methods (info, error, warning, …)
 * come from `LoggerTrait`; this class only owns `log()`.
 */
class Logger implements LoggerInterface
{
    use LoggerTrait;

    public const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    private string $path;

    public function __construct(string $path)
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException("Log directory unavailable: $directory");
        }
        $this->path = $path;
    }

    /**
     * PSR-3 `log()`. `$level` is `mixed` per the interface, but in
     * practice should be one of the `Psr\Log\LogLevel` constants
     * (which are exactly our `LEVELS`); anything else throws.
     * `$message` may be a `string` or `\Stringable`.
     *
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (!is_string($level) || !in_array($level, self::LEVELS, true)) {
            throw new \InvalidArgumentException("Unknown log level '" . (is_scalar($level) ? (string)$level : get_debug_type($level)) . "'");
        }
        $line = json_encode(
            [
                'ts'      => gmdate('Y-m-d\TH:i:s\Z'),
                'level'   => $level,
                'message' => (string)$message,
                'context' => $context,
            ],
            JSON_UNESCAPED_SLASHES
        );
        if ($line === false) {
            throw new \RuntimeException('Failed to encode log line: ' . json_last_error_msg());
        }
        file_put_contents($this->path, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
