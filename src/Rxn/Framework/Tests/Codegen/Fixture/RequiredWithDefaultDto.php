<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Codegen\Fixture;

use Rxn\Framework\Http\Attribute\Required;
use Rxn\Framework\Http\Binding\RequestDto;

/**
 * `#[Required]` plus a default value. Binder's missing-field branch
 * fires "is required" before reaching the default-application path,
 * so the default is effectively dead — the exporter must not emit
 * `default:` in this case.
 */
final class RequiredWithDefaultDto implements RequestDto
{
    #[Required]
    public string $name = 'unused-default';
}
