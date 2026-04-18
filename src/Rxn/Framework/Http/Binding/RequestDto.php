<?php declare(strict_types=1);

namespace Rxn\Framework\Http\Binding;

/**
 * Marker interface: a class implementing this gets hydrated from
 * the request (query + body + headers) by `Binder::bind()` when
 * it shows up as a controller-method parameter, instead of being
 * resolved through the container.
 *
 * There is no contract to implement — the interface only exists
 * so the framework can tell DTOs apart from services.
 *
 *   final class CreateProduct implements RequestDto
 *   {
 *       #[Required]
 *       #[Length(min: 1, max: 100)]
 *       public string $name;
 *
 *       #[Required]
 *       #[Min(0)]
 *       public int $price;
 *
 *       public bool $active = true;
 *   }
 */
interface RequestDto
{
}
