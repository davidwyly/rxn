<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Rxn\Framework\Http\PsrAdapter;

final class PsrAdapterTest extends TestCase
{
    public function testFactoryImplementsPsr17(): void
    {
        $factory = PsrAdapter::factory();
        $this->assertInstanceOf(Psr17Factory::class, $factory);

        // Spot-check one factory method per implemented interface.
        $this->assertNotNull($factory->createRequest('GET', '/x'));
        $this->assertNotNull($factory->createResponse(200));
        $this->assertNotNull($factory->createStream('hi'));
        $this->assertNotNull($factory->createUri('https://example.test/'));
    }

    public function testServerRequestFromGlobalsReadsTheCurrentState(): void
    {
        $_SERVER = array_merge($_SERVER, [
            'REQUEST_METHOD'    => 'POST',
            'REQUEST_URI'       => '/products?page=2',
            'QUERY_STRING'      => 'page=2',
            'HTTP_HOST'         => 'example.test',
            'HTTP_X_CUSTOM'     => 'yes',
            'CONTENT_TYPE'      => 'application/x-www-form-urlencoded',
            'HTTPS'             => 'on',
            'SERVER_PROTOCOL'   => 'HTTP/1.1',
        ]);
        $_GET  = ['page' => '2'];
        $_POST = ['name' => 'widget'];

        $request = PsrAdapter::serverRequestFromGlobals();

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/products', $request->getUri()->getPath());
        $this->assertSame('page=2', $request->getUri()->getQuery());
        $this->assertSame('2', $request->getQueryParams()['page'] ?? null);
        $this->assertSame(['yes'], $request->getHeader('X-Custom'));
        $this->assertSame('widget', $request->getParsedBody()['name'] ?? null);
    }
}
