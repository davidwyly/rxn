<?php declare(strict_types=1);

namespace Rxn\Framework\Utility;

/**
 * Minimal append-only JSON-lines logger. One line per event: ISO-8601
 * UTC timestamp, level, message, and an optional context array. No
 * dependency on psr/log; wrap or replace in app code if a full PSR-3
 * logger is needed.
 */
class Logger
{
    const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    private string $path;

    public function __construct(string $path)
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException("Log directory unavailable: $directory");
        }
        $this->path = $path;
    }

    public function log(string $level, string $message, array $context = []): void
    {
        if (!in_array($level, self::LEVELS, true)) {
            throw new \InvalidArgumentException("Unknown log level '$level'");
        }
        $line = json_encode(
            [
                'ts'      => gmdate('Y-m-d\TH:i:s\Z'),
                'level'   => $level,
                'message' => $message,
                'context' => $context,
            ],
            JSON_UNESCAPED_SLASHES
        );
        if ($line === false) {
            throw new \RuntimeException('Failed to encode log line: ' . json_last_error_msg());
        }
        file_put_contents($this->path, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    public function debug(string $message, array $context = []): void     { $this->log('debug', $message, $context); }
    public function info(string $message, array $context = []): void      { $this->log('info', $message, $context); }
    public function notice(string $message, array $context = []): void    { $this->log('notice', $message, $context); }
    public function warning(string $message, array $context = []): void   { $this->log('warning', $message, $context); }
    public function error(string $message, array $context = []): void     { $this->log('error', $message, $context); }
    public function critical(string $message, array $context = []): void  { $this->log('critical', $message, $context); }
    public function alert(string $message, array $context = []): void     { $this->log('alert', $message, $context); }
    public function emergency(string $message, array $context = []): void { $this->log('emergency', $message, $context); }
}
