<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author  David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn\Data;

use \Rxn\Config;
use \Rxn\Datasources;
use \Rxn\Utility\Debug as Debug;

/**
 * Class Database
 *
 * @package Rxn\Data
 */
class Database
{

    /**
     * @var array
     */
    private $default_settings = [
        'host'     => null,
        'name'     => null,
        'username' => null,
        'password' => null,
        'charset'  => 'utf8',
    ];

    /**
     * @var array
     */
    private $cache_table_settings = [
        'table'          => null,
        'expires_column' => null,
        'sql_column'     => null,
        'param_column'   => null,
        'type_column'    => null,
        'package_column' => null,
        'elapsed_column' => null,
    ];

    /**
     * @var bool
     */
    public $allow_caching = false;

    /**
     * @var int
     */
    private $default_cache_timeout = 5;

    /**
     * @var
     */
    private $cache_lookups;

    /**
     * @var \PDO
     */
    private $connection;

    /**
     * @var int
     */
    private $execution_count = 0;

    /**
     * @var float
     */
    private $execution_seconds = 0.0;

    /**
     * @var
     */
    private $queries;

    /**
     * @var bool
     */
    private $stay_alive = false;

    /**
     * @var int
     */
    private $transaction_depth = 0;

    /**
     * @var int
     */
    private $last_insert_id;

    /**
     * @var int
     */
    private $last_affected_rows;

    /**
     * Database constructor.
     *
     * @param Config      $config
     * @param Datasources $datasources
     * @param null|string $source_name
     *
     * @throws \Exception
     */
    public function __construct(Config $config, Datasources $datasources, ?string $source_name = null)
    {
        if (is_null($source_name)) {
            $source_name = $datasources->default_read;
        }
        $this->setConfiguration($config, $datasources, $source_name);
        $this->connect();
    }

    /**
     * @param Config      $config
     * @param Datasources $datasources
     * @param string      $source_name
     *
     * @throws \Exception
     */
    private function setConfiguration(Config $config, Datasources $datasources, string $source_name): void
    {
        $databases = $datasources->getDatabases();
        $this->setDefaultSettings($databases[$source_name]);
        $this->allow_caching = $config->useQueryCaching;
    }

    /**
     * @return mixed
     */
    public function getHost()
    {
        return $this->default_settings['host'];
    }

    /**
     * @return mixed
     */
    public function getName()
    {
        return $this->default_settings['name'];
    }

    /**
     * @return mixed
     */
    public function getUsername()
    {
        return $this->default_settings['username'];
    }

    /**
     * @return mixed
     */
    public function getPassword()
    {
        return $this->default_settings['password'];
    }

    /**
     * @return mixed
     */
    public function getCharset()
    {
        return $this->default_settings['charset'];
    }

    /**
     * @return mixed
     */
    public function getLastInsertid()
    {
        return $this->last_insert_id;
    }

    public function getLastAffectedrows()
    {
        return $this->last_affected_rows;
    }

    /**
     * @param array $default_settings
     *
     * @return null
     * @throws \Exception
     */
    public function setDefaultSettings(array $default_settings)
    {
        $required_keys = array_keys($this->default_settings);
        foreach ($required_keys as $required_key) {
            if (!array_key_exists($required_key, $default_settings)) {
                throw new \Exception("Required key '$required_key' missing", 500);
            }
        }
        $this->default_settings = $default_settings;
        return null;
    }

    /**
     * @param array $cache_table_settings
     *
     * @return null
     * @throws \Exception
     */
    public function setCacheSettings(array $cache_table_settings)
    {
        $required_keys = array_keys($this->cache_table_settings);
        foreach ($required_keys as $required_key) {
            if (!array_key_exists($required_key, $cache_table_settings)) {
                throw new \Exception("Required key '$required_key' missing", 500);
            }
        }
        $this->cache_table_settings = $cache_table_settings;
        return null;
    }

    /**
     * @return array
     */
    public function getDefaultSettings()
    {
        return (array)$this->default_settings;
    }

    /**
     * @return array
     */
    public function getCacheTableSettings()
    {
        return (array)$this->cache_table_settings;
    }

    /**
     * @return \PDO
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * @param \PDO|null $connection
     * @param bool      $stay_alive
     *
     * @return bool
     * @throws \Exception
     */
    public function transactionOpen(?\PDO $connection = null, bool $stay_alive = true)
    {
        $this->verifyConnection();
        if (is_null($connection)) {
            $connection = $this->connection;
        }
        if (!empty($this->transaction_depth)) {
            $this->transaction_depth++;
            return true;
        }
        try {
            $connection->beginTransaction();
        } catch (\PDOException $e) {
            throw new \Exception($e->getMessage(), 500);
        }
        $this->transaction_depth++;
        $this->stay_alive = $stay_alive;
        return true;
    }

    /**
     * @param null|\PDO $connection
     *
     * @return bool
     * @throws \Exception
     */
    private function verifyConnection(?\PDO $connection): bool
    {
        if (empty($connection)) {
            throw new \Exception(__METHOD__ . ": connection does not exist", 500);
        }
        return true;
    }

    /**
     * @param null|\PDO $connection
     *
     * @return bool
     * @throws \Exception
     */
    public function transactionClose(\PDO $connection): bool
    {
        if ($this->transaction_depth < 1) {
            throw new \Exception(__METHOD__ . ": transaction does not exist", 500);
        }
        if ($this->transaction_depth > 1) {
            $this->transaction_depth--;
            return true;
        }
        try {
            /** @var $connection \PDO */
            $connection->commit();
        } catch (\PDOException $e) {
            $error = $e->getCode();
            throw new \Exception("PDO Exception (code $error)", 500);
        }
        return true;
    }

    /**
     * @param null|\PDO $connection
     *
     * @return bool
     * @throws \Exception
     */
    private function transactionRollback(\PDO $connection): bool
    {
        if ($this->transaction_depth < 1) {
            throw new \Exception(__METHOD__ . ": transaction does not exist", 500);
        }
        try {
            /** @var $connection \PDO */
            $connection->rollBack();
        } catch (\PDOException $e) {
            $error = $e->getCode();
            throw new \Exception("PDO Exception (code $error)", 500);
        }
        $this->transaction_depth--;
        return true;
    }

    /**
     * @param \PDO $connection
     *
     * @return array
     */
    public function getAttributes(\PDO $connection)
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
                $response[$key] = $connection->getAttribute(constant("PDO::ATTR_$val"));
            } catch (\PDOException $e) {
                continue;
            }
        }
        return $response;
    }

    /**
     * @param \PDO|null $connection
     * @param bool      $stay_alive
     *
     * @return \PDO
     * @throws \Exception
     */
    public function connect(?\PDO $connection = null, $stay_alive = false): \PDO
    {
        if (is_null($connection)) {
            $connection = $this->createConnection();
        }
        $this->stay_alive = $stay_alive;
        // set connection to static variable and return connection
        return $this->connection = $connection;
    }

    /**
     * @return \PDO
     * @throws \Exception
     */
    public function createConnection()
    {
        $host    = $this->getHost();
        $name    = $this->getName();
        $charset = $this->getCharset();
        try {
            $connection = new \PDO("mysql:host=$host;dbname=$name;charset=$charset", $this->getUsername(),
                $this->getPassword());
        } catch (\PDOException $e) {
            $error = $e->getMessage();
            throw new \Exception("PDO Exception (code $error)", 500);
        }
        $connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $connection;
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
        $this->stay_alive        = false;
        $this->transaction_depth = 0;
        $this->connection        = null;
        return true;
    }

    /**
     * @param $raw_sql
     *
     * @return array
     */
    public function splitStatement(string $raw_sql): array
    {
        $split_sql_array  = [];
        $multiple_queries = preg_split('#[\;]+#', $raw_sql, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($multiple_queries as $key => $split_sql) {
            $trimmed_split_sql = trim($split_sql);
            if (!empty($trimmed_split_sql)) {
                $split_sql_array[] = $trimmed_split_sql;
            }
        }
        return $split_sql_array;
    }

    /**
     * @param $raw_sql
     *
     * @return array|null
     */
    public function getTransactionProblemStatements($raw_sql)
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

            if (function_exists('mb_stripos')) {
                $implicit_statement_exists = (mb_stripos($raw_sql, $implicit_commit_statement) !== false);
            } else {
                $implicit_statement_exists = (stripos($raw_sql, $implicit_commit_statement) !== false);
            }

            if ($implicit_statement_exists) {
                $problem_statements[] = $implicit_commit_statement;
            }
        }
        return $problem_statements;
    }

    /**
     * @param string     $raw_sql
     * @param array      $vars_to_prepare
     * @param string     $query_type
     * @param bool       $use_caching
     * @param float|null $cache_timeout
     *
     * @return array|mixed
     * @throws \Exception
     */
    public function query(
        string $raw_sql,
        array $vars_to_prepare = [],
        string $query_type = 'query',
        bool $use_caching = false,
        ?float $cache_timeout = null
    ) {
        // check global caching settings
        if ($this->allow_caching === false) {
            $use_caching = false;
        }

        // check if an existing connection exists
        if (!$this->connection) {
            // connect to the default connection settings if necessary
            if (!$this->connect()) {
                throw new \Exception("Connection could not be created", 500);
            }
        } else {
            $this->stay_alive = true;
        }

        if ($use_caching) {
            if (!is_numeric($cache_timeout)) {
                $cache_timeout = $this->default_cache_timeout;
            }
            $cache = $this->cacheLookup($raw_sql, $vars_to_prepare, $query_type);
            if ($cache) {
                return $cache;
            }
        }

        // verify that statements which may cause an implicit commit are not used in transactions
        if ($this->transaction_depth > 0) {
            $problem_statements = $this->getTransactionProblemStatements($raw_sql);
            if (is_array($problem_statements)) {
                $problems = implode(', ', $problem_statements);
                throw new \Exception("Transactions used when implicit commit statements exist in query: $problems",
                    500);
            }
        }

        // prepare the statement
        $prepared_statement = $this->prepare($raw_sql);
        if (!$prepared_statement) {
            throw new \Exception("Could not prepare statement", 500);
        }

        // check for values to bind
        if (!empty($vars_to_prepare)) {
            // bind values to the the prepared statement
            $prepared_statement = $this->bind($prepared_statement, $raw_sql, $vars_to_prepare);
            if (!$prepared_statement) {
                throw new \Exception("Could not bind prepared statement", 500);
            }
        }

        // execute the statement
        $time_start         = microtime(true);
        $executed_statement = $this->execute($prepared_statement);
        $time_elapsed       = microtime(true) - $time_start;

        // set the static 'last_insert_id' property
        $connection               = $this->connection;
        $this->last_insert_id     = $connection->lastInsertId();
        $this->last_affected_rows = $executed_statement->rowCount();

        // validate the response
        if (!$executed_statement) {
            $var_string = implode("','", $vars_to_prepare);
            throw new \Exception("Could not execute prepared statement: '$raw_sql' (prepared values: '$var_string')",
                500);
        }

        // log the execution event
        $this->execution_seconds = round($this->execution_seconds + $time_elapsed, 4);
        $this->execution_count++;
        $this->logQuery($prepared_statement->queryString, $vars_to_prepare, $time_elapsed, null, null);

        // check to see if the connection should close after execution
        if (!$this->stay_alive) {
            // disconnect normally
            $this->disconnect();
        }

        // return results of the query
        switch ($query_type) {
            case "query":
                return true;
            case "fetchAll":
                $result = $executed_statement->fetchAll(\PDO::FETCH_ASSOC);
                if ($use_caching) {
                    $this->cacheResult($raw_sql, $vars_to_prepare, $query_type, $cache_timeout, $result, $time_elapsed);
                }
                return $result;
            case "fetchArray":
                $result = $executed_statement->fetchAll(\PDO::FETCH_COLUMN);
                if ($use_caching) {
                    $this->cacheResult($raw_sql, $vars_to_prepare, $query_type, $cache_timeout, $result, $time_elapsed);
                }
                return $result;
            case "fetch":
                $result = $executed_statement->fetch(\PDO::FETCH_ASSOC);
                if ($use_caching) {
                    $this->cacheResult($raw_sql, $vars_to_prepare, $query_type, $cache_timeout, $result, $time_elapsed);
                }
                return $result;
            default:
                throw new \Exception("Incorrect query type '$query_type''", 500);
        }
    }

    /**
     * @param string     $raw_sql
     * @param array      $vars_to_prepare
     * @param bool       $cache_result
     * @param float|null $cache_timeout
     *
     * @return array|mixed
     * @throws \Exception
     */
    public function fetchAll(
        string $raw_sql,
        array $vars_to_prepare = [],
        bool $cache_result = false,
        ?float $cache_timeout = null
    ) {
        $query_type = "fetchAll";
        return $this->query($raw_sql, $vars_to_prepare, $query_type, $cache_result, $cache_timeout);
    }

    /**
     * @param string     $raw_sql
     * @param array      $vars_to_prepare
     * @param bool       $cache_result
     * @param float|null $cache_timeout
     *
     * @return array|mixed
     * @throws \Exception
     */
    public function fetch(
        string $raw_sql,
        array $vars_to_prepare = [],
        bool $cache_result = false,
        ?float $cache_timeout = null
    ) {
        $queryType = "fetch";
        return $this->query($raw_sql, $vars_to_prepare, $queryType, $cache_result, $cache_timeout);
    }

    /**
     * @param string     $raw_sql
     * @param array      $vars_to_prepare
     * @param bool       $cache_result
     * @param float|null $cache_timeout
     *
     * @return array|mixed
     * @throws \Exception
     */
    public function fetchArray(
        string $raw_sql,
        array $vars_to_prepare = [],
        bool $cache_result = false,
        ?float $cache_timeout = null
    ) {
        $queryType = "fetchArray";
        return $this->query($raw_sql, $vars_to_prepare, $queryType, $cache_result, $cache_timeout);
    }

    /**
     * @param $raw_sql
     *
     * @return \PDOStatement
     * @throws \Exception
     */
    private function prepare(string $raw_sql)
    {
        // prepare the statement
        try {
            $statement = $this->connection->prepare($raw_sql);
        } catch (\PDOException $e) {
            $error = $e->getCode();
            throw new \Exception("PDO Exception (code $error)", 500);
        }
        return $statement;
    }

    /**
     * @param \PDOStatement $statement
     * @param               $raw_sql
     * @param array         $vars_to_prepare
     *
     * @return \PDOStatement
     * @throws \Exception
     */
    private function bind(\PDOStatement $statement, string $raw_sql, array $vars_to_prepare)
    {
        // associative binding with ":" (e.g., [:preparedVar] => "prepared value")
        if (array_keys($vars_to_prepare) !== range(0, count($vars_to_prepare) - 1)) {
            foreach ($vars_to_prepare as $key => $value) {
                try {
                    $statement->bindValue(trim($key), trim($value));
                } catch (\PDOException $e) {
                    $error = $e->getMessage();
                    throw new \Exception("PDO Exception ($error)", 500);
                }
            }
        } else { // non-associative binding with "?" (e.g., [0] => "prepared value")
            $array_size = substr_count($raw_sql, '?');
            // fail if the raw sql has an incorrect number of binding points
            $binding_size = count($vars_to_prepare);
            if ($array_size != $binding_size) {
                throw new \Exception("Trying to bind '$binding_size' properties when sql statement has '$array_size' binding points",
                    500);
            }
            foreach ($vars_to_prepare as $key => $value) {
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
        $truncate_result = $this->query($truncate_sql);
        if (!$truncate_result) {
            return false;
        }
        return true;
    }

    /**
     * @param       $raw_sql
     * @param array $vars_to_prepare
     * @param       $query_type
     *
     * @return bool|mixed
     * @throws \Exception
     */
    private function cacheLookup(string $raw_sql, array $vars_to_prepare, string $query_type)
    {
        // hash the raw SQL and values array
        $sql_hash   = md5(serialize($raw_sql));
        $param_hash = md5(serialize($vars_to_prepare));

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
                AND `$type_column` LIKE :query_type
                AND `$expires_column` > NOW()
        ";
        $bind_params = [
            'sql_hash'   => $sql_hash,
            'param_hash' => $param_hash,
            'query_type' => $query_type,
        ];
        $find_result = $this->fetch($find_sql, $bind_params, false);

        // log the lookup
        $this->logCacheLookup($raw_sql, $vars_to_prepare, $query_type);

        if ($find_result) {
            return unserialize($find_result['package']);
        }
        return false;
    }

    /**
     * @param       $raw_sql
     * @param array $vars_to_prepare
     * @param       $query_type
     * @param       $cache_timeout
     * @param       $result
     * @param       $time_elapsed
     *
     * @return bool
     * @throws \Exception
     */
    private function cacheResult(
        string $raw_sql,
        array $vars_to_prepare,
        $query_type,
        $cache_timeout,
        $result,
        $time_elapsed
    ): bool {
        // sanitize cache_timeout
        if (!is_numeric($cache_timeout)) {
            throw new \Exception(__METHOD__ . ": cache timeout needs to be numeric", 500);
        }

        $serialized_result = serialize($result);
        $sql_hash          = md5(serialize($raw_sql));
        $param_hash        = md5(serialize($vars_to_prepare));
        $table             = $this->cache_table_settings['table'];
        $timestamp_column  = $this->cache_table_settings['timestamp_column'];
        $expires_column    = $this->cache_table_settings['expires_column'];
        $sql_column        = $this->cache_table_settings['sql_column'];
        $param_column      = $this->cache_table_settings['param_column'];
        $type_column       = $this->cache_table_settings['type_column'];
        $package_column    = $this->cache_table_settings['package_column'];
        $elapsed_column    = $this->cache_table_settings['elapsed_column'];

        $insert_sql    = "/** @lang MySQL */
            INSERT INTO `$table`
                (`$expires_column`,`$sql_column`,`$param_column`,`$type_column`,`$package_column`,`$elapsed_column`)
            VALUES
                (NOW() + interval $cache_timeout minute,:sql_hash,:param_hash,:query_type,:serialized_result,:queryTime)
            ON DUPLICATE KEY
                UPDATE
                    `$timestamp_column` = NOW(),
                    `$package_column` = :serializedResultUpdate,
                    `$expires_column` = NOW() + interval $cache_timeout minute
        ";
        $bind_params   = [
            "sql_hash"               => $sql_hash,
            "param_hash"             => $param_hash,
            "query_type"             => $query_type,
            "serialized_result"      => $serialized_result,
            "serializedResultUpdate" => $serialized_result,
            "queryTime"              => $time_elapsed,
        ];
        $insert_result = $this->query($insert_sql, $bind_params, 'query', false);
        if ($insert_result) {
            return true;
        }
        return false;
    }

    /**
     * @param $query
     * @param $bound_vars
     * @param $elapsed
     * @param $file
     * @param $line
     */
    public function logQuery($query, $bound_vars, $elapsed, $file, $line)
    {
        $this->queries[] = [
            'query'   => $query,
            'params'  => $bound_vars,
            'elapsed' => $elapsed,
            'file'    => $file,
            'line'    => $line,
        ];
    }

    /**
     * @param $query
     * @param $bound_vars
     * @param $query_type
     */
    public function logCacheLookup($query, $bound_vars, $query_type)
    {
        $this->cache_lookups[] = [
            'query'  => $query,
            'params' => $bound_vars,
            'type'   => $query_type,
        ];
    }

}