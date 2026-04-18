<?php declare(strict_types=1);

namespace Rxn\Orm\Builder;

/**
 * Opt-out marker for expressions the builder should emit verbatim
 * instead of escaping as an identifier. Use wherever a column /
 * field reference is expected:
 *
 *   $q->select([Raw::of('COUNT(o.id) AS order_count'), 'u.id'])
 *     ->from('orders', 'o')
 *     ->groupBy(Raw::of('DATE(o.created_at)'))
 *     ->orderBy(Raw::of('RAND()'));
 *
 * Contents are not sanitised — callers are responsible for the
 * fragment being safe (typically: don't interpolate user input
 * into a Raw).
 */
final class Raw
{
    public function __construct(public readonly string $sql) {}

    public static function of(string $sql): self
    {
        return new self($sql);
    }

    public function __toString(): string
    {
        return $this->sql;
    }
}
