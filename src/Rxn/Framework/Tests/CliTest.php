<?php declare(strict_types=1);

namespace Rxn\Framework\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Exercises the bin/rxn CLI by shelling out to PHP. Only subcommands
 * that don't need a live database are covered here (help, make:*);
 * migrate is integration-tested separately and needs a real DB.
 *
 * The CLI is always invoked from the real project root so it can
 * find its vendor/autoload.php; scaffolded files are redirected into
 * a per-test sandbox via the RXN_CLI_OUTPUT_ROOT env var (honoured
 * by bin/rxn when set).
 */
final class CliTest extends TestCase
{
    private string $cli;
    private string $projectRoot;
    private string $sandbox;

    protected function setUp(): void
    {
        $this->projectRoot = realpath(__DIR__ . '/../../../../');
        $this->cli         = $this->projectRoot . '/bin/rxn';
        $this->sandbox     = sys_get_temp_dir() . '/rxn-cli-test-' . bin2hex(random_bytes(4));
        mkdir($this->sandbox);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->sandbox);
    }

    /**
     * Recursive delete that refuses to follow symlinks, so nothing
     * outside $dir can ever be reached.
     */
    private function rrmdir(string $dir): void
    {
        if (is_link($dir) || !is_dir($dir)) {
            if (is_link($dir) || is_file($dir)) {
                @unlink($dir);
            }
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path)) {
                @unlink($path);
                continue;
            }
            if (is_dir($path)) {
                $this->rrmdir($path);
                continue;
            }
            @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * @return array{status: int, stdout: string, stderr: string}
     */
    private function runCli(array $args, array $env = []): array
    {
        $cmd = array_merge([PHP_BINARY, $this->cli], $args);
        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        // Merge env with passthrough of the parent environment so PHP
        // can still find composer autoload, etc.
        $mergedEnv = $env === [] ? null : array_merge(getenv(), $env);
        $proc = proc_open($cmd, $desc, $pipes, $this->projectRoot, $mergedEnv);
        $this->assertIsResource($proc);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($proc);
        return ['status' => $status, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    public function testHelpListsCommands(): void
    {
        $result = $this->runCli(['help']);
        $this->assertSame(0, $result['status']);
        $this->assertStringContainsString('make:controller', $result['stdout']);
        $this->assertStringContainsString('make:record', $result['stdout']);
        $this->assertStringContainsString('migrate', $result['stdout']);
    }

    public function testUnknownCommandExitsNonZero(): void
    {
        $result = $this->runCli(['nope']);
        $this->assertNotSame(0, $result['status']);
        $this->assertStringContainsString('Unknown command', $result['stderr']);
    }

    public function testMakeControllerRejectsMissingArgs(): void
    {
        $result = $this->runCli(['make:controller', 'Acme']);
        $this->assertSame(2, $result['status']);
        $this->assertStringContainsString('Usage', $result['stderr']);
    }

    public function testMakeControllerRejectsNonNumericVersion(): void
    {
        $result = $this->runCli(['make:controller', 'Acme', 'Foo', 'abc']);
        $this->assertSame(2, $result['status']);
        $this->assertStringContainsString('Version must be an integer', $result['stderr']);
    }

    public function testMakeControllerCreatesLintCleanFile(): void
    {
        $result = $this->runCli(
            ['make:controller', 'Sandbox\\App', 'Widget', '3'],
            ['RXN_CLI_OUTPUT_ROOT' => $this->sandbox]
        );
        $this->assertSame(0, $result['status'], $result['stderr']);

        $generated = $this->sandbox . '/app/Http/Controller/v3/WidgetController.php';
        $this->assertFileExists($generated);

        $contents = file_get_contents($generated);
        $this->assertStringContainsString('namespace Sandbox\\App\\Http\\Controller\\v3;', $contents);
        $this->assertStringContainsString('class WidgetController', $contents);

        // php -l should accept the generated file.
        exec(escapeshellcmd(PHP_BINARY) . ' -l ' . escapeshellarg($generated), $lintOutput, $lintStatus);
        $this->assertSame(0, $lintStatus, implode("\n", $lintOutput));
    }

    public function testMakeControllerRefusesToOverwrite(): void
    {
        $env = ['RXN_CLI_OUTPUT_ROOT' => $this->sandbox];

        $first = $this->runCli(['make:controller', 'Sandbox\\App', 'Widget', '3'], $env);
        $this->assertSame(0, $first['status']);

        $second = $this->runCli(['make:controller', 'Sandbox\\App', 'Widget', '3'], $env);
        $this->assertSame(3, $second['status']);
        $this->assertStringContainsString('already exists', $second['stderr']);
    }

    public function testOpenapiEmitsJsonToStdout(): void
    {
        // Populate a sandbox with one controller + one action, then
        // point `openapi` at it via --root / --ns so we don't depend
        // on whatever happens to live in app/Http/Controller.
        $controllerDir = $this->sandbox . '/app/Http/Controller/v1';
        mkdir($controllerDir, 0777, true);
        file_put_contents(
            $controllerDir . '/PingController.php',
            "<?php declare(strict_types=1);\n"
            . "namespace Sandbox\\Http\\Controller\\v1;\n"
            . "class PingController { public function ping_v1(int \$id): array { return []; } }\n"
        );

        $result = $this->runCli([
            'openapi',
            '--ns=Sandbox',
            '--root=' . $this->sandbox,
            '--title=Sandbox API',
            '--version=9.9.9',
        ]);
        $this->assertSame(0, $result['status'], $result['stderr']);

        $spec = json_decode($result['stdout'], true);
        $this->assertIsArray($spec);
        $this->assertSame('Sandbox API', $spec['info']['title']);
        $this->assertSame('9.9.9', $spec['info']['version']);
        $this->assertArrayHasKey('/v1.1/ping/ping', $spec['paths']);
    }

    public function testOpenapiWritesToFileWithOutFlag(): void
    {
        mkdir($this->sandbox . '/app/Http/Controller/v1', 0777, true);
        file_put_contents(
            $this->sandbox . '/app/Http/Controller/v1/PingController.php',
            "<?php declare(strict_types=1);\n"
            . "namespace Sandbox\\Http\\Controller\\v1;\n"
            . "class PingController { public function ping_v1(): array { return []; } }\n"
        );

        $outPath = $this->sandbox . '/openapi.json';
        $result  = $this->runCli([
            'openapi',
            '--ns=Sandbox',
            '--root=' . $this->sandbox,
            '--out=' . $outPath,
        ]);
        $this->assertSame(0, $result['status'], $result['stderr']);
        $this->assertFileExists($outPath);

        $spec = json_decode((string)file_get_contents($outPath), true);
        $this->assertIsArray($spec);
        $this->assertArrayHasKey('/v1.1/ping/ping', $spec['paths']);
    }

    public function testMakeRecordCreatesFile(): void
    {
        $result = $this->runCli(
            ['make:record', 'Sandbox\\App', 'Widget', 'widgets'],
            ['RXN_CLI_OUTPUT_ROOT' => $this->sandbox]
        );
        $this->assertSame(0, $result['status'], $result['stderr']);

        $generated = $this->sandbox . '/app/Model/v1/Widget.php';
        $this->assertFileExists($generated);

        $contents = file_get_contents($generated);
        $this->assertStringContainsString("protected \$table = 'widgets';", $contents);
    }
}
