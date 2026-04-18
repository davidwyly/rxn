<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Binding;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Http\Binding\ValidationException;
use Rxn\Framework\Http\Response;

final class ProblemDetailsIntegrationTest extends TestCase
{
    public function testValidationErrorsSurfaceInProblemDetails(): void
    {
        $errors = [
            ['field' => 'name', 'message' => 'is required'],
            ['field' => 'price', 'message' => 'must be >= 0'],
        ];
        $response = (new Response())->getFailure(new ValidationException($errors));

        $pd = $response->toProblemDetails('/api/v1/products');
        $this->assertSame(422, $pd['status']);
        $this->assertSame('Unprocessable Entity', $pd['title']);
        $this->assertSame($errors, $pd['errors']);
        $this->assertSame('/api/v1/products', $pd['instance']);
    }

    public function testRegularErrorsHaveNoErrorsMember(): void
    {
        $response = (new Response())->getFailure(new \Exception('nope', 404));
        $pd = $response->toProblemDetails();
        $this->assertArrayNotHasKey('errors', $pd);
    }

    public function testIsErrorIsTrueForValidationFailure(): void
    {
        $response = (new Response())->getFailure(new ValidationException([['field' => 'x', 'message' => 'bad']]));
        $this->assertTrue($response->isError());
        $this->assertSame(422, $response->getCode());
    }
}
