<?php declare(strict_types=1);

namespace Rxn\Framework\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Thin bridge between PHP superglobals and PSR-7. Exists so apps
 * built on Rxn can opt into the PSR-15 middleware ecosystem without
 * giving up the rest of the framework.
 *
 *   $request  = PsrAdapter::serverRequestFromGlobals();
 *   $response = $pipeline->handle($request);
 *   PsrAdapter::emit($response);
 */
final class PsrAdapter
{
    /**
     * Build a PSR-7 ServerRequest from the current PHP globals.
     */
    public static function serverRequestFromGlobals(): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $creator = new ServerRequestCreator($factory, $factory, $factory, $factory);
        return $creator->fromGlobals();
    }

    /**
     * Emit a PSR-7 Response to the current SAPI: status line,
     * headers, then body. Safe to call from a standard php-fpm
     * worker.
     */
    public static function emit(ResponseInterface $response): void
    {
        if (headers_sent($file, $line)) {
            throw new \RuntimeException("Headers already sent at $file:$line");
        }
        http_response_code($response->getStatusCode());
        foreach ($response->getHeaders() as $name => $values) {
            // Replace any previously-set header with the same name.
            $first = true;
            foreach ($values as $value) {
                header($name . ': ' . $value, $first);
                $first = false;
            }
        }
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        while (!$body->eof()) {
            echo $body->read(8192);
        }
    }

    /**
     * Convenience factory returning Nyholm's PSR-17 factory, which
     * implements every PSR-17 interface (RequestFactory,
     * ResponseFactory, StreamFactory, UploadedFileFactory,
     * UriFactory, ServerRequestFactory).
     */
    public static function factory(): Psr17Factory
    {
        return new Psr17Factory();
    }
}
