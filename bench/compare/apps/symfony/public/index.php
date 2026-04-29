<?php declare(strict_types=1);

/**
 * Comparison app for a hand-wired Symfony micro-kernel
 * (HttpFoundation + HttpKernel + Routing). No bundles, no DI
 * container — that's deliberate; full Symfony with FrameworkBundle
 * isn't really a "micro" peer to Rxn / Slim.
 *
 *   GET  /hello                → 200 {"hello":"world"}
 *   GET  /products/{id}        → 200 {"id":<int>,"name":"Product <id>"}
 *   POST /products             → 201 or 422 application/problem+json
 */

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\EventListener\RouterListener;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

$routes = new RouteCollection();
$routes->add('hello', new Route('/hello', [
    '_controller' => static fn() => new JsonResponse(['hello' => 'world']),
]));
$routes->add('show', new Route(
    '/products/{id}',
    [
        '_controller' => static function (string $id): JsonResponse {
            $id = (int) $id;
            return new JsonResponse(['id' => $id, 'name' => "Product $id"]);
        },
    ],
    ['id' => '\d+']
));
$routes->add('create', new Route(
    '/products',
    [
        '_controller' => static function (Request $request): JsonResponse {
            $body = json_decode($request->getContent() ?: 'null', true);
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
                return new JsonResponse(
                    [
                        'type'   => 'about:blank',
                        'title'  => 'Validation failed',
                        'status' => 422,
                        'errors' => $errors,
                    ],
                    422,
                    ['Content-Type' => 'application/problem+json']
                );
            }

            return new JsonResponse(
                ['id' => 1, 'name' => $body['name'], 'price' => (float) $body['price']],
                201
            );
        },
    ],
    [],
    [],
    '',
    [],
    ['POST']
));

$request = Request::createFromGlobals();
$context = (new RequestContext())->fromRequest($request);
$matcher = new UrlMatcher($routes, $context);

$dispatcher  = new EventDispatcher();
$requestStack = new RequestStack();
$dispatcher->addSubscriber(new RouterListener($matcher, $requestStack));

$kernel = new HttpKernel(
    $dispatcher,
    new ControllerResolver(),
    $requestStack,
    new ArgumentResolver()
);

$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
