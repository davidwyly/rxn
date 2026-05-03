<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Concurrency;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Concurrency\HttpClient;
use Rxn\Framework\Concurrency\Scheduler;

/**
 * Unit tests for `HttpClient` that don't require live HTTP.
 *
 * The happy path (real GET against a real server) is covered by
 * `bench/fiber/run.php` which boots backends; here we lock the
 * scheme-rejection guard so a `file://` / `gopher://` / `ftp://`
 * URL never reaches `curl_init`.
 */
final class HttpClientTest extends TestCase
{
    public function testRejectsFileScheme(): void
    {
        // Scheme rejection runs *before* any curl call inside
        // HttpClient::getAsync, so this test deliberately doesn't
        // skip when ext-curl is missing — the guard is what's
        // under test, and it must hold regardless.
        $client = new HttpClient(new Scheduler());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only http/https URLs are allowed.');
        $client->getAsync('file:///etc/hostname');
    }

    public function testRejectsGopherScheme(): void
    {
        $client = new HttpClient(new Scheduler());

        $this->expectException(\InvalidArgumentException::class);
        $client->getAsync('gopher://example.com/');
    }

    public function testRejectsSchemelessUrl(): void
    {
        $client = new HttpClient(new Scheduler());

        $this->expectException(\InvalidArgumentException::class);
        $client->getAsync('example.com/path');
    }

    public function testAcceptsHttpAndHttpsButFailsLaterIfNoCurl(): void
    {
        // We only assert the guard is silent for http/https; the
        // call may then fail on missing curl or network — those
        // are out of scope for this test.
        $client = new HttpClient(new Scheduler());

        if (!extension_loaded('curl')) {
            $this->markTestSkipped('ext-curl not available; cannot exercise the curl branch');
        }

        // No exception from the guard. Promise creation must not
        // throw InvalidArgumentException for these URLs.
        $client->getAsync('http://127.0.0.1:1/will-not-resolve');
        $client->getAsync('https://127.0.0.1:1/will-not-resolve');
        $this->assertTrue(true, 'http/https URLs pass the scheme guard');
    }
}
