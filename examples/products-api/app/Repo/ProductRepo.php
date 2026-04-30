<?php declare(strict_types=1);

namespace Example\Products\Repo;

use PDO;

/**
 * Minimal PDO/sqlite-backed repository for the products example.
 * Kept deliberately small — the point of the example is to show
 * the framework wiring, not to demonstrate a real ORM.
 */
final class ProductRepo
{
    public function __construct(private readonly PDO $pdo) {}

    public static function bootstrap(string $sqlitePath): self
    {
        $pdo = new PDO('sqlite:' . $sqlitePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS products (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                name     TEXT    NOT NULL,
                price    REAL    NOT NULL,
                status   TEXT    NOT NULL DEFAULT 'draft',
                homepage TEXT             DEFAULT NULL
            )
        SQL);
        return new self($pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * @return list<array{id:int, name:string, price:float, status:string, homepage:?string}>
     */
    public function paginate(int $limit, int $offset): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, price, status, homepage FROM products ORDER BY id LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array{id:int, name:string, price:float, status:string, homepage:?string}> */
        return $stmt->fetchAll();
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    }

    /** @return array{id:int, name:string, price:float, status:string, homepage:?string}|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE id = :id');
        $stmt->execute([':id' => $id]);
        /** @var array{id:int, name:string, price:float, status:string, homepage:?string}|false $row */
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array{id:int, name:string, price:float, status:string, homepage:?string}
     */
    public function create(string $name, float $price, string $status, ?string $homepage): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO products (name, price, status, homepage) VALUES (:name, :price, :status, :homepage)'
        );
        $stmt->execute([
            ':name'     => $name,
            ':price'    => $price,
            ':status'   => $status,
            ':homepage' => $homepage,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        return [
            'id'       => $id,
            'name'     => $name,
            'price'    => $price,
            'status'   => $status,
            'homepage' => $homepage,
        ];
    }
}
