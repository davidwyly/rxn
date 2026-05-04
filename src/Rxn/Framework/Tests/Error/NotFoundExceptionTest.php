<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Error;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Error\NotFoundException;
use Rxn\Framework\Error\RequestException;

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

    public function testCanWrapPreviousException(): void
    {
        $previous = new \RuntimeException('upstream cause');
        $e = new NotFoundException('No route matches this request', 404, $previous);
        $this->assertSame($previous, $e->getPrevious());
    }
}
