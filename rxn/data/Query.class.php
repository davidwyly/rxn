<?php
/**
 * This file is part of the Rxn (Reaction) PHP API App
 *
 * @package    Rxn
 * @copyright  2015-2017 David Wyly
 * @author     David Wyly (davidwyly) <david.wyly@gmail.com>
 * @link       Github <https://github.com/davidwyly/rxn>
 * @license    MIT License (MIT) <https://github.com/davidwyly/rxn/blob/master/LICENSE>
 */

namespace Rxn\Data;

use \Rxn\Error\QueryException;

class Query
{
    const TYPE_QUERY            = 'query';
    const TYPE_FETCH            = 'fetch';
    const TYPE_FETCH_ALL        = 'fetchAll';
    const TYPE_FETCH_ARRAY      = 'fetchArray';
    const DEFAULT_CACHE_TIMEOUT = 5;

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
    private $executed= false;

    /**
     * Query constructor.
     *
     * @param \PDO   $connection
     * @param string $type
     * @param string $sql
     * @param array  $bindings
     *
     */
    public function __construct(\PDO $connection, string $type, string $sql, array $bindings = []) {
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
        foreach ($multiple_queries as $key => $split_sql) {
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

        // list of statements that cause an implicit commit
        // source: http://dev.mysql.com/doc/refman/5.0/en/implicit-commit.html
        $implicit_commit_statements = [
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
        foreach ($implicit_commit_statements as $implicit_commit_statement) {
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
        if ($this->caching
            && $this->isLookup()
        ) {
            return $this->cacheLookup($this->type, $this->sql, $this->bindings);
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

    private function validateProblemStatements() {
        if ($this->in_transaction) {
            $problem_statements = $this->getTransactionProblemStatements($this->sql);
            if (is_array($problem_statements)) {
                $problems = implode(', ', $problem_statements);
                throw new QueryException("Transactions with implicit commit statements: $problems", 500);
            }
        }
    }

    private function returnQueryResults(\PDOStatement $executed_statement, $time_elapsed) {
        switch ($this->type) {
            case "query":
                return true;
            case "fetchAll":
                $result = $executed_statement->fetchAll(\PDO::FETCH_ASSOC);
                if ($this->caching) {
                    $this->cacheResult($result, $time_elapsed);
                }
                $this->executed = true;
                return $result;
            case "fetchArray":
                $result = $executed_statement->fetchAll(\PDO::FETCH_COLUMN);
                if ($this->caching) {
                    $this->cacheResult($result, $time_elapsed);
                }
                $this->executed = true;
                return $result;
            case "fetch":
                $result = $executed_statement->fetch(\PDO::FETCH_ASSOC);
                if ($this->caching) {
                    $this->cacheResult($result, $time_elapsed);
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
            foreach ($bindings as $key => $value) {
                try {
                    $statement->bindValue(trim($key), trim($value));
                } catch (\PDOException $exception) {
                    $error = $exception->getMessage();
                    throw new QueryException("PDO Exception ($error)", 500, $exception);
                }
            }
            return $statement;
        }

        $array_size = substr_count($sql, '?');
        // fail if the raw sql has an incorrect number of binding points
        $binding_size = count($bindings);
        if ($array_size != $binding_size) {
            throw new QueryException("Trying to bind '$binding_size' properties "
                . "when sql statement has '$array_size' binding points", 500);
        }

        foreach ($bindings as $key => $value) {
            if (!is_string($value)
                && !is_null($value)
                && !is_int($value)
                && !is_float($value)
            ) {
                throw new QueryException("Can only bind types: string, null, int, float", 500);
            }
            $next_key = $key + 1;
            try {
                $statement->bindValue($next_key, trim($value));
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
     * @return bool
     * @throws QueryException
     */
    public function clearCache()
    {
        $cache_table     = $this->cache_table_settings['table'];
        $truncate_sql    = "TRUNCATE TABLE `$cache_table`;";
        $truncate_query  = new Query($this->connection, $truncate_sql, [], 'query');
        $truncate_result = $truncate_query->run();
        if (!$truncate_result) {
            return false;
        }
        return true;
    }

    /**
     * @param string $type
     * @param string $sql
     * @param array  $bindings
     *
     * @return bool|mixed
     * @throws QueryException
     */
    private function cacheLookup(string $type, string $sql, array $bindings)
    {
        // hash the raw SQL and values array
        $sql_hash   = md5(serialize($sql));
        $param_hash = md5(serialize($bindings));

        $table          = $this->cache_table_settings['table'];
        $sql_column     = $this->cache_table_settings['sql_column'];
        $param_column   = $this->cache_table_settings['param_column'];
        $type_column    = $this->cache_table_settings['type_column'];
        $expires_column = $this->cache_table_settings['expires_column'];

        $find_sql    = "
            SELECT *
            FROM `$table`
            WHERE `$sql_column` LIKE :sql_hash
                AND `$param_column` LIKE :param_hash
                AND `$type_column` LIKE :type
                AND `$expires_column` > NOW()
        ";
        $bind_params = [
            'sql_hash'   => $sql_hash,
            'param_hash' => $param_hash,
            'type'       => $type,
        ];
        $cache_query = new Query($this->connection, $find_sql, $bind_params, 'fetch', false);
        $find_result = $cache_query->run();

        if ($find_result) {
            return unserialize($find_result['package']);
        }
        return false;
    }

    /**
     * @param       $result
     * @param float $time_elapsed
     *
     * @return bool
     * @throws QueryException
     */
    private function cacheResult($result, float $time_elapsed): bool
    {
        // sanitize timeout
        if (!is_numeric($this->timeout)) {
            throw new QueryException(__METHOD__ . ": cache timeout needs to be numeric", 500);
        }

        $serialized_result = serialize($result);
        $sql_hash          = md5(serialize($this->sql));
        $param_hash        = md5(serialize($this->bindings));
        $table             = $this->cache_table_settings['table'];
        $timestamp_column  = $this->cache_table_settings['timestamp_column'];
        $expires_column    = $this->cache_table_settings['expires_column'];
        $sql_column        = $this->cache_table_settings['sql_column'];
        $param_column      = $this->cache_table_settings['param_column'];
        $type_column       = $this->cache_table_settings['type_column'];
        $package_column    = $this->cache_table_settings['package_column'];
        $elapsed_column    = $this->cache_table_settings['elapsed_column'];

        $insert_sql  = /** @lang MySQL */
            "
            INSERT INTO `$table` (
                `$expires_column`,
                `$sql_column`,
                `$param_column`,
                `$type_column`,
                `$package_column`,
                `$elapsed_column`
            )
            VALUES (
                NOW() + INTERVAL {$this->timeout} MINUTE,
                :sql_hash,
                :param_hash,
                :type,
                :serialized_result,
                :queryTime
            )
            ON DUPLICATE KEY
                UPDATE
                    `$timestamp_column` = NOW(),
                    `$package_column` = :serializedResultUpdate,
                    `$expires_column` = NOW() + INTERVAL {$this->timeout} MINUTE
        ";
        $bind_params = [
            "sql_hash"               => $sql_hash,
            "param_hash"             => $param_hash,
            "type"                   => $this->type,
            "serialized_result"      => $serialized_result,
            "serializedResultUpdate" => $serialized_result,
            "queryTime"              => $time_elapsed,
        ];

        $insert_query  = new Query($this->connection, $insert_sql, $bind_params, 'query');
        $insert_result = $insert_query->run();
        if ($insert_result) {
            return true;
        }
        return false;
    }

    /**
     * @param string $type
     *
     * @throws QueryException
     */
    public function setType(string $type)
    {
        if (!in_array($type, $this->allowed_types)) {
            throw new QueryException("Query type '$type' is not allowed", 500);
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
