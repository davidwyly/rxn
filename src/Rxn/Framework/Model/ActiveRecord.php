<?php declare(strict_types=1);

namespace Rxn\Framework\Model;

use Rxn\Framework\Data\Database;
use Rxn\Orm\Builder\Query;

/**
 * Minimal active-record layer on top of the Rxn\Orm query builder.
 *
 * Subclasses declare their table via `const TABLE` and optionally
 * override `const PK`; reading operations hydrate rows into
 * subclass instances exposing column values as public __get
 * properties. Relationships (hasMany / belongsTo / hasOne) return
 * builder Query instances so callers can add extra filters or
 * pagination before executing.
 *
 *   class User extends ActiveRecord {
 *       public const TABLE = 'users';
 *       public function orders(): Query {
 *           return $this->hasMany(Order::class, 'user_id');
 *       }
 *       public function role(): Query {
 *           return $this->belongsTo(Role::class, 'role_id');
 *       }
 *   }
 *
 *   $user   = User::find($database, 42);
 *   $email  = $user->email;                 // __get attribute
 *   $orders = User::hydrate($database->run($user->orders()), Order::class);
 *
 * By design this layer is read-oriented — persistence happens via
 * the Query / Insert / Update / Delete builders and
 * Database::run(). Apps that want Eloquent-style $user->save() can
 * add it on top; keeping it out of the framework keeps the core
 * small and lets callers opt into whatever mutation shape fits.
 */
abstract class ActiveRecord
{
    /** Subclasses must override with their table name. */
    public const TABLE = null;

    /** Override to change the primary-key column. */
    public const PK = 'id';

    /** @var array<string, mixed> */
    protected array $attributes = [];

    /**
     * Wrap an already-fetched row into an ActiveRecord instance.
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->attributes = $row;
        return $instance;
    }

    /**
     * Fetch the row with the given primary-key value and hydrate
     * it. Returns null when no row matches.
     */
    public static function find(Database $database, mixed $id): ?static
    {
        $query = (new Query())
            ->select()
            ->from(static::tableName())
            ->where(static::PK, '=', $id)
            ->limit(1);

        $rows = $database->run($query);
        return empty($rows) ? null : static::fromRow($rows[0]);
    }

    /**
     * Run `$query` and hydrate every row into $class instances.
     *
     * @template T of ActiveRecord
     * @param class-string<T> $class
     * @return T[]
     */
    public static function hydrate(array $rows, string $class): array
    {
        if (!is_subclass_of($class, self::class)) {
            throw new \InvalidArgumentException("$class is not an ActiveRecord");
        }
        // Inline the per-row construction. array_map+fromRow does
        // a closure call + a static method dispatch per row; this
        // body collapses both into one `new $class()` and a direct
        // property write, sustainably faster on big result sets.
        $out = [];
        foreach ($rows as $r) {
            $instance = new $class();
            $instance->attributes = $r;
            $out[] = $instance;
        }
        return $out;
    }

    /**
     * Return the resolved table name, enforcing the TABLE constant.
     */
    public static function tableName(): string
    {
        if (static::TABLE === null) {
            throw new \LogicException('Define TABLE on ' . static::class);
        }
        return static::TABLE;
    }

    /**
     * Start a SELECT Query on this record's table. Callers can
     * layer their own conditions and then execute via
     * $database->run($query).
     */
    public static function query(): Query
    {
        return (new Query())->select()->from(static::tableName());
    }

    /**
     * Hydrated column access: $user->email
     */
    public function __get(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return array_key_exists($name, $this->attributes);
    }

    /**
     * Primary-key value for this row.
     */
    public function id(): mixed
    {
        return $this->attributes[static::PK] ?? null;
    }

    /**
     * Raw attribute map; useful for serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    // -- relationships ---------------------------------------------

    /**
     * "Has many" relation: return a Query fetching rows from the
     * related class's table whose $foreignKey matches this record's
     * primary key.
     *
     * @param class-string<ActiveRecord> $relatedClass
     */
    public function hasMany(string $relatedClass, string $foreignKey): Query
    {
        $this->assertSubclass($relatedClass);
        return (new Query())
            ->select()
            ->from($relatedClass::tableName())
            ->where($foreignKey, '=', $this->id());
    }

    /**
     * "Has one" relation: exactly like hasMany but limited to one
     * row. The caller can call ::fromRow on $database->run(...)[0]
     * (or use ->first() semantics in app code) to hydrate.
     *
     * @param class-string<ActiveRecord> $relatedClass
     */
    public function hasOne(string $relatedClass, string $foreignKey): Query
    {
        return $this->hasMany($relatedClass, $foreignKey)->limit(1);
    }

    /**
     * "Belongs to" relation: fetch the single row from $ownerClass
     * whose primary key matches this record's $foreignKey column.
     *
     * @param class-string<ActiveRecord> $ownerClass
     */
    public function belongsTo(string $ownerClass, string $foreignKey, ?string $ownerKey = null): Query
    {
        $this->assertSubclass($ownerClass);
        $pk    = $ownerKey ?? $ownerClass::PK;
        $value = $this->attributes[$foreignKey] ?? null;
        return (new Query())
            ->select()
            ->from($ownerClass::tableName())
            ->where($pk, '=', $value)
            ->limit(1);
    }

    private function assertSubclass(string $class): void
    {
        if (!is_subclass_of($class, self::class)) {
            throw new \InvalidArgumentException("$class is not an ActiveRecord");
        }
    }
}
