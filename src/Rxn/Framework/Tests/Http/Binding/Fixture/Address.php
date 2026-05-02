<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Binding\Fixture;

use Rxn\Framework\Http\Attribute\Required;
use Rxn\Framework\Http\Binding\RequestDto;

/**
 * Fixture exercising the non-inlinable side-table branch of the
 * Binder. `country` carries a CountryCode attribute with named
 * args; the dump path must reconstruct
 * `new \...\CountryCode(allowed: [...], message: '...')` at the
 * top of the dumped file.
 */
final class Address implements RequestDto
{
    #[Required]
    public string $line1;

    #[Required]
    #[CountryCode(allowed: ['US', 'CA', 'MX'], message: 'must be a North American country')]
    public string $country;
}
