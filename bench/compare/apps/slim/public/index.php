<?php declare(strict_types=1);

/**
 * Comparison app for Slim 4. Identical route table to the Rxn /
 * Symfony / raw equivalents:
 *
 *   GET  /hello                → 200 {"hello":"world"}
 *   GET  /products/{id}        → 200 {"id":<int>,"name":"Product <id>"}
 *   POST /products             → 201 or 422 application/problem+json
 *
 * Slim 4 does not ship typed-DTO hydration, so the validation is
 * hand-rolled to match what Rxn's Binder produces from
 * `#[Required]` + `#[Length]` + `#[Min]`.
 */

require __DIR__ . '/../vendor/autoload.php';

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

$app = AppFactory::create();
$app->addBodyParsingMiddleware();

$app->get('/hello', function (Request $req, Response $res) {
    $res->getBody()->write('{"hello":"world"}');
    return $res->withHeader('Content-Type', 'application/json');
});

$app->get('/products/{id:[0-9]+}', function (Request $req, Response $res, array $args) {
    $id = (int) $args['id'];
    $res->getBody()->write(json_encode(['id' => $id, 'name' => "Product $id"]));
    return $res->withHeader('Content-Type', 'application/json');
});

$app->post('/products', function (Request $req, Response $res) {
    $body = $req->getParsedBody();
    $body = is_array($body) ? $body : [];

    $errors = [];
    if (
        !isset($body['name'])
        || !is_string($body['name'])
        || strlen($body['name']) < 1
        || strlen($body['name']) > 100
    ) {
        $errors[] = ['field' => 'name', 'message' => 'is required, 1-100 chars'];
    }
    if (
        !isset($body['price'])
        || !is_numeric($body['price'])
        || (float) $body['price'] < 0
    ) {
        $errors[] = ['field' => 'price', 'message' => 'is required, >= 0'];
    }

    if ($errors !== []) {
        $payload = [
            'type'   => 'about:blank',
            'title'  => 'Validation failed',
            'status' => 422,
            'errors' => $errors,
        ];
        $res->getBody()->write(json_encode($payload));
        return $res
            ->withStatus(422)
            ->withHeader('Content-Type', 'application/problem+json');
    }

    $payload = ['id' => 1, 'name' => $body['name'], 'price' => (float) $body['price']];
    $res->getBody()->write(json_encode($payload));
    return $res
        ->withStatus(201)
        ->withHeader('Content-Type', 'application/json');
});

$app->run();
