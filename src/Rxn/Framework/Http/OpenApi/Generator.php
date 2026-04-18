<?php declare(strict_types=1);

namespace Rxn\Framework\Http\OpenApi;

/**
 * Reflection-driven OpenAPI 3.0 spec generator. Given a list of
 * controller class names (FQCN), enumerate each `action_v{N}` method
 * and emit a path + operation describing the Rxn convention URL:
 *
 *     /v{controllerVersion}.{actionVersion}/{controller}/{action}
 *
 * Keep the generator pure — it takes an explicit class list rather
 * than scanning the filesystem — so tests and apps can drive it
 * however they like. `Discoverer` is the optional filesystem half.
 *
 * What lands in the spec:
 * - Every non-static public method matching `/^[a-z][a-z0-9_]*_v\d+$/`
 *   that is defined on the class itself (not inherited), skipping
 *   methods whose parameters can't be trivially described (only
 *   non-object parameters become query params; object params are
 *   assumed DI and omitted).
 * - The native `{data, meta}` envelope as the 200 response schema
 *   and RFC 7807 Problem Details as the default error response —
 *   the same two shapes the framework itself emits.
 *
 * This is a starting point, not an exhaustive contract. Callers that
 * need per-operation detail can post-process the returned array, or
 * override by passing a richer `$info` / `$servers` payload.
 */
final class Generator
{
    /**
     * @param array<string, mixed>             $info    OpenAPI `info` object
     * @param list<array{url: string, description?: string}> $servers
     * @param list<class-string>               $controllers
     */
    public function __construct(
        private array $info = ['title' => 'Rxn API', 'version' => '0.1.0'],
        private array $servers = [],
        private array $controllers = []
    ) {}

    /** @param class-string $class */
    public function addController(string $class): self
    {
        $this->controllers[] = $class;
        return $this;
    }

    /** @return array<string, mixed> */
    public function generate(): array
    {
        $paths = [];
        foreach ($this->controllers as $class) {
            foreach ($this->extractOperations($class) as $path => $operation) {
                $paths[$path] = array_merge($paths[$path] ?? [], $operation);
            }
        }
        ksort($paths);
        $spec = [
            'openapi'    => '3.0.3',
            'info'       => $this->info,
            'paths'      => $paths,
            'components' => ['schemas' => self::envelopeSchemas()],
        ];
        if ($this->servers !== []) {
            $spec['servers'] = $this->servers;
        }
        return $spec;
    }

    /**
     * @param class-string $class
     * @return array<string, array<string, mixed>>
     */
    private function extractOperations(string $class): array
    {
        if (!class_exists($class)) {
            return [];
        }
        $ref = new \ReflectionClass($class);
        [$controllerSlug, $controllerVersion] = $this->parseControllerClass($ref);
        if ($controllerSlug === null) {
            return [];
        }

        $out = [];
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $ref->getName()) {
                continue;
            }
            if ($method->isStatic() || $method->isConstructor() || $method->isDestructor()) {
                continue;
            }
            if (!preg_match('/^([a-z][a-z0-9_]*)_v(\d+)$/', $method->getName(), $m)) {
                continue;
            }
            [$actionName, $actionVersion] = [$m[1], (int)$m[2]];

            $path = sprintf(
                '/v%d.%d/%s/%s',
                $controllerVersion,
                $actionVersion,
                $controllerSlug,
                $actionName
            );
            $operation = [
                'get' => [
                    'summary'     => $this->summaryFor($class, $method),
                    'operationId' => sprintf('%s.%s.v%d.%d', $controllerSlug, $actionName, $controllerVersion, $actionVersion),
                    'tags'        => [$controllerSlug],
                    'parameters'  => $this->parametersFor($method),
                    'responses'   => [
                        '200' => [
                            'description' => 'Success envelope',
                            'content'     => [
                                'application/json' => [
                                    'schema' => ['$ref' => '#/components/schemas/RxnSuccess'],
                                ],
                            ],
                        ],
                        'default' => [
                            'description' => 'RFC 7807 Problem Details',
                            'content'     => [
                                'application/problem+json' => [
                                    'schema' => ['$ref' => '#/components/schemas/ProblemDetails'],
                                ],
                            ],
                        ],
                    ],
                ],
            ];
            $out[$path] = $operation;
        }
        return $out;
    }

    /**
     * @return array{0: ?string, 1: int} [slug, major-version]. Slug is
     *         null when the class isn't a recognised Rxn convention
     *         controller.
     */
    private function parseControllerClass(\ReflectionClass $ref): array
    {
        $name = $ref->getShortName();
        if (!str_ends_with($name, 'Controller') || $name === 'Controller') {
            return [null, 0];
        }
        $slug = $this->slugify(substr($name, 0, -strlen('Controller')));
        if ($slug === '') {
            return [null, 0];
        }
        $ns = $ref->getNamespaceName();
        $parts = explode('\\', $ns);
        $last  = end($parts) ?: '';
        if (!preg_match('/^v(\d+)$/', $last, $m)) {
            return [null, 0];
        }
        return [$slug, (int)$m[1]];
    }

    /** @return list<array<string, mixed>> */
    private function parametersFor(\ReflectionMethod $method): array
    {
        $params = [];
        foreach ($method->getParameters() as $p) {
            $type = $p->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                // injected dependency; not a request parameter
                continue;
            }
            $params[] = [
                'name'     => $p->getName(),
                'in'       => 'query',
                'required' => !$p->isOptional(),
                'schema'   => ['type' => $this->mapPhpType($type)],
            ];
        }
        return $params;
    }

    private function mapPhpType(?\ReflectionType $type): string
    {
        if (!$type instanceof \ReflectionNamedType) {
            return 'string';
        }
        return match ($type->getName()) {
            'int', 'integer'        => 'integer',
            'float', 'double'       => 'number',
            'bool', 'boolean'       => 'boolean',
            'array', 'iterable'     => 'array',
            default                 => 'string',
        };
    }

    private function summaryFor(string $class, \ReflectionMethod $method): string
    {
        $doc = $method->getDocComment();
        if (is_string($doc) && preg_match('#\*\s+([^@\n][^\n]*)#', $doc, $m)) {
            return trim($m[1]);
        }
        return ucfirst(str_replace('_', ' ', explode('_v', $method->getName())[0]));
    }

    private function slugify(string $camel): string
    {
        $snake = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $camel) ?? $camel;
        return strtolower($snake);
    }

    /** @return array<string, array<string, mixed>> */
    private static function envelopeSchemas(): array
    {
        return [
            'RxnSuccess' => [
                'type'       => 'object',
                'required'   => ['data', 'meta'],
                'properties' => [
                    'data' => ['description' => 'Action payload'],
                    'meta' => [
                        'type'       => 'object',
                        'properties' => [
                            'success'    => ['type' => 'boolean'],
                            'code'       => ['type' => 'integer'],
                            'elapsed_ms' => ['type' => 'number'],
                        ],
                    ],
                ],
            ],
            'ProblemDetails' => [
                'type'        => 'object',
                'description' => 'RFC 7807 Problem Details',
                'required'    => ['type', 'title', 'status'],
                'properties'  => [
                    'type'     => ['type' => 'string', 'format' => 'uri'],
                    'title'    => ['type' => 'string'],
                    'status'   => ['type' => 'integer'],
                    'detail'   => ['type' => 'string'],
                    'instance' => ['type' => 'string', 'format' => 'uri-reference'],
                    'x-rxn-elapsed-ms' => ['type' => 'string'],
                ],
            ],
        ];
    }
}
