<?php declare(strict_types=1);

/**
 * Comparison app for Rxn. Reaches into the framework's Router +
 * Binder directly so the route table here is identical in shape
 * to the Slim / Symfony / raw equivalents.
 *
 *   GET  /hello                → 200 {"hello":"world"}
 *   GET  /products/{id:int}    → 200 {"id":<int>,"name":"Product <id>"}
 *   POST /products             → 201 with hydrated DTO, or 422
 *                                application/problem+json with field
 *                                errors
 */

require __DIR__ . '/../../../../../vendor/autoload.php';

use Rxn\Framework\Http\Attribute\Length;
use Rxn\Framework\Http\Attribute\Min;
use Rxn\Framework\Http\Attribute\Required;
use Rxn\Framework\Http\Binding\Binder;
use Rxn\Framework\Http\Binding\RequestDto;
use Rxn\Framework\Http\Binding\ValidationException;
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

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$hit    = $router->match($method, $path);

if ($hit === null) {
    http_response_code(404);
    header('Content-Type: application/problem+json');
    echo json_encode([
        'type'   => 'about:blank',
        'title'  => 'Not Found',
        'status' => 404,
    ]);
    return;
}

switch ($hit['handler']) {
    case 'hello':
        header('Content-Type: application/json');
        echo '{"hello":"world"}';
        return;

    case 'show':
        $id = (int) $hit['params']['id'];
        header('Content-Type: application/json');
        echo json_encode(['id' => $id, 'name' => "Product $id"]);
        return;

    case 'create':
        $raw  = file_get_contents('php://input') ?: 'null';
        $body = json_decode($raw, true);
        $body = is_array($body) ? $body : [];

        try {
            $dto = Binder::bind(CreateProduct::class, $body);
        } catch (ValidationException $e) {
            http_response_code(422);
            header('Content-Type: application/problem+json');
            echo json_encode([
                'type'   => 'about:blank',
                'title'  => 'Validation failed',
                'status' => 422,
                'errors' => $e->errors(),
            ]);
            return;
        }

        http_response_code(201);
        header('Content-Type: application/json');
        echo json_encode(['id' => 1, 'name' => $dto->name, 'price' => $dto->price]);
        return;
}
