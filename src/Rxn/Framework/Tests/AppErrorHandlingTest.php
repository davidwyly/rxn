<?php declare(strict_types=1);

namespace Rxn\Framework\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Rxn\Framework\App;

final class AppErrorHandlingTest extends TestCase
{
    public function testRenderEnvironmentErrorsRedactsStartupErrorsInProduction(): void
    {
        $method = (new ReflectionClass(App::class))->getMethod('renderEnvironmentErrors');
        $source = $this->extractMethodSource($method);

        $this->assertStringContainsString('self::isProductionEnvironment()', $source);
        $this->assertStringContainsString("? []", $source);
    }

    public function testAppendEnvironmentErrorDoesNotStoreFileLineInProduction(): void
    {
        $method = (new ReflectionClass(App::class))->getMethod('appendEnvironmentError');
        $source = $this->extractMethodSource($method);

        $this->assertStringContainsString("? ['message' => 'Startup error']", $source);
        $this->assertStringContainsString("'file'    => \$exception->getFile()", $source);
        $this->assertStringContainsString("'line'    => \$exception->getLine()", $source);
    }

    private function extractMethodSource(\ReflectionMethod $method): string
    {
        $file  = file($method->getFileName());
        $start = $method->getStartLine() - 1;
        $end   = $method->getEndLine();
        return implode('', array_slice($file, $start, $end - $start));
    }
}
