<?php

namespace Rxn\Framework\Data;

use \Rxn\Framework\Error\QueryException;

class Query
{
    const TYPE_QUERY            = 'query';
    const TYPE_FETCH            = 'fetch';
    const TYPE_FETCH_ALL        = 'fetchAll';
    const TYPE_FETCH_ARRAY      = 'fetchArray';
    const DEFAULT_CACHE_TIMEOUT = 5;

    /**
     * list of statements that cause an implicit commit
    // source: http://dev.mysql.com/doc/refman/5.0/en/implicit-commit.html
     */
    const IMPLICIT_COMMIT_STATEMENTS = [
        'ALTER TABLE',
        'CREATE INDEX',
        'DROP INDEX',
        'DROP TABLE',
        'RENAME TABLE',
        'CREATE TABLE',
        'CREATE DATABASE',
        'DROP DATABASE',
        'TRUNCATE TABLE',
        'ALTER PROCEDURE',
        'CREATE PROCEDURE',
        'DROP PROCEDURE',
        'ALTER FUNCTION',
        'CREATE FUNCTION',
        'DROP FUNCTION',
        'ALTER VIEW',
        'CREATE TRIGGER',
        'CREATE VIEW',
        'DROP TRIGGER',
        'DROP VIEW',
        'CREATE USER',
        'DROP USER',
        'RENAME USER',
    ];

    public $allowed_types = [
        self::TYPE_QUERY,
        self::TYPE_FETCH,
        self::TYPE_FETCH_ALL,
        self::TYPE_FETCH_ARRAY,
    ];

    /**
     * @var string
     */
    private $sql;

    /**
     * @var array
     */
    private $bindings;

    /**
     * @var string
     */
    private $type;

    /**
     * @var bool
     */
    private $caching;

    /**
     * @var null|float
     */
    private $timeout;

    /**
     * @var \PDO
     */
    private $connection;

    /**
     * @var array
     */
    private $attributes;

    /**
     * @var int
     */
    private $last_insert_id;

    /**
     * @var string|null directory used for the filesystem query cache
     */
    private $cache_directory;

    /**
     * @var int cache TTL in seconds
     */
    private $cache_ttl = 300;

    /**
     * @var int
     */
    private $last_affected_rows;

    /**
     * @var bool
     */
    private $in_transaction = false;

    /**
     * @var
     */
    private $executed = false;

    /**
     * Builder constructor.
     *
     * @param \PDO   $connection
     * @param string $type
     * @param string $sql
     * @param array  $bindings
     *
     */
    public function __construct(\PDO $connection, string $type, string $sql, array $bindings = [])
    {
        $this->setConnection($connection);
        $this->sql        = $sql;
        $this->bindings   = $bindings;
        $this->type       = $type;
        $this->attributes = $this->getAttributes();
    }

    /**
     * @return \PDO
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * @param \PDO $connection
     */
    public function setConnection(\PDO $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return array
     */
    private function getAttributes()
    {
        $attributes = [
            "AUTOCOMMIT",
            "ERRMODE",
            "CASE",
            "CLIENT_VERSION",
            "CONNECTION_STATUS",
            "ORACLE_NULLS",
            "PERSISTENT",
            "PREFETCH",
            "SERVER_INFO",
            "SERVER_VERSION",
            "TIMEOUT",
        ];

        $response = [];
        foreach ($attributes as $val) {
            $key = "PDO::ATTR_$val";
            try {
                $response[$key] = $this->connection->getAttribute(constant("PDO::ATTR_$val"));
            } catch (\PDOException $exception) {
                continue;
            }
        }
        return $response;
    }

    /**
     * @param string $sql
     *
     * @return array
     */
    public function splitStatement(string $sql)
    {
        $split_sql_array  = [];
        $multiple_queries = preg_split('#[\;]+#', $sql, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($multiple_queries as $split_sql) {
            $trimmed_split_sql = trim($split_sql);
            if (!empty($trimmed_split_sql)) {
                $split_sql_array[] = $trimmed_split_sql;
            }
        }
        return $split_sql_array;
    }

    /**
     * @param string $sql
     *
     * @return array|null
     */
    private function getTransactionProblemStatements(string $sql)
    {
        $problem_statements = null;

        foreach (self::IMPLICIT_COMMIT_STATEMENTS as $implicit_commit_statement) {
            $implicit_statement = (mb_stripos($sql, $implicit_commit_statement) !== false);
            if ($implicit_statement) {
                $problem_statements[] = $implicit_commit_statement;
            }
        }
        return $problem_statements;
    }

    private function isLookup()
    {
        if ($this->type == self::TYPE_FETCH || $this->type == self::TYPE_FETCH_ARRAY
            || $this->type == self::TYPE_FETCH_ALL
        ) {
            return true;
        }
        return false;
    }

    /**
     * @return array|mixed
     * @throws QueryException
     */
    public function run()
    {
        if ($this->caching && $this->isLookup()) {
            $hit = $this->cacheRead();
            if ($hit !== null) {
                return $hit;
            }
        }

        // verify that statements which may cause an implicit commit are not used in transactions
        $this->validateProblemStatements();

        // prepare the statement
        $prepared_statement = $this->prepare($this->sql);

        // check for values to bind
        if (!empty($this->bindings)) {
            $prepared_statement = $this->bind($prepared_statement, $this->sql, $this->bindings);
        }

        // execute the statement
        $time_start         = microtime(true);
        $executed_statement = $this->execute($prepared_statement);
        $time_elapsed       = microtime(true) - $time_start;

        // set the static 'last_insert_id' property
        if ($this->type == 'query') {
            $this->last_insert_id     = $this->connection->lastInsertId();
            $this->last_affected_rows = $executed_statement->rowCount();
        }

        // validate the response
        if (!$executed_statement) {
            $var_string = implode("','", $this->bindings);
            throw new QueryException("Could not execute prepared statement: '{$this->sql}'"
                . " (prepared values: '$var_string')", 500);
        }

        // return results of the query
        return $this->returnQueryResults($executed_statement, $time_elapsed);
    }

    private function validateProblemStatements()
    {
        if ($this->in_transaction) {
            $problem_statements = $this->getTransactionProblemStatements($this->sql);
            if (is_array($problem_statements)) {
                $problems = implode(', ', $problem_statements);
                throw new QueryException("Transactions with implicit commit statements: $problems", 500);
            }
        }
    }

    private function returnQueryResults(\PDOStatement $executed_statement, $time_elapsed)
    {
        switch ($this->type) {
            case "query":
                return true;
            case "fetchAll":
                $result = $executed_statement->fetchAll(\PDO::FETCH_ASSOC);
                if ($this->caching) {
                    $this->cacheWrite($result);
                }
                $this->executed = true;
                return $result;
            case "fetchArray":
                $result = $executed_statement->fetchAll(\PDO::FETCH_COLUMN);
                if ($this->caching) {
                    $this->cacheWrite($result);
                }
                $this->executed = true;
                return $result;
            case "fetch":
                $result = $executed_statement->fetch(\PDO::FETCH_ASSOC);
                if ($this->caching) {
                    $this->cacheWrite($result);
                }
                $this->executed = true;
                return $result;
            default:
                throw new QueryException("Incorrect query type '{$this->type}'", 500);
        }
    }

    /**
     * @param string $sql
     *
     * @return \PDOStatement
     * @throws QueryException
     */
    private function prepare(string $sql): \PDOStatement
    {
        // prepare the statement
        try {
            $statement = $this->connection->prepare($sql);
        } catch (\PDOException $exception) {
            $error = $exception->getCode();
            throw new QueryException("PDO Exception (code $error)", 500, $exception);
        }
        return $statement;
    }

    /**
     * @param \PDOStatement $statement
     * @param string        $sql
     * @param array         $bindings
     *
     * @return \PDOStatement
     * @throws QueryException
     */
    private function bind(\PDOStatement $statement, string $sql, array $bindings): \PDOStatement
    {
        // associative binding with ":" (e.g., [:preparedVar] => "prepared value")
        if (array_keys($bindings) !== range(0, count($bindings) - 1)) {
            return $this->bindAssociative($statement, $bindings);
        }

        $array_size = substr_count($sql, '?');
        // fail if the raw sql has an incorrect number of binding points
        $binding_size = count($bindings);
        if ($array_size != $binding_size) {
            throw new QueryException("Trying to bind '$binding_size' properties "
                . "when sql statement has '$array_size' binding points", 500);
        }

        return $this->bindIndexed($statement, $bindings);
    }

    /**
     * @param \PDOStatement $statement
     * @param array         $bindings
     *
     * @return \PDOStatement
     * @throws QueryException
     */
    private function bindAssociative(\PDOStatement $statement, array $bindings)
    {
        foreach ($bindings as $key => $value) {
            if (!is_scalar($value) && !is_null($value)) {
                throw new QueryException("Can only bind types: string, null, int, float, bool", 500);
            }
            try {
                $statement->bindValue(trim((string)$key), $value);
            } catch (\PDOException $exception) {
                $error = $exception->getMessage();
                throw new QueryException("PDO Exception ($error)", 500, $exception);
            }
        }
        return $statement;
    }

    /**
     * @param \PDOStatement $statement
     * @param array         $bindings
     *
     * @return \PDOStatement
     * @throws QueryException
     */
    private function bindIndexed(\PDOStatement $statement, array $bindings)
    {
        foreach ($bindings as $key => $value) {
            if (!is_scalar($value) && !is_null($value)) {
                throw new QueryException("Can only bind types: string, null, int, float, bool", 500);
            }
            $next_key = $key + 1;
            try {
                $statement->bindValue($next_key, $value);
            } catch (\PDOException $exception) {
                $error = $exception->getMessage();
                throw new QueryException("PDO Exception ($error)", 500, $exception);
            }
        }
        return $statement;
    }

    /**
     * @param \PDOStatement $statement
     *
     * @return \PDOStatement
     * @throws QueryException
     */
    private function execute(\PDOStatement $statement)
    {
        // execute the statement
        try {
            $statement->execute();
        } catch (\PDOException $exception) {
            $error = $exception->getMessage();
            throw new QueryException("PDO Exception ($error)", 500);
        }
        return $statement;
    }

    public function getLastInsertId()
    {
        return $this->last_insert_id;
    }

    public function getLastAffectedRows()
    {
        return $this->last_affected_rows;
    }

    /**
     * @param        $host
     * @param        $name
     * @param        $username
     * @param        $password
     * @param string $charset
     *
     * @return array
     */
    public function defineDefaultSettings($host, $name, $username, $password, $charset = 'utf8')
    {
        $default_settings = [
            'host'     => $host,
            'name'     => $name,
            'username' => $username,
            'password' => $password,
            'charset'  => $charset,
        ];
        return $default_settings;
    }

    /**
     * Enable or disable query-result caching on this Query. When
     * enabled, read queries whose (sql, bindings) hash matches an
     * on-disk cache file newer than the TTL return the cached
     * result; read queries that miss populate the cache after
     * running.
     */
    public function setCache(?string $directory, int $ttl_seconds = 300): void
    {
        if ($directory === null) {
            $this->caching         = false;
            $this->cache_directory = null;
            return;
        }
        if ($ttl_seconds < 1) {
            throw new QueryException('Cache TTL must be a positive integer', 500);
        }
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new QueryException("Query cache directory unavailable: $directory", 500);
        }
        $this->caching         = true;
        $this->cache_directory = rtrim($directory, '/');
        $this->cache_ttl       = $ttl_seconds;
    }

    private function cacheKeyPath(): string
    {
        $hash = md5($this->type . '|' . $this->sql . '|' . serialize($this->bindings));
        return $this->cache_directory . '/' . $hash . '.qcache';
    }

    /**
     * @return mixed|null cached result, or null on miss / expired
     */
    private function cacheRead()
    {
        if ($this->cache_directory === null) {
            return null;
        }
        $path = $this->cacheKeyPath();
        if (!is_file($path)) {
            return null;
        }
        if ((time() - filemtime($path)) >= $this->cache_ttl) {
            @unlink($path);
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $decoded = unserialize($raw, ['allowed_classes' => false]);
        return $decoded === false && $raw !== 'b:0;' ? null : $decoded;
    }

    private function cacheWrite($result): void
    {
        if ($this->cache_directory === null) {
            return;
        }
        $path = $this->cacheKeyPath();
        $tmp  = tempnam($this->cache_directory, 'qc_');
        if ($tmp === false) {
            return;
        }
        if (file_put_contents($tmp, serialize($result), LOCK_EX) === false) {
            @unlink($tmp);
            return;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
        }
    }

    /**
     * Remove every cached entry in the configured cache directory.
     */
    public function clearCache(): void
    {
        if ($this->cache_directory === null) {
            return;
        }
        foreach (glob($this->cache_directory . '/*.qcache') ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * @param string $type
     *
     * @throws QueryException
     */
    public function setType(string $type)
    {
        if (!in_array($type, $this->allowed_types)) {
            throw new QueryException("Builder type '$type' is not allowed", 500);
        }
        $this->type = $type;
    }

    /**
     * @return bool
     */
    public function isInTransaction(): bool
    {
        return $this->in_transaction;
    }

    /**
     * @param bool $in_transaction
     */
    public function setInTransaction(bool $in_transaction)
    {
        $this->in_transaction = $in_transaction;
    }
}
