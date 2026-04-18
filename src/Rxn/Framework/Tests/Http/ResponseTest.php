<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Http\Response;

final class ResponseTest extends TestCase
{
    public function testGetSuccessStoresActionPayload(): void
    {
        $response = new Response();
        $returned = $response->getSuccess(['order_id' => 42]);

        $this->assertSame($response, $returned, 'getSuccess returns $this for chaining');
        $this->assertSame(['order_id' => 42], $response->data);
        $this->assertSame(200, $response->meta['code']);
        $this->assertTrue($response->meta['success']);
        $this->assertTrue($response->isRendered());
        $this->assertSame(Response::DEFAULT_SUCCESS_CODE, $response->getCode());
    }

    public function testGetSuccessWithoutDataFallsBackToStatusText(): void
    {
        $response = new Response();
        $response->getSuccess();
        $this->assertSame('OK', $response->data);
    }

    public function testGetFailurePopulatesErrorFieldsAndCode(): void
    {
        $response  = new Response();
        $exception = new \Exception('something broke', 422);
        $response->getFailure($exception);

        $this->assertSame(422, $response->getCode());
        $this->assertSame(422, $response->meta['code']);
        $this->assertFalse($response->meta['success']);
        $this->assertSame('Unprocessable Entity', $response->errors['type']);
        $this->assertSame('something broke', $response->errors['message']);
    }

    public function testGetFailureDefaultsToInternalServerErrorOnUnknownCode(): void
    {
        $response = new Response();
        $response->getFailure(new \Exception('bang'));
        $this->assertSame(500, $response->getCode());
    }

    public function testGetFailureOmitsTraceInProduction(): void
    {
        $previous = getenv('ENVIRONMENT');
        putenv('ENVIRONMENT=production');
        try {
            $response = new Response();
            $response->getFailure(new \Exception('bang', 500));
            $this->assertArrayNotHasKey('file', $response->errors);
            $this->assertArrayNotHasKey('line', $response->errors);
            $this->assertArrayNotHasKey('trace', $response->errors);
        } finally {
            putenv($previous !== false ? "ENVIRONMENT=$previous" : 'ENVIRONMENT');
        }
    }

    public function testGetFailureIncludesTraceOutsideProduction(): void
    {
        $previous = getenv('ENVIRONMENT');
        putenv('ENVIRONMENT=local');
        try {
            $response = new Response();
            $response->getFailure(new \Exception('bang', 500));
            $this->assertArrayHasKey('file', $response->errors);
            $this->assertArrayHasKey('line', $response->errors);
            $this->assertArrayHasKey('trace', $response->errors);
        } finally {
            putenv($previous !== false ? "ENVIRONMENT=$previous" : 'ENVIRONMENT');
        }
    }

    public function testStripEmptyParamsDropsNullsAndKeepsData(): void
    {
        $response = new Response();
        $response->getSuccess(['id' => 1]);
        $stripped = $response->stripEmptyParams();
        $this->assertArrayHasKey('data', $stripped);
        $this->assertArrayHasKey('meta', $stripped);
        // errors/failure_response/request weren't set on the success path.
        $this->assertArrayNotHasKey('errors', $stripped);
    }

    public function testIsErrorDistinguishesSuccessFromFailure(): void
    {
        $success = (new Response())->getSuccess(['x' => 1]);
        $failure = (new Response())->getFailure(new \Exception('nope', 404));

        $this->assertFalse($success->isError());
        $this->assertTrue($failure->isError());
    }

    public function testToProblemDetailsEmitsRfc7807Shape(): void
    {
        putenv('ENVIRONMENT=production'); // strip dev fields for a clean shape
        try {
            $response = (new Response())->getFailure(new \Exception('row not found', 404));
            $pd = $response->toProblemDetails('/users/42');

            $this->assertSame('about:blank', $pd['type']);
            $this->assertSame('Not Found', $pd['title']);
            $this->assertSame(404, $pd['status']);
            $this->assertSame('row not found', $pd['detail']);
            $this->assertSame('/users/42', $pd['instance']);
        } finally {
            putenv('ENVIRONMENT');
        }
    }

    public function testToProblemDetailsIncludesDebugFieldsOutsideProduction(): void
    {
        putenv('ENVIRONMENT=development');
        try {
            $response = (new Response())->getFailure(new \Exception('row not found', 404));
            $pd = $response->toProblemDetails();

            $this->assertArrayHasKey('x-rxn-file', $pd);
            $this->assertArrayHasKey('x-rxn-line', $pd);
            $this->assertArrayHasKey('x-rxn-trace', $pd);
            $this->assertArrayNotHasKey('instance', $pd);
        } finally {
            putenv('ENVIRONMENT');
        }
    }

    public function testNotModifiedIsHeadersOnly(): void
    {
        $r = Response::notModified();
        $this->assertSame(304, $r->getCode());
        $this->assertTrue($r->isRendered());
        $this->assertNull($r->data);
    }

    public function testToJsonRoundTripsEnvelope(): void
    {
        $response = (new Response())->getSuccess(['id' => 7]);
        $decoded  = json_decode($response->toJson(), true);
        $this->assertSame(['id' => 7], $decoded['data']);
        $this->assertTrue($decoded['meta']['success']);
    }
}
