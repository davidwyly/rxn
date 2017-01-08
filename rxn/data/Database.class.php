<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn\Data;

use \Rxn\Config as Config;
use \Rxn\Utility\Debug as Debug;

/**
 * Class Database
 *
 * @package Rxn\Data
 */
class Database {

    /**
     * @var array
     */
    private $defaultSettings = [
        'host' => null,
        'name' => null,
        'username' => null,
        'password' => null,
        'charset' => 'utf8',
    ];

    /**
     * @var array
     */
    private $cacheTableSettings = [
        'table' => null,
        'expiresColumn' => null,
        'sqlColumn' => null,
        'paramColumn' => null,
        'typeColumn' => null,
        'packageColumn' => null,
        'elapsedColumn' => null,
    ];

    /**
     * @var bool
     */
    public $allowCaching = false;

    /**
     * @var int
     */
    private $defaultCacheTimeout = 5;

    /**
     * @var
     */
    private $cacheLookups;

    /**
     * @var \PDO
     */
    private $connection;

    /**
     * @var int
     */
    private $executionCount = 0;

    /**
     * @var float
     */
    private $executionSeconds = 0.0;

    /**
     * @var
     */
    private $queries;

    /**
     * @var bool
     */
    private $stayAlive = false;

    /**
     * @var int
     */
    private $transactionDepth = 0;

    /**
     * @var int
     */
    private $lastInsertId;

    /**
     * @var int
     */
    private $lastAffectedRows;

    /**
     * Database constructor.
     *
     * @param Config $config
     */
    public function __construct(Config $config)
    {
        $this->setConfiguration($config);
        $this->connect();
    }

    /**
     * @param Config $config
     *
     * @throws \Exception
     */
    private function setConfiguration(Config $config) {
        $this->setDefaultSettings($config->databaseDefaultSettings);
        $this->allowCaching = $config->useQuerycaching;
    }

    /**
     * @return mixed
     */
    public function getHost() {
        return $this->defaultSettings['host'];
    }

    /**
     * @return mixed
     */
    public function getName() {
        return $this->defaultSettings['name'];
    }

    /**
     * @return mixed
     */
    public function getUsername() {
        return $this->defaultSettings['username'];
    }

    /**
     * @return mixed
     */
    public function getPassword() {
        return $this->defaultSettings['password'];
    }

    /**
     * @return mixed
     */
    public function getCharset() {
        return $this->defaultSettings['charset'];
    }

    /**
     * @return mixed
     */
    public function getLastInsertId() {
        return $this->lastInsertId;
    }

    public function getLastAffectedRows() {
        return $this->lastAffectedRows;
    }

    /**
     * @param array $defaultSettings
     *
     * @return null
     * @throws \Exception
     */
    public function setDefaultSettings(array $defaultSettings) {
        $requiredKeys = array_keys($this->defaultSettings);
        foreach ($requiredKeys as $requiredKey) {
            if (!array_key_exists($requiredKey,$defaultSettings)) {
                throw new \Exception("Required key '$requiredKey' missing",500);
            }
        }
        $this->defaultSettings = $defaultSettings;
        return null;
    }

    /**
     * @param array $cacheTableSettings
     *
     * @return null
     * @throws \Exception
     */
    public function setCacheSettings(array $cacheTableSettings) {
        $requiredKeys = array_keys($this->cacheTableSettings);
        foreach ($requiredKeys as $requiredKey) {
            if (!array_key_exists($requiredKey,$cacheTableSettings)) {
                throw new \Exception("Required key '$requiredKey' missing",500);
            }
        }
        $this->cacheTableSettings = $cacheTableSettings;
        return null;
    }

    /**
     * @return array
     */
    public function getDefaultSettings() {
        return (array)$this->defaultSettings;
    }

    /**
     * @return array
     */
    public function getCacheTableSettings() {
        return (array)$this->cacheTableSettings;
    }

    /**
     * @return \PDO
     */
    public function getConnection() {
        return $this->connection;
    }

    /**
     * @param \PDO|null $connection
     * @param bool      $stayAlive
     *
     * @return bool
     * @throws \Exception
     */
    public function transactionOpen (\PDO $connection = null, $stayAlive = true) {
        $this->verifyConnection();
        $connection = $this->connection;
        if (!empty($this->transactionDepth)) {
            $this->transactionDepth++;
            return true;
        }
        try {
            /** @var $connection \PDO */
            $connection->beginTransaction();
        } catch (\PDOException $e) {
            throw new \Exception($e->getMessage(),500);
        }
        $this->transactionDepth++;
        $this->stayAlive = true;
        return true;
    }

    /**
     * @return bool
     * @throws \Exception
     */
    private function verifyConnection() {
        if (empty($this->connection)) {
            throw new \Exception(__METHOD__ . ": connection does not exist",500);
        }
        return true;
    }

    /**
     * @return bool
     * @throws \Exception
     */
    public function transactionClose () {
        $this->verifyConnection();
        $connection = $this->connection;
        if ($this->transactionDepth < 1) {
            throw new \Exception(__METHOD__ . ": transaction does not exist",500);
        }
        if ($this->transactionDepth > 1) {
            $this->transactionDepth--;
            return true;
        }
        try {
            /** @var $connection \PDO */
            $connection->commit();
        } catch (\PDOException $e) {
            $error = $e->getCode();
            throw new \Exception("PDO Exception (code $error)",500);
        }
        return true;
    }

    /**
     * @return bool
     * @throws \Exception
     */
    private function transactionRollback () {
        $this->verifyConnection();
        $connection = $this->connection;
        if ($this->transactionDepth < 1) {
            throw new \Exception(__METHOD__ . ": transaction does not exist",500);
        }
        try {
            /** @var $connection \PDO */
            $connection->rollBack();
        } catch (\PDOException $e) {
            $error = $e->getCode();
            throw new \Exception("PDO Exception (code $error)",500);
        }
        $this->transactionDepth--;
        return true;
    }

    /**
     * @return array
     */
    public function getAttributes() {
        $connection = $this->connection;
        $attributes = [
            "AUTOCOMMIT", "ERRMODE", "CASE", "CLIENT_VERSION", "CONNECTION_STATUS",
            "ORACLE_NULLS", "PERSISTENT", "PREFETCH", "SERVER_INFO", "SERVER_VERSION",
            "TIMEOUT"
        ];

        $response = [];
        foreach ($attributes as $val) {
            $key = "PDO::ATTR_$val";
            try {
                /* @var $connection \PDO */
                $response[$key] = $connection->getAttribute(constant("PDO::ATTR_$val"));
            } catch (\PDOException $e) {
                continue;
            }
        }
        return $response;
    }

    /**
     * @param \PDO|null $connection
     * @param bool      $stayAlive
     *
     * @return \PDO
     * @throws \Exception
     */
    public function connect(\PDO $connection = null, $stayAlive = false) {
        if (is_null($connection)) {
            $connection = $this->createConnection();
        }
        $this->stayAlive = $stayAlive;
        // set connection to static variable and return connection
        return $this->connection = $connection;
    }

    /**
     * @return \PDO
     * @throws \Exception
     */
    public function createConnection() {
        $host = $this->getHost();
        $name = $this->getName();
        $charset = $this->getCharset();
        try {
            $connection = new \PDO(
                "mysql:host=$host;dbname=$name;charset=$charset",
                $this->getUsername(),
                $this->getPassword()
            );
        } catch (\PDOException $e) {
            $error = $e->getMessage();
            throw new \Exception("PDO Exception (code $error)",500);
        }
        $connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $connection;
    }

    /**
     * @return bool
     * @throws \Exception
     */
    public function disconnect() {
        if ($this->transactionDepth > 0) {
            $this->transactionRollback();
        }
        $this->stayAlive = false;
        $this->transactionDepth = 0;
        $this->connection = null;
        return true;
    }

    /**
     * @param $rawSql
     *
     * @return array
     */
    public function splitStatement($rawSql) {
        $splitSqlArray = array();
        $multipleQueries = preg_split('#[\;]+#', $rawSql, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($multipleQueries as $key=>$splitSql) {
            $trimmedSplitSql = trim($splitSql);
            if (!empty($trimmedSplitSql)) {
                $splitSqlArray[] = $trimmedSplitSql;
            }
        }
        return $splitSqlArray;
    }

    /**
     * @param $rawSql
     *
     * @return array|null
     */
    public function getTransactionProblemStatements($rawSql) {
        $problemStatements = null;

        // list of statements that cause an implicit commit
        // source: http://dev.mysql.com/doc/refman/5.0/en/implicit-commit.html
        $implicitCommitStatements = [
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
        foreach ($implicitCommitStatements as $problemStatement) {
            if (mb_stripos($rawSql,$problemStatement) !== false) {
                $problemStatements[] = $problemStatement;
            }
        }
        return $problemStatements;
    }

    /**
     * @param        $rawSql
     * @param array  $varsToPrepare
     * @param string $queryType
     * @param bool   $useCaching
     * @param null   $cacheTimeout
     *
     * @return array|mixed
     * @throws \Exception
     */
    public function query($rawSql, array $varsToPrepare = array(), $queryType = 'query', $useCaching = false, $cacheTimeout = null) {
        // check global caching settings
        if ($this->allowCaching === false) {
            $useCaching = false;
        }

        // check if an existing connection exists
        if (!$this->connection) {
            // connect to the default connection settings if necessary
            if (!$this->connect()) {
                throw new \Exception("Connection could not be created",500);
            }
        } else {
            $this->stayAlive = true;
        }

        if ($useCaching) {
            if (!is_numeric($cacheTimeout)) {
                $cacheTimeout = $this->defaultCacheTimeout;
            }
            $cache = $this->cacheLookup($rawSql,$varsToPrepare,$queryType);
            if ($cache) {
                return $cache;
            }
        }

        // verify that statements which may cause an implicit commit are not used in transactions
        if ($this->transactionDepth > 0) {
            $problemStatements = $this->getTransactionProblemStatements($rawSql);
            if (is_array($problemStatements)) {
                $problems = implode(', ',$problemStatements);
                throw new \Exception("Transactions used when implicit commit statements exist in query: $problems",500);
            }
        }

        // prepare the statement
        $preparedStatement = $this->prepare($rawSql);
        if (!$preparedStatement) {
            throw new \Exception("Could not prepare statement",500);
        }

        // check for values to bind
        if (!empty($varsToPrepare)) {
            // bind values to the the prepared statement
            $preparedStatement = $this->bind($preparedStatement, $rawSql, $varsToPrepare);
            if (!$preparedStatement) {
                throw new \Exception("Could not bind prepared statement",500);
            }
        }

        // execute the statement
        $timeStart = microtime(true);
        $executedStatement = $this->execute($preparedStatement);
        $timeElapsed = microtime(true) - $timeStart;

        // set the static 'lastInsertId' property
        $connection = $this->connection; /** @var $connection \PDO */
        $this->lastInsertId = $connection->lastInsertId();
        $this->lastAffectedRows = $executedStatement->rowCount();

        // validate the response
        if (!$executedStatement) {
            $varString = implode("','",$varsToPrepare);
            throw new \Exception("Could not execute prepared statement: '$rawSql' (prepared values: '$varString')",500);
        }

        // log the execution event
        $this->executionSeconds = round($this->executionSeconds + $timeElapsed,4);
        $this->executionCount++;
        $this->logQuery($preparedStatement->queryString, $varsToPrepare, $timeElapsed,null,null);

        // check to see if the connection should close after execution
        if (!$this->stayAlive) {
            // disconnect normally
            $this->disconnect();
        }

        // return results of the query
        switch ($queryType) {
            case "query":
                return true;
            case "fetchAll":
                $result = $executedStatement->fetchAll(\PDO::FETCH_ASSOC);
                if ($useCaching) {
                    $this->cacheResult($rawSql,$varsToPrepare,$queryType,$cacheTimeout,$result,$timeElapsed);
                }
                return $result;
            case "fetchArray":
                $result = $executedStatement->fetchAll(\PDO::FETCH_COLUMN);
                if ($useCaching) {
                    $this->cacheResult($rawSql,$varsToPrepare,$queryType,$cacheTimeout,$result,$timeElapsed);
                }
                return $result;
            case "fetch":
                $result = $executedStatement->fetch(\PDO::FETCH_ASSOC);
                if ($useCaching) {
                    $this->cacheResult($rawSql,$varsToPrepare,$queryType,$cacheTimeout,$result,$timeElapsed);
                }
                return $result;
            default:
                throw new \Exception("Incorrect query type '$queryType''",500);
        }
    }

    /**
     * @param       $rawSql
     * @param array $varsToPrepare
     * @param bool  $cacheResult
     * @param null  $cacheTimeout
     *
     * @return array|mixed
     * @throws \Exception
     */
    public function fetchAll($rawSql, array $varsToPrepare = array(), $cacheResult = false, $cacheTimeout = null) {
        $queryType = "fetchAll";
        return $this->query($rawSql,$varsToPrepare,$queryType,$cacheResult,$cacheTimeout);
    }

    /**
     * @param       $rawSql
     * @param array $varsToPrepare
     * @param bool  $cacheResult
     * @param null  $cacheTimeout
     *
     * @return array|mixed
     * @throws \Exception
     */
    public function fetch($rawSql, array $varsToPrepare = array(), $cacheResult = false, $cacheTimeout = null) {
        $queryType = "fetch";
        return $this->query($rawSql,$varsToPrepare,$queryType,$cacheResult,$cacheTimeout);
    }

    /**
     * @param       $rawSql
     * @param array $varsToPrepare
     * @param bool  $cacheResult
     * @param null  $cacheTimeout
     *
     * @return array|mixed
     * @throws \Exception
     */
    public function fetchArray($rawSql, array $varsToPrepare = array(), $cacheResult = false, $cacheTimeout = null) {
        $queryType = "fetchArray";
        return $this->query($rawSql,$varsToPrepare,$queryType,$cacheResult,$cacheTimeout);
    }

    /**
     * @param $rawSql
     *
     * @return \PDOStatement
     * @throws \Exception
     */
    private function prepare($rawSql) {
        // prepare the statement
        try {
            /** @noinspection PhpUndefinedMethodInspection */
            $statement = $this->connection->prepare($rawSql);
        } catch (\PDOException $e) {
            $error = $e->getCode();
            throw new \Exception("PDO Exception (code $error)",500);
        }
        return $statement;
    }

    /**
     * @param \PDOStatement $statement
     * @param               $rawSql
     * @param array         $varsToPrepare
     *
     * @return \PDOStatement
     * @throws \Exception
     */
    private function bind(\PDOStatement $statement, $rawSql, array $varsToPrepare)
    {
        // associative binding with ":" (e.g., [:preparedVar] => "prepared value")
        if (array_keys($varsToPrepare) !== range(0, count($varsToPrepare) - 1)) {
            foreach ($varsToPrepare as $key => $value) {
                try {
                    $statement->bindValue(trim($key), trim($value));
                } catch (\PDOException $e) {
                    $error = $e->getMessage();
                    throw new \Exception("PDO Exception ($error)",500);
                }
            }
        } else { // non-associative binding with "?" (e.g., [0] => "prepared value")
            $arraySize = substr_count($rawSql, '?');
            // fail if the raw sql has an incorrect number of binding points
            $bindingSize = count($varsToPrepare);
            if ($arraySize != $bindingSize) {
                throw new \Exception("Trying to bind '$bindingSize' properties when sql statement has '$arraySize' binding points",500);
            }
            foreach ($varsToPrepare as $key => $value) {
                if (!is_string($value) && !is_null($value) && !is_int($value) && !is_float($value)) {
                    throw new \Exception("Can only bind types: string, null, int, float",500);
                }
                $nextKey = $key + 1;
                try {
                    $statement->bindValue($nextKey, trim($value));
                } catch (\PDOException $e) {
                    $error = $e->getMessage();
                    throw new \Exception("PDO Exception ($error)",500);
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
    private function execute(\PDOStatement $statement) {
        // execute the statement
        try {
            $statement->execute();
        } catch (\PDOException $e) {
            $error = $e->getMessage();
            throw new \Exception("PDO Exception ($error)",500);
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
    public function defineDefaultSettings($host,$name,$username,$password,$charset='utf8') {
        $defaultSettings = [
            'host' => $host,
            'name' => $name,
            'username' => $username,
            'password' => $password,
            'charset' => $charset,
        ];
        return $defaultSettings;
    }

    /**
     * @return bool
     * @throws \Exception
     */
    public function clearCache() {
        $cacheTable = $this->cacheTableSettings['table'];
        $truncateSql = "TRUNCATE TABLE `$cacheTable`;";
        $truncateResult = $this->query($truncateSql);
        if (!$truncateResult) {
            return false;
        }
        return true;
    }

    /**
     * @param       $rawSql
     * @param array $varsToPrepare
     * @param       $queryType
     *
     * @return bool|mixed
     */
    private function cacheLookup($rawSql, array $varsToPrepare, $queryType) {
        // hash the raw SQL and values array
        $sqlHash = md5(serialize($rawSql));
        $paramHash = md5(serialize($varsToPrepare));

        $table = $this->cacheTableSettings['table'];
        $sqlColumn = $this->cacheTableSettings['sqlColumn'];
        $paramColumn = $this->cacheTableSettings['paramColumn'];
        $typeColumn = $this->cacheTableSettings['typeColumn'];
        $expiresColumn = $this->cacheTableSettings['expiresColumn'];

        $findSql = "
            SELECT *
            FROM `$table`
            WHERE `$sqlColumn` LIKE :sqlHash
                AND `$paramColumn` LIKE :paramHash
                AND `$typeColumn` LIKE :queryType
                AND `$expiresColumn` > NOW()
        ";
        $bindParams = [
            'sqlHash'=>$sqlHash,
            'paramHash'=>$paramHash,
            'queryType'=>$queryType
        ];
        $findResult = $this->fetch($findSql,$bindParams,false);

        // log the lookup
        $this->logCacheLookup($rawSql,$varsToPrepare,$queryType);

        if ($findResult) {
            return unserialize($findResult['package']);
        }
        return false;
    }

    /**
     * @param       $rawSql
     * @param array $varsToPrepare
     * @param       $queryType
     * @param       $cacheTimeout
     * @param       $result
     * @param       $timeElapsed
     *
     * @return bool
     * @throws \Exception
     */
    private function cacheResult($rawSql, array $varsToPrepare, $queryType, $cacheTimeout, $result, $timeElapsed) {
        // sanitize cacheTimeout
        if (!is_numeric($cacheTimeout)) {
            throw new \Exception(__METHOD__ . ": cache timeout needs to be numeric",500);
        }

        $serializedResult = serialize($result);
        $sqlHash = md5(serialize($rawSql));
        $paramHash = md5(serialize($varsToPrepare));
        $table =$this->cacheTableSettings['table'];
        $timestampColumn = $this->cacheTableSettings['timestampColumn'];
        $expiresColumn = $this->cacheTableSettings['expiresColumn'];
        $sqlColumn = $this->cacheTableSettings['sqlColumn'];
        $paramColumn = $this->cacheTableSettings['paramColumn'];
        $typeColumn = $this->cacheTableSettings['typeColumn'];
        $packageColumn = $this->cacheTableSettings['packageColumn'];
        $elapsedColumn = $this->cacheTableSettings['elapsedColumn'];
        $insertSql = "
            INSERT INTO `$table`
                (`$expiresColumn`,`$sqlColumn`,`$paramColumn`,`$typeColumn`,`$packageColumn`,`$elapsedColumn`)
            VALUES
                (NOW() + interval $cacheTimeout minute,:sqlHash,:paramHash,:queryType,:serializedResult,:queryTime)
            ON DUPLICATE KEY
                UPDATE
                    `$timestampColumn` = NOW(),
                    `$packageColumn` = :serializedResultUpdate,
                    `$expiresColumn` = NOW() + interval $cacheTimeout minute
        ";
        $bindParams = [
            "sqlHash" => $sqlHash,
            "paramHash" => $paramHash,
            "queryType" => $queryType,
            "serializedResult" => $serializedResult,
            "serializedResultUpdate" => $serializedResult,
            "queryTime" => $timeElapsed,
        ];
        $insertResult = $this->query($insertSql,$bindParams,'query',false);
        if ($insertResult) {
            return true;
        }
        return false;
    }

    /**
     * @param $query
     * @param $boundVars
     * @param $elapsed
     * @param $file
     * @param $line
     */
    public function logQuery($query,$boundVars,$elapsed,$file,$line) {
        $this->queries[] = [
            'query' => $query,
            'params' => $boundVars,
            'elapsed' => $elapsed,
            'file' => $file,
            'line' => $line,
        ];
    }

    /**
     * @param $query
     * @param $boundVars
     * @param $queryType
     */
    public function logCacheLookup($query,$boundVars,$queryType) {
        $this->cacheLookups[] = [
            'query' => $query,
            'params' => $boundVars,
            'type',
        ];
    }

}