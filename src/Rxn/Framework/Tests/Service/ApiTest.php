<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Service;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Config;
use Rxn\Framework\Http\Collector;
use Rxn\Framework\Http\Request;
use Rxn\Framework\Service\Api;

/**
 * Api::findController is intentionally a thin pass-through to
 * Request::getControllerRef. The test pins the contract so a future
 * refactor that moves the responsibility doesn't silently change
 * the public shape.
 */
final class ApiTest extends TestCase
{
    public function testFindControllerReturnsRequestControllerRef(): void
    {
        $previousGet       = $_GET;
        $previousNamespace = getenv('APP_NAMESPACE');
        try {
            putenv('APP_NAMESPACE=Sample');
            $_GET = [
                'version'    => 'v1.0',
                'controller' => 'product_catalog',
                'action'     => 'list',
            ];

            $config    = new Config();
            $collector = new Collector($config);
            $request   = new Request($collector, $config);
            $api       = new Api();

            $this->assertSame(
                $request->getControllerRef(),
                $api->findController($request)
            );
            $this->assertSame(
                'Sample\\Http\\Controller\\v1\\Product_CatalogController',
                $api->findController($request)
            );
        } finally {
            $_GET = $previousGet;
            if ($previousNamespace === false) {
                putenv('APP_NAMESPACE');
            } else {
                putenv('APP_NAMESPACE=' . $previousNamespace);
            }
        }
    }
}
