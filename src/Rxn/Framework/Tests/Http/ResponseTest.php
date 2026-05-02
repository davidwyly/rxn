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
        $this->assertSame(['order_id' => 42], $response->getData());
        $this->assertSame(200, $response->getMeta()['code']);
        $this->assertTrue($response->getMeta()['success']);
        $this->assertTrue($response->isRendered());
        $this->assertSame(Response::DEFAULT_SUCCESS_CODE, $response->getCode());
    }

    public function testGetSuccessWithoutDataFallsBackToStatusText(): void
    {
        $response = new Response();
        $response->getSuccess();
        $this->assertSame('OK', $response->getData());
    }

    public function testGetFailurePopulatesErrorFieldsAndCode(): void
    {
        $response  = new Response();
        $exception = new \Exception('something broke', 422);
        $response->getFailure($exception);

        $this->assertSame(422, $response->getCode());
        $this->assertSame(422, $response->getMeta()['code']);
        $this->assertFalse($response->getMeta()['success']);
        $this->assertSame('Unprocessable Entity', $response->getErrors()['type']);
        $this->assertSame('something broke', $response->getErrors()['message']);
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
            $this->assertArrayNotHasKey('file', $response->getErrors());
            $this->assertArrayNotHasKey('line', $response->getErrors());
            $this->assertArrayNotHasKey('trace', $response->getErrors());
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
            $this->assertArrayHasKey('file', $response->getErrors());
            $this->assertArrayHasKey('line', $response->getErrors());
            $this->assertArrayHasKey('trace', $response->getErrors());
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
        $this->assertNull($r->getData());
    }

    public function testToJsonRoundTripsEnvelope(): void
    {
        $response = (new Response())->getSuccess(['id' => 7]);
        $decoded  = json_decode($response->toJson(), true);
        $this->assertSame(['id' => 7], $decoded['data']);
        $this->assertTrue($decoded['meta']['success']);
    }

    public static function badExceptionCodes(): iterable
    {
        // Exception codes that previously leaked straight to
        // http_response_code() and produced malformed status lines.
        // After the allow-list these all collapse to 500.
        yield 'default zero'      => [0];
        yield 'arbitrary integer' => [12345];
        yield 'too small'         => [-1];
        yield 'success-range 200' => [200]; // 2xx through getErrorCode is itself a misuse
        yield 'too large'         => [600];
    }

    /** @dataProvider badExceptionCodes */
    public function testGetErrorCodeRejectsCodesOutsideHttpErrorRange(int $code): void
    {
        $exception = new \RuntimeException('whatever', $code);
        $this->assertSame(500, Response::getErrorCode($exception));
    }

    public function testGetErrorCodeAcceptsValidHttpErrorCodes(): void
    {
        // 4xx / 5xx codes are honoured. Sample the boundaries plus
        // a few common values in between.
        foreach ([400, 401, 404, 422, 499, 500, 502, 503, 599] as $code) {
            $this->assertSame(
                $code,
                Response::getErrorCode(new \RuntimeException('x', $code)),
                "expected $code to pass through unchanged"
            );
        }
    }
}
