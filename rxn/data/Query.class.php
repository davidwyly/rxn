<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author  David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn\Data;

use \Rxn\MultiByte;
use \Rxn\Config;
use \Rxn\Datasources;
use \Rxn\Utility\Debug as Debug;

/**
 * Class Query
 *
 * @package Rxn\Data
 */
class Query
{
    const TYPE_QUERY            = 'query';
    const TYPE_FETCH            = 'fetch';
    const TYPE_FETCH_ALL        = 'fetchAll';
    const TYPE_FETCH_ARRAY      = 'fetchArray';
    const DEFAULT_CACHE_TIMEOUT = 5;

    /**
     * @var string
     */
    public $sql;

    /**
     * @var array
     */
    public $bindings;

    /**
     * @var string
     */
    public $type;

    /**
     * @var bool
     */
    public $caching;

    /**
     * @var null|float
     */
    public $timeout;

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
    private $transaction_depth;

    /**
     * @var bool
     */
    private $in_transaction = false;

    /**
     * @var int
     */
    private $last_insert_id;

    /**
     * @var int
     */
    private $last_affected_rows;

    /**
     * Query constructor.
     *
     * @param \PDO   $connection
     * @param string $sql
     * @param array  $bindings
     * @param string $type
     * @param        $cache
     * @param        $timeout
     *
     * @throws \Exception
     */
    public function __construct(
        \PDO $connection,
        string $sql,
        array $bindings,
        string $type,
        bool $cache = false,
        $timeout = null
    ) {
        $this->setConnection($connection);
        $this->sql        = $sql;
        $this->bindings   = $bindings;
        $this->type       = $type;
        $this->cache      = $cache;
        $this->timeout    = $timeout;
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
     *
     * @throws \Exception
     */
    public function setConnection(\PDO $connection)
    {
        $this->connection = $connection;
    }

    public function transactionOpen()
    {
        if (!empty($this->transaction_depth)) {
            $this->transaction_depth++;
            return true;
        }
        try {
            $this->connection->beginTransaction();
        } catch (\PDOException $e) {
            throw new \Exception($e->getMessage(), 500);
        }
        $this->transaction_depth++;
        $this->in_transaction = true;
        return true;
    }

    /**
     * @return bool
     * @throws \Exception
     */
    public function transactionClose()
    {
        if ($this->transaction_depth < 1) {
            throw new \Exception(__METHOD__ . ": transaction does not exist", 500);
        }
        if ($this->transaction_depth > 1) {
            $this->transaction_depth--;
            return true;
        }
        try {
            $this->connection->commit();
        } catch (\PDOException $e) {
            $error = $e->getCode();
            throw new \Exception("PDO Exception (code $error)", 500);
        }
        return true;
    }

    /**
     * @return bool
     * @throws \Exception
     */
    private function transactionRollback()
    {
        if ($this->transaction_depth < 1) {
            throw new \Exception(__METHOD__ . ": transaction does not exist", 500);
        }
        try {
            $this->connection->rollBack();
        } catch (\PDOException $e) {
            $error = $e->getCode();
            throw new \Exception("PDO Exception (code $error)", 500);
        }
        $this->transaction_depth--;
        return true;
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
            } catch (\PDOException $e) {
                continue;
            }
        }
        return $response;
    }

    /**
     * @return bool
     * @throws \Exception
     */
    public function disconnect()
    {
        if ($this->transaction_depth > 0) {
            $this->transactionRollback();
        }
        $this->in_transaction    = false;
        $this->transaction_depth = 0;
        $this->connection        = null;
        return true;
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
            $implicit_statement = (MultiByte::stripos($sql, $implicit_commit_statement) !== false);
            if ($implicit_statement) {
                $problem_statements[] = $implicit_commit_statement;
            }
        }
        return $problem_statements;
    }

    /**
     * @return array|mixed
     * @throws \Exception
     */
    public function run()
    {
        if ($this->caching) {
            if (!is_numeric($this->timeout)) {
                $cache_timeout = self::DEFAULT_CACHE_TIMEOUT;
            }
            $cache = $this->cacheLookup($this->sql,$this->bindings,$this->type);
            if ($cache) {
                return $cache;
            }
        }

        // verify that statements which may cause an implicit commit are not used in transactions
        if ($this->transaction_depth > 0) {
            $problem_statements = $this->getTransactionProblemStatements($this->sql);
            if (is_array($problem_statements)) {
                $problems = implode(', ', $problem_statements);
                throw new \Exception("Transactions used when implicit commit statements exist in query: $problems",
                    500);
            }
        }

        // prepare the statement
        $prepared_statement = $this->prepare($this->sql);
        if (!$prepared_statement) {
            throw new \Exception("Could not prepare statement", 500);
        }

        // check for values to bind
        if (!empty($this->bindings)) {
            // bind values to the the prepared statement
            $prepared_statement = $this->bind($prepared_statement, $this->sql, $this->bindings);
            if (!$prepared_statement) {
                throw new \Exception("Could not bind prepared statement", 500);
            }
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
            throw new \Exception("Could not execute prepared statement: '{$this->sql}'"
                . " (prepared values: '$var_string')", 500);
        }

        // check to see if the connection should close after execution
        if (!$this->in_transaction) {
            // disconnect normally
            $this->disconnect();
        }

        // return results of the query
        switch ($this->type) {
            case "query":
                return true;
            case "fetchAll":
                $result = $executed_statement->fetchAll(\PDO::FETCH_ASSOC);
                if ($this->caching) {
                    $this->cacheResult($result, $time_elapsed);
                }
                return $result;
            case "fetchArray":
                $result = $executed_statement->fetchAll(\PDO::FETCH_COLUMN);
                if ($this->caching) {
                    $this->cacheResult($result, $time_elapsed);
                }
                return $result;
            case "fetch":
                $result = $executed_statement->fetch(\PDO::FETCH_ASSOC);
                if ($this->caching) {
                    $this->cacheResult($result, $time_elapsed);
                }
                return $result;
            default:
                throw new \Exception("Incorrect query type '{$this->type}'", 500);
        }
    }

    /**
     * @param string $sql
     *
     * @return \PDOStatement
     * @throws \Exception
     */
    private function prepare(string $sql): \PDOStatement
    {
        // prepare the statement
        try {
            $statement = $this->connection->prepare($sql);
        } catch (\PDOException $e) {
            $error = $e->getCode();
            throw new \Exception("PDO Exception (code $error)", 500);
        }
        return $statement;
    }

    /**
     * @param \PDOStatement $statement
     * @param string        $sql
     * @param array         $bindings
     *
     * @return \PDOStatement
     * @throws \Exception
     */
    private function bind(\PDOStatement $statement, string $sql, array $bindings): \PDOStatement
    {
        // associative binding with ":" (e.g., [:preparedVar] => "prepared value")
        if (array_keys($bindings) !== range(0, count($bindings) - 1)) {
            foreach ($bindings as $key => $value) {
                try {
                    $statement->bindValue(trim($key), trim($value));
                } catch (\PDOException $e) {
                    $error = $e->getMessage();
                    throw new \Exception("PDO Exception ($error)", 500);
                }
            }
        } else { // non-associative binding with "?" (e.g., [0] => "prepared value")
            $array_size = substr_count($sql, '?');
            // fail if the raw sql has an incorrect number of binding points
            $binding_size = count($bindings);
            if ($array_size != $binding_size) {
                throw new \Exception("Trying to bind '$binding_size' properties "
                    . "when sql statement has '$array_size' binding points", 500);
            }
            foreach ($bindings as $key => $value) {
                if (!is_string($value)
                    && !is_null($value)
                    && !is_int($value)
                    && !is_float($value)
                ) {
                    throw new \Exception("Can only bind types: string, null, int, float", 500);
                }
                $next_key = $key + 1;
                try {
                    $statement->bindValue($next_key, trim($value));
                } catch (\PDOException $e) {
                    $error = $e->getMessage();
                    throw new \Exception("PDO Exception ($error)", 500);
                }
            }
        }
        return $statement;
    }

    /**
     * @param \PDOStatement $statement
     *
     * @return \PDOStatement
     * @throws \Exception
     */
    private function execute(\PDOStatement $statement)
    {
        // execute the statement
        try {
            $statement->execute();
        } catch (\PDOException $e) {
            $error = $e->getMessage();
            throw new \Exception("PDO Exception ($error)", 500);
        }
        return $statement;
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
     * @throws \Exception
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

    private function cacheLookup(string $sql, array $bindings, string $type)
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
     * @param                         $result
     * @param float                   $time_elapsed
     *
     * @return bool
     * @throws \Exception
     */
    private function cacheResult($result, float $time_elapsed): bool
    {
        // sanitize timeout
        if (!is_numeric($this->timeout)) {
            throw new \Exception(__METHOD__ . ": cache timeout needs to be numeric", 500);
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

        $insert_query = new Query($this->connection, $insert_sql, $bind_params, 'query');
        $insert_result = $insert_query->run();
        if ($insert_result) {
            return true;
        }
        return false;
    }

}