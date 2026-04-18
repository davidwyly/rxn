<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\OpenApi;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Http\OpenApi\Discoverer;

final class DiscovererTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/rxn-openapi-disco-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/app/Http/Controller/v1', 0777, true);
        mkdir($this->root . '/app/Http/Controller/v2', 0777, true);
        file_put_contents($this->root . '/app/Http/Controller/v1/ProductsController.php', "<?php");
        file_put_contents($this->root . '/app/Http/Controller/v2/OrderItemsController.php', "<?php");
        file_put_contents($this->root . '/app/Http/Controller/v1/README.md', 'readme');
        file_put_contents($this->root . '/app/Http/Controller/v1/Helper.php', "<?php");
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->root);
    }

    public function testDiscoversControllersUnderVersionedSubdirs(): void
    {
        $classes = (new Discoverer($this->root, 'App'))->all();
        $this->assertContains('App\\Http\\Controller\\v1\\ProductsController', $classes);
        $this->assertContains('App\\Http\\Controller\\v2\\OrderItemsController', $classes);
    }

    public function testIgnoresNonControllerFiles(): void
    {
        $classes = (new Discoverer($this->root, 'App'))->all();
        foreach ($classes as $c) {
            $this->assertStringEndsWith('Controller', $c);
        }
        $this->assertNotContains('App\\Http\\Controller\\v1\\Helper', $classes);
    }

    public function testReturnsEmptyWhenControllerDirMissing(): void
    {
        $this->rmdir($this->root);
        $this->assertSame([], (new Discoverer($this->root, 'App'))->all());
    }

    private function rmdir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $it = new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS);
        $rr = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($rr as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($path);
    }
}
