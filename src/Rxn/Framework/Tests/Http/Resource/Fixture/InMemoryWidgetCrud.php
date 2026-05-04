<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http\Resource\Fixture;

use Rxn\Framework\Http\Binding\RequestDto;
use Rxn\Framework\Http\Resource\CrudHandler;

/**
 * In-memory `CrudHandler` for tests. Keeps storage state on the
 * instance so each test starts fresh; production handlers wire
 * a database / `rxn-orm` query builder / etc. behind the same
 * five-method shape.
 */
final class InMemoryWidgetCrud implements CrudHandler
{
    /** @var array<int, array<string, mixed>> */
    private array $rows = [];
    private int $nextId = 1;

    public function create(RequestDto $dto): array
    {
        if (!$dto instanceof CreateWidget) {
            throw new \LogicException('CreateWidget DTO required for create');
        }
        $id  = $this->nextId++;
        $row = [
            'id'     => $id,
            'name'   => $dto->name,
            'price'  => $dto->price,
            'status' => $dto->status,
        ];
        $this->rows[$id] = $row;
        return $row;
    }

    public function read(int|string $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function update(int|string $id, RequestDto $dto): ?array
    {
        if (!$dto instanceof UpdateWidget) {
            throw new \LogicException('UpdateWidget DTO required for update');
        }
        if (!isset($this->rows[$id])) {
            return null;
        }
        $row = $this->rows[$id];
        if ($dto->name !== null)   { $row['name']   = $dto->name; }
        if ($dto->price !== null)  { $row['price']  = $dto->price; }
        if ($dto->status !== null) { $row['status'] = $dto->status; }
        $this->rows[$id] = $row;
        return $row;
    }

    public function delete(int|string $id): bool
    {
        if (!isset($this->rows[$id])) {
            return false;
        }
        unset($this->rows[$id]);
        return true;
    }

    public function search(?RequestDto $filter): array
    {
        $rows = array_values($this->rows);
        if ($filter instanceof SearchWidgets) {
            if ($filter->status !== null) {
                $rows = array_values(array_filter(
                    $rows,
                    static fn (array $r) => $r['status'] === $filter->status,
                ));
            }
            if ($filter->q !== null && $filter->q !== '') {
                $needle = strtolower($filter->q);
                $rows = array_values(array_filter(
                    $rows,
                    static fn (array $r) => str_contains(strtolower((string) $r['name']), $needle),
                ));
            }
        }
        return $rows;
    }
}
