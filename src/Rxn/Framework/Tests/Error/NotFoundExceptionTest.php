<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Error;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Error\NotFoundException;
use Rxn\Framework\Error\RequestException;
use Rxn\Framework\Http\Response;

final class NotFoundExceptionTest extends TestCase
{
    public function testDefaultsToHttp404(): void
    {
        $e = new NotFoundException();
        $this->assertSame(404, $e->getCode());
        $this->assertSame('No route matches this request', $e->getMessage());
    }

    public function testCustomMessage(): void
    {
        $e = new NotFoundException('No route matches this request (missing controller)');
        $this->assertSame(404, $e->getCode());
        $this->assertStringContainsString('missing controller', $e->getMessage());
    }

    public function testInheritsFromRequestException(): void
    {
        // Catch blocks expecting `RequestException` should still
        // catch this — keeps the failure-path code uniform.
        $e = new NotFoundException();
        $this->assertInstanceOf(RequestException::class, $e);
    }

    public function testResponseGetErrorCodePicksUp404(): void
    {
        // The plumbing that App::renderFailure depends on:
        // Response::getErrorCode reads $exception->getCode() and
        // a 404 must come through as 404 (not the 500 default).
        $e = new NotFoundException();
        $this->assertSame(404, Response::getErrorCode($e));
    }

    public function testCanWrapPreviousException(): void
    {
        // The convention router wraps Collector's bare \Exception
        // and ContainerException as the cause.
        $previous = new \RuntimeException('parameter version not in GET');
        $e = new NotFoundException('No route matches this request', 404, $previous);
        $this->assertSame($previous, $e->getPrevious());
    }
}
