<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\OpenApi;

use PHPUnit\Framework\TestCase;
use Rxn\Framework\Http\OpenApi\SwaggerUi;

final class SwaggerUiTest extends TestCase
{
    public function testHtmlReferencesSpecUrl(): void
    {
        $html = SwaggerUi::html('/openapi.json', 'My API');
        $this->assertStringContainsString('url: "/openapi.json"', $html);
        $this->assertStringContainsString('<title>My API</title>', $html);
    }

    public function testDefaultCdnPullsSwaggerAssets(): void
    {
        $html = SwaggerUi::html('/openapi.json');
        $this->assertStringContainsString('unpkg.com/swagger-ui-dist@5/swagger-ui.css', $html);
        $this->assertStringContainsString('unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js', $html);
    }

    public function testCustomCdnBaseIsRespected(): void
    {
        $html = SwaggerUi::html('/openapi.json', cdnBase: 'https://cdn.example.com/swagger/');
        $this->assertStringContainsString('https://cdn.example.com/swagger/swagger-ui.css', $html);
        $this->assertStringContainsString('https://cdn.example.com/swagger/swagger-ui-bundle.js', $html);
    }

    public function testTitleIsHtmlEscaped(): void
    {
        $html = SwaggerUi::html('/openapi.json', '<script>alert(1)</script>');
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testSpecUrlIsHtmlEscaped(): void
    {
        $html = SwaggerUi::html('/spec?x="&y=1');
        $this->assertStringNotContainsString('"&y=1', $html);
        $this->assertStringContainsString('&quot;', $html);
    }
}
