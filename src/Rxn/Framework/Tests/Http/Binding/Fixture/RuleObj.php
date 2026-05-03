<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Binding\Fixture;

final class RuleObj
{
    public function __construct(public readonly string $expected) {}
}
