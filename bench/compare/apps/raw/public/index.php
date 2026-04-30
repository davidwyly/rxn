<?php declare(strict_types=1);

/**
 * Floor baseline — no framework, no container, no PSR-7 wrapper.
 * Same three routes the framework apps expose. Use this number to
 * reason about how much overhead each framework adds on top of
 * "PHP doing the work directly".
 *
 *   GET  /hello                → 200 {"hello":"world"}
 *   GET  /products/{id}        → 200 {"id":<int>,"name":"Product <id>"}
 *   POST /products             → 201 or 422 application/problem+json
 */

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($method === 'GET' && $path === '/hello') {
    header('Content-Type: application/json');
    echo '{"hello":"world"}';
    return;
}

if ($method === 'GET' && preg_match('#^/products/(\d+)$#', $path, $m)) {
    $id = (int) $m[1];
    header('Content-Type: application/json');
    echo json_encode(['id' => $id, 'name' => "Product $id"]);
    return;
}

if ($method === 'POST' && $path === '/products') {
    $body = json_decode(file_get_contents('php://input') ?: 'null', true);
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
        http_response_code(422);
        header('Content-Type: application/problem+json');
        echo json_encode([
            'type'   => 'about:blank',
            'title'  => 'Validation failed',
            'status' => 422,
            'errors' => $errors,
        ]);
        return;
    }

    http_response_code(201);
    header('Content-Type: application/json');
    echo json_encode(['id' => 1, 'name' => $body['name'], 'price' => (float) $body['price']]);
    return;
}

http_response_code(404);
header('Content-Type: application/problem+json');
echo json_encode(['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404]);
