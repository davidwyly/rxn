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
}
