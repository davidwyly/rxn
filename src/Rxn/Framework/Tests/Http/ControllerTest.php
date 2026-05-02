<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Container;
use Rxn\Framework\Http\Controller;
use Rxn\Framework\Http\Request;
use Rxn\Framework\Http\Response;

/**
 * Verifies the controller dispatch shape without booting the full
 * framework: we build a Controller subclass via reflection, skip
 * its dependency-heavy constructor, then call trigger().
 */
final class ControllerTest extends TestCase
{
    private function buildController(string $actionName, string $actionVersion, Response $response): Controller
    {
        // Build a Controller subclass without calling the
        // dependency-heavy parent constructor. Properties live on
        // the parent class (private), so reflect against it.
        $controller = (new \ReflectionClass(TestController::class))->newInstanceWithoutConstructor();
        $parent     = new \ReflectionClass(Controller::class);
        $values     = [
            'action_name'    => $actionName,
            'action_version' => $actionVersion,
            'action_method'  => $actionName . '_' . $actionVersion,
            'response'       => $response,
            'container'      => new Container(),
        ];
        foreach ($values as $prop => $value) {
            $p = $parent->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($controller, $value);
        }
        return $controller;
    }

    public function testTriggerInvokesActionAndWrapsResponse(): void
    {
        $response   = new Response();
        $controller = $this->buildController('ping', 'v1', $response);

        $result = $controller->trigger();

        $this->assertSame($response, $result);
        $this->assertSame(['pong' => true], $result->getData());
        $this->assertTrue($result->getMeta()['success']);
        $this->assertSame(200, $result->getMeta()['code']);
    }

    public function testTriggerWrapsExceptionsAsFailureResponses(): void
    {
        $response   = new Response();
        $controller = $this->buildController('boom', 'v1', $response);

        $result = $controller->trigger();

        $this->assertSame(418, $result->getMeta()['code']);
        $this->assertFalse($result->getMeta()['success']);
        $this->assertSame('kaboom', $result->getErrors()['message']);
    }

    public function testTriggerRejectsNonArrayActionResponse(): void
    {
        $response   = new Response();
        $controller = $this->buildController('nonArray', 'v1', $response);

        $result = $controller->trigger();

        $this->assertFalse($result->getMeta()['success']);
        $this->assertStringContainsString('must return an array', $result->getErrors()['message']);
    }

    public function testDtoParameterIsHydratedFromRequest(): void
    {
        $prevGet  = $_GET;
        $prevPost = $_POST;
        try {
            $_GET  = [];
            $_POST = ['name' => 'Widget', 'price' => '499'];

            $response   = new Response();
            $controller = $this->buildController('create', 'v1', $response);
            $result     = $controller->trigger();

            $this->assertSame(200, $result->getMeta()['code']);
            $this->assertSame(['name' => 'Widget', 'price' => 499], $result->getData());
        } finally {
            $_GET = $prevGet;
            $_POST = $prevPost;
        }
    }

    public function testDtoValidationFailuresSurfaceAs422(): void
    {
        $prevGet  = $_GET;
        $prevPost = $_POST;
        try {
            $_GET  = [];
            $_POST = ['name' => '', 'price' => '-1'];

            $response   = new Response();
            $controller = $this->buildController('create', 'v1', $response);
            $result     = $controller->trigger();

            $this->assertSame(422, $result->getMeta()['code']);
            $this->assertSame('Validation failed', $result->getErrors()['message']);

            $pd = $result->toProblemDetails();
            $this->assertArrayHasKey('errors', $pd);
            $fields = array_column($pd['errors'], 'field');
            $this->assertContains('name', $fields);
            $this->assertContains('price', $fields);
        } finally {
            $_GET = $prevGet;
            $_POST = $prevPost;
        }
    }
}

/**
 * Stand-alone controller used by ControllerTest. Defining it at
 * file scope (rather than an anonymous class) lets reflection find
 * the *_v1 methods without fighting PHP's anonymous-class
 * semantics.
 */
class TestController extends Controller
{
    public function ping_v1(): array
    {
        return ['pong' => true];
    }

    public function boom_v1(): array
    {
        throw new \Exception('kaboom', 418);
    }

    public function nonArray_v1()
    {
        return 'not an array';
    }

    public function create_v1(TestCreateProductDto $dto): array
    {
        return ['name' => $dto->name, 'price' => $dto->price];
    }
}

final class TestCreateProductDto implements \Rxn\Framework\Http\Binding\RequestDto
{
    #[\Rxn\Framework\Http\Attribute\Required]
    #[\Rxn\Framework\Http\Attribute\Length(min: 1)]
    public string $name;

    #[\Rxn\Framework\Http\Attribute\Required]
    #[\Rxn\Framework\Http\Attribute\Min(0)]
    public int $price;
}
