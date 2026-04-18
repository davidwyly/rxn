<?php declare(strict_types=1);

namespace Rxn\Framework\Http\OpenApi;

/**
 * Tiny helper that renders a Swagger UI shell pointing at the given
 * spec URL. Pairs with `Generator` so any app that ships an
 * OpenAPI JSON endpoint gets interactive docs for free:
 *
 *   $router->get('/openapi.json', fn () => json_encode($generator->generate()));
 *   $router->get('/docs',         fn () => SwaggerUi::html('/openapi.json'));
 *
 * The HTML pulls `swagger-ui-dist` from unpkg by default, so the
 * page is a single self-contained response with no build step.
 * Callers that prefer to self-host the assets pass their own CDN
 * base via `$cdnBase` — everything else composes from that.
 */
final class SwaggerUi
{
    public const DEFAULT_CDN = 'https://unpkg.com/swagger-ui-dist@5';

    public static function html(
        string $specUrl,
        string $title = 'API Docs',
        string $cdnBase = self::DEFAULT_CDN
    ): string {
        $title   = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $specUrl = htmlspecialchars($specUrl, ENT_QUOTES, 'UTF-8');
        $cdn     = htmlspecialchars(rtrim($cdnBase, '/'), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>$title</title>
  <link rel="stylesheet" href="$cdn/swagger-ui.css">
  <style>body { margin: 0; } #swagger-ui { max-width: 1200px; margin: 0 auto; }</style>
</head>
<body>
  <div id="swagger-ui"></div>
  <script src="$cdn/swagger-ui-bundle.js" crossorigin></script>
  <script>
    window.ui = SwaggerUIBundle({
      url: "$specUrl",
      dom_id: "#swagger-ui",
      deepLinking: true
    });
  </script>
</body>
</html>
HTML;
    }
}
