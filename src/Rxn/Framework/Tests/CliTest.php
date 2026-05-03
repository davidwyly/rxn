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

    public function testOpenapiCheckUpdateWritesSnapshotFile(): void
    {
        $this->seedSandboxController();
        $snapshot = $this->sandbox . '/openapi.snapshot.json';

        $result = $this->runCli([
            'openapi:check',
            '--ns=Sandbox',
            '--root=' . $this->sandbox,
            '--snapshot=' . $snapshot,
            '--update',
        ]);

        $this->assertSame(0, $result['status'], $result['stderr']);
        $this->assertFileExists($snapshot);
        $contents = (string) file_get_contents($snapshot);
        $this->assertStringEndsWith("\n", $contents, 'snapshot file must end with newline for clean diffs');
        $decoded = json_decode($contents, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('/v1.1/ping/ping', $decoded['paths']);
    }

    public function testOpenapiCheckExitsZeroWhenSpecMatchesSnapshot(): void
    {
        $this->seedSandboxController();
        $snapshot = $this->sandbox . '/openapi.snapshot.json';

        // Seed the snapshot.
        $this->runCli([
            'openapi:check', '--ns=Sandbox', '--root=' . $this->sandbox,
            '--snapshot=' . $snapshot, '--update',
        ]);

        // Re-run without --update; spec hasn't drifted, exit 0.
        $result = $this->runCli([
            'openapi:check', '--ns=Sandbox', '--root=' . $this->sandbox,
            '--snapshot=' . $snapshot,
        ]);
        $this->assertSame(0, $result['status'], $result['stderr']);
        $this->assertStringContainsString('No drift', $result['stdout']);
    }

    public function testOpenapiCheckExitsTwoOnBreakingChangeWithoutOverride(): void
    {
        // Snapshot a controller with one operation, then remove it
        // so the regenerated spec has fewer paths than the snapshot.
        // The diff classifier marks operation removal as breaking.
        $this->seedSandboxController();
        $snapshot = $this->sandbox . '/openapi.snapshot.json';
        $this->runCli([
            'openapi:check', '--ns=Sandbox', '--root=' . $this->sandbox,
            '--snapshot=' . $snapshot, '--update',
        ]);

        // Remove the controller to trigger an operation-removed
        // breaking change on the next run.
        unlink($this->sandbox . '/app/Http/Controller/v1/PingController.php');
        // Need at least one controller for Discoverer; seed an unrelated one.
        file_put_contents(
            $this->sandbox . '/app/Http/Controller/v1/OtherController.php',
            "<?php declare(strict_types=1);\n"
            . "namespace Sandbox\\Http\\Controller\\v1;\n"
            . "class OtherController { public function other_v1(): array { return []; } }\n"
        );

        $result = $this->runCli([
            'openapi:check', '--ns=Sandbox', '--root=' . $this->sandbox,
            '--snapshot=' . $snapshot,
        ]);
        $this->assertSame(2, $result['status']);
        $this->assertStringContainsString('Breaking changes', $result['stdout']);
        $this->assertStringContainsString('--update', $result['stdout']);
    }

    public function testOpenapiCheckAllowBreakingDowngradesToExitOne(): void
    {
        $this->seedSandboxController();
        $snapshot = $this->sandbox . '/openapi.snapshot.json';
        $this->runCli([
            'openapi:check', '--ns=Sandbox', '--root=' . $this->sandbox,
            '--snapshot=' . $snapshot, '--update',
        ]);

        unlink($this->sandbox . '/app/Http/Controller/v1/PingController.php');
        file_put_contents(
            $this->sandbox . '/app/Http/Controller/v1/OtherController.php',
            "<?php declare(strict_types=1);\n"
            . "namespace Sandbox\\Http\\Controller\\v1;\n"
            . "class OtherController { public function other_v1(): array { return []; } }\n"
        );

        $result = $this->runCli([
            'openapi:check', '--ns=Sandbox', '--root=' . $this->sandbox,
            '--snapshot=' . $snapshot, '--allow-breaking',
        ]);
        // Exit 1 = drift exists, but we allowed it. Diff still printed.
        $this->assertSame(1, $result['status']);
        $this->assertStringContainsString('Breaking changes', $result['stdout']);
    }

    public function testOpenapiCheckExitsTwoWhenSnapshotIsMissing(): void
    {
        $this->seedSandboxController();
        $missing = $this->sandbox . '/does-not-exist.json';

        $result = $this->runCli([
            'openapi:check', '--ns=Sandbox', '--root=' . $this->sandbox,
            '--snapshot=' . $missing,
        ]);
        $this->assertSame(2, $result['status']);
        $this->assertStringContainsString('no snapshot', $result['stderr']);
        $this->assertStringContainsString('--update', $result['stderr']);
    }

    public function testRoutesCheckExitsZeroOnCleanController(): void
    {
        $controllerDir = $this->sandbox . '/app/Http/Controller/v1';
        mkdir($controllerDir, 0777, true);
        // int and alpha don't overlap (digits vs letters), different
        // verbs disambiguate, and `/users/{id:int}/orders` has a
        // distinct segment count from `/users/{id:int}` — so this
        // set is genuinely clean.
        file_put_contents(
            $controllerDir . '/UsersController.php',
            "<?php declare(strict_types=1);\n"
            . "namespace Sandbox\\Http\\Controller\\v1;\n"
            . "use Rxn\\Framework\\Http\\Attribute\\Route;\n"
            . "class UsersController {\n"
            . "  #[Route('GET', '/users/{id:int}')] public function show() {}\n"
            . "  #[Route('POST', '/users/{id:int}')] public function update() {}\n"
            . "  #[Route('GET', '/posts/{name:alpha}')] public function showPost() {}\n"
            . "  #[Route('GET', '/users/{id:int}/orders')] public function userOrders() {}\n"
            . "}\n"
        );

        $result = $this->runCli([
            'routes:check', '--ns=Sandbox', '--root=' . $this->sandbox,
        ]);
        $this->assertSame(0, $result['status'], $result['stderr']);
        $this->assertStringContainsString('No route conflicts', $result['stdout']);
    }

    public function testRoutesCheckExitsOneOnConflict(): void
    {
        $controllerDir = $this->sandbox . '/app/Http/Controller/v1';
        mkdir($controllerDir, 0777, true);
        // /items/{id:int} vs /items/{slug:slug} — both accept "123",
        // so whichever was registered first wins at runtime.
        file_put_contents(
            $controllerDir . '/ItemsController.php',
            "<?php declare(strict_types=1);\n"
            . "namespace Sandbox\\Http\\Controller\\v1;\n"
            . "use Rxn\\Framework\\Http\\Attribute\\Route;\n"
            . "class ItemsController {\n"
            . "  #[Route('GET', '/items/{id:int}')] public function showById() {}\n"
            . "  #[Route('GET', '/items/{slug:slug}')] public function showBySlug() {}\n"
            . "}\n"
        );

        $result = $this->runCli([
            'routes:check', '--ns=Sandbox', '--root=' . $this->sandbox,
        ]);
        $this->assertSame(1, $result['status']);
        $this->assertStringContainsString('Found 1 route conflict', $result['stdout']);
        $this->assertStringContainsString('/items/{id:int}', $result['stdout']);
        $this->assertStringContainsString('/items/{slug:slug}', $result['stdout']);
        $this->assertStringContainsString('runtime-silent', $result['stdout']);
    }

    private function seedSandboxController(): void
    {
        $dir = $this->sandbox . '/app/Http/Controller/v1';
        mkdir($dir, 0777, true);
        file_put_contents(
            $dir . '/PingController.php',
            "<?php declare(strict_types=1);\n"
            . "namespace Sandbox\\Http\\Controller\\v1;\n"
            . "class PingController { public function ping_v1(int \$id): array { return []; } }\n"
        );
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
