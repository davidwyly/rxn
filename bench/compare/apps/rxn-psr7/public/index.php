<?php declare(strict_types=1);

/**
 * Comparison app for Rxn driven through the full PSR-7 / PSR-15
 * stack — the experiment branch's whole reason for existing.
 *
 * Identical route table to the other bench apps:
 *
 *   GET  /hello                → 200 {"hello":"world"}
 *   GET  /products/{id:int}    → 200 {"id":<int>,"name":"Product <id>"}
 *   POST /products             → 201 with hydrated DTO, or 422
 *                                application/problem+json with field
 *                                errors
 *
 * Same Router, same Binder as `apps/rxn/`. The *only* thing that
 * differs is ingress and egress: PSR-7 ServerRequest in via
 * PsrAdapter, Psr15Pipeline carries the request, PSR-7 Response
 * out via PsrAdapter::emit. That isolates "what does going
 * PSR-7-native end-to-end actually cost?" — the headline question
 * the framework's design philosophy claims is non-trivial.
 */

require __DIR__ . '/../../../../../vendor/autoload.php';

use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rxn\Framework\Http\Attribute\Length;
use Rxn\Framework\Http\Attribute\Min;
use Rxn\Framework\Http\Attribute\Required;
use Rxn\Framework\Http\Binding\Binder;
use Rxn\Framework\Http\Binding\RequestDto;
use Rxn\Framework\Http\Binding\ValidationException;
use Rxn\Framework\Http\PsrAdapter;
use Rxn\Framework\Http\Psr15Pipeline;
use Rxn\Framework\Http\Router;

final class CreateProduct implements RequestDto
{
    #[Required]
    #[Length(min: 1, max: 100)]
    public string $name;

    #[Required]
    #[Min(0)]
    public float $price;
}

$router = new Router();
$router->get('/hello', 'hello');
$router->get('/products/{id:int}', 'show');
$router->post('/products', 'create');

$terminal = new class ($router) implements RequestHandlerInterface {
    public function __construct(private Router $router) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $method = $request->getMethod();
        $path   = $request->getUri()->getPath();
        $hit    = $this->router->match($method, $path);

        if ($hit === null) {
            return $this->problem(404, 'Not Found');
        }

        return match ($hit['handler']) {
            'hello'  => $this->json(200, '{"hello":"world"}'),
            'show'   => $this->showProduct((int) $hit['params']['id']),
            'create' => $this->createProduct($request),
            default  => $this->problem(500, 'Unrouted handler'),
        };
    }

    private function showProduct(int $id): ResponseInterface
    {
        return $this->json(200, json_encode(['id' => $id, 'name' => "Product $id"]));
    }

    private function createProduct(ServerRequestInterface $request): ResponseInterface
    {
        $raw  = (string) $request->getBody();
        $body = json_decode($raw === '' ? 'null' : $raw, true);
        $body = is_array($body) ? $body : [];

        try {
            $dto = Binder::bind(CreateProduct::class, $body);
        } catch (ValidationException $e) {
            return $this->problem(422, 'Validation failed', $e->errors());
        }

        return $this->json(
            201,
            json_encode(['id' => 1, 'name' => $dto->name, 'price' => $dto->price]),
        );
    }

    private function json(int $status, string $body): ResponseInterface
    {
        return (new Psr7Response($status, ['Content-Type' => 'application/json'], $body));
    }

    private function problem(int $status, string $title, array $errors = []): ResponseInterface
    {
        $payload = ['type' => 'about:blank', 'title' => $title, 'status' => $status];
        if ($errors !== []) {
            $payload['errors'] = $errors;
        }
        return new Psr7Response(
            $status,
            ['Content-Type' => 'application/problem+json'],
            json_encode($payload),
        );
    }
};

$request  = PsrAdapter::serverRequestFromGlobals();
$pipeline = new Psr15Pipeline();
$response = $pipeline->run($request, $terminal);
PsrAdapter::emit($response);
