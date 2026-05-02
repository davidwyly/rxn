<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Codegen\Fixture;

use Rxn\Framework\Http\Attribute\Pattern;
use Rxn\Framework\Http\Attribute\Required;
use Rxn\Framework\Http\Binding\RequestDto;

/**
 * Fixture that uses a Validates-implementing attribute the JS
 * emitter doesn't have a JS twin for. Used to verify the
 * emitter REFUSES to emit silently-divergent code rather than
 * skipping the attribute.
 */
final class UnsupportedDto implements RequestDto
{
    #[Required]
    #[Pattern('/^[a-z]+$/')]
    public string $slug;
}
