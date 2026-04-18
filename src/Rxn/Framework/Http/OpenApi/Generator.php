<?php declare(strict_types=1);

namespace Rxn\Framework\Http\OpenApi;

use Rxn\Framework\Http\Attribute\InSet;
use Rxn\Framework\Http\Attribute\Length;
use Rxn\Framework\Http\Attribute\Max;
use Rxn\Framework\Http\Attribute\Min;
use Rxn\Framework\Http\Attribute\Pattern;
use Rxn\Framework\Http\Attribute\Required;
use Rxn\Framework\Http\Binding\RequestDto;

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
 *   that is defined on the class itself (not inherited).
 * - Scalar method parameters become query parameters.
 * - A parameter whose type implements `RequestDto` becomes a
 *   `requestBody` under `application/json`; the DTO's public
 *   properties + validation attributes are emitted as a JSON
 *   Schema, so the spec inherits the same `#[Min]` / `#[Length]` /
 *   `#[Pattern]` / `#[InSet]` metadata that drives runtime
 *   validation. Operations with a body default to POST; the rest
 *   stay on GET.
 * - Object parameters that aren't DTOs are assumed DI-resolved and
 *   omitted.
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
     * DTO class-strings collected across all scanned operations.
     * Each one is emitted once into `components.schemas`, keyed by
     * the short class name.
     *
     * @var array<string, class-string>
     */
    private array $dtoClasses = [];

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
        $this->dtoClasses = [];

        $paths = [];
        foreach ($this->controllers as $class) {
            foreach ($this->extractOperations($class) as $path => $operation) {
                $paths[$path] = array_merge($paths[$path] ?? [], $operation);
            }
        }
        ksort($paths);

        $schemas = self::envelopeSchemas();
        foreach ($this->dtoClasses as $shortName => $dtoClass) {
            $schemas[$shortName] = $this->dtoSchema($dtoClass);
        }
        ksort($schemas);

        $spec = [
            'openapi'    => '3.0.3',
            'info'       => $this->info,
            'paths'      => $paths,
            'components' => ['schemas' => $schemas],
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

            [$queryParams, $dtoClass] = $this->splitParameters($method);
            $httpMethod = $dtoClass !== null ? 'post' : 'get';

            $op = [
                'summary'     => $this->summaryFor($class, $method),
                'operationId' => sprintf('%s.%s.v%d.%d', $controllerSlug, $actionName, $controllerVersion, $actionVersion),
                'tags'        => [$controllerSlug],
                'parameters'  => $queryParams,
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
            ];
            if ($dtoClass !== null) {
                $shortName = (new \ReflectionClass($dtoClass))->getShortName();
                $this->dtoClasses[$shortName] = $dtoClass;
                $op['requestBody'] = [
                    'required' => true,
                    'content'  => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/' . $shortName],
                        ],
                    ],
                ];
            }

            $out[$path] = [$httpMethod => $op];
        }
        return $out;
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: class-string<RequestDto>|null}
     */
    private function splitParameters(\ReflectionMethod $method): array
    {
        $queryParams = [];
        $dtoClass    = null;

        foreach ($method->getParameters() as $p) {
            $type = $p->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();
                if (is_a($typeName, RequestDto::class, true) && $dtoClass === null) {
                    $dtoClass = $typeName;
                }
                // DI-injected deps (and any DTO beyond the first) stay out of the spec.
                continue;
            }
            $queryParams[] = [
                'name'     => $p->getName(),
                'in'       => 'query',
                'required' => !$p->isOptional(),
                'schema'   => ['type' => $this->mapPhpType($type)],
            ];
        }
        return [$queryParams, $dtoClass];
    }

    /**
     * Build a JSON Schema for a RequestDto by walking its public
     * typed properties and mapping each validation attribute to the
     * equivalent OpenAPI keyword.
     *
     * @param class-string<RequestDto> $class
     * @return array<string, mixed>
     */
    private function dtoSchema(string $class): array
    {
        $ref = new \ReflectionClass($class);
        $properties = [];
        $required   = [];

        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $properties[$prop->getName()] = $this->propertySchema($prop);
            if ($this->isPropertyRequired($prop)) {
                $required[] = $prop->getName();
            }
        }

        $schema = [
            'type'       => 'object',
            'properties' => $properties,
        ];
        if ($required !== []) {
            $schema['required'] = $required;
        }
        return $schema;
    }

    /** @return array<string, mixed> */
    private function propertySchema(\ReflectionProperty $prop): array
    {
        $schema = [];
        $type   = $prop->getType();
        if ($type instanceof \ReflectionNamedType) {
            $schema['type'] = $this->mapPhpType($type);
            if ($type->allowsNull()) {
                $schema['nullable'] = true;
            }
        }

        foreach ($prop->getAttributes() as $attr) {
            $instance = $attr->newInstance();
            if ($instance instanceof Min) {
                $schema['minimum'] = $instance->min;
            } elseif ($instance instanceof Max) {
                $schema['maximum'] = $instance->max;
            } elseif ($instance instanceof Length) {
                if ($instance->min !== null) {
                    $schema['minLength'] = $instance->min;
                }
                if ($instance->max !== null) {
                    $schema['maxLength'] = $instance->max;
                }
            } elseif ($instance instanceof Pattern) {
                $schema['pattern'] = self::stripRegexDelimiters($instance->regex);
            } elseif ($instance instanceof InSet) {
                $schema['enum'] = $instance->values;
            }
        }

        if ($prop->hasDefaultValue()) {
            $schema['default'] = $prop->getDefaultValue();
        }
        return $schema;
    }

    private function isPropertyRequired(\ReflectionProperty $prop): bool
    {
        if ($prop->getAttributes(Required::class) !== []) {
            return true;
        }
        $type = $prop->getType();
        if (!$prop->hasDefaultValue() && $type instanceof \ReflectionNamedType && !$type->allowsNull()) {
            return true;
        }
        return false;
    }

    /**
     * Strip `/.../[flags]` (or any matching delimiter pair) from a
     * PHP regex so the inner expression can land in OpenAPI's
     * `pattern` keyword. Returns the input unchanged if nothing
     * matches — callers may supply a bare regex already.
     */
    private static function stripRegexDelimiters(string $regex): string
    {
        if (strlen($regex) < 2) {
            return $regex;
        }
        $first    = $regex[0];
        $lastPos  = strrpos($regex, $first);
        if ($lastPos === false || $lastPos === 0) {
            return $regex;
        }
        return substr($regex, 1, $lastPos - 1);
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
                    'errors'   => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'field'   => ['type' => 'string'],
                                'message' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'x-rxn-elapsed-ms' => ['type' => 'string'],
                ],
            ],
        ];
    }
}
