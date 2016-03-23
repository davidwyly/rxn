<?php
/**
 *
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 *
 * For full copyright and license information, please see the docs/CREDITS.txt file.
 *
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
 *
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
     * @var
     */
    private $lastInsertId;

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
    
    private function setConfiguration(Config $config) {
        $this->setDefaultSettings($config->databaseDefaultSettings);
        $this->setCacheSettings($config->databaseCacheSettings);
        $this->allowCaching = $config->allowCaching;
    }

    public function getHost() {
        return $this->defaultSettings['host'];
    }

    public function getName() {
        return $this->defaultSettings['name'];
    }

    public function getUsername() {
        return $this->defaultSettings['username'];
    }

    public function getPassword() {
        return $this->defaultSettings['password'];
    }

    public function getCharset() {
        return $this->defaultSettings['charset'];
    }

    public function getLastInsertId() {
        return $this->lastInsertId;
    }

    public function setDefaultSettings(array $defaultSettings) {
        $requiredKeys = array_keys($this->defaultSettings);
        foreach ($requiredKeys as $requiredKey) {
            if (!array_key_exists($requiredKey,$defaultSettings)) {
                throw new \Exception("Required key '$requiredKey' missing");
            }
        }
        $this->defaultSettings = $defaultSettings;
        return null;
    }

    public function setCacheSettings(array $cacheTableSettings) {
        $requiredKeys = array_keys($this->cacheTableSettings);
        foreach ($requiredKeys as $requiredKey) {
            if (!array_key_exists($requiredKey,$cacheTableSettings)) {
                throw new \Exception("Required key '$requiredKey' missing");
            }
        }
        $this->cacheTableSettings = $cacheTableSettings;
        return null;
    }

    public function getDefaultSettings() {
        return (array)$this->defaultSettings;
    }

    public function getCacheTableSettings() {
        return (array)$this->cacheTableSettings;
    }

    public function getConnection() {
        return $this->connection;
    }

    public function transactionOpen (\PDO $connection = null, $stayAlive = true)
    {
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

    private function verifyConnection() {
        if (empty($this->connection)) {
            throw new \Exception(__METHOD__ . ": connection does not exist",500);
        }
        return true;
    }

    public function transactionClose ()
    {
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

    private function transactionRollback ()
    {
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

    public function connect(\PDO $connection = null, $stayAlive = false)
    {
        if (is_null($connection)) {
            $connection = $this->createConnection();
        }
        $this->stayAlive = $stayAlive;
        // set connection to static variable and return connection
        return $this->connection = $connection;
    }

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



    public function disconnect()
    {
        if ($this->transactionDepth > 0) {
            $this->transactionRollback();
        }
        $this->stayAlive = false;
        $this->transactionDepth = 0;
        $this->connection = null;
        return true;
    }

    public function splitStatement($rawSql)
    {
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

    public function query($rawSql, array $varsToPrepare = array(), $queryType = 'query', $useCaching = false, $cacheTimeout = null)
    {
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
                throw new \Exception("Incorrect query type");
        }
    }

    public function fetchAll($rawSql, array $varsToPrepare = array(), $cacheResult = false, $cacheTimeout = null)
    {
        $queryType = "fetchAll";
        return $this->query($rawSql,$varsToPrepare,$queryType,$cacheResult,$cacheTimeout);
    }

    public function fetch($rawSql, array $varsToPrepare = array(), $cacheResult = false, $cacheTimeout = null)
    {
        $queryType = "fetch";
        return $this->query($rawSql,$varsToPrepare,$queryType,$cacheResult,$cacheTimeout);
    }

    public function fetchArray($rawSql, array $varsToPrepare = array(), $cacheResult = false, $cacheTimeout = null)
    {
        $queryType = "fetchArray";
        return $this->query($rawSql,$varsToPrepare,$queryType,$cacheResult,$cacheTimeout);
    }

    private function prepare($rawSql)
    {
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

    private function execute(\PDOStatement $statement)
    {
        // execute the statement
        try {
            $statement->execute();
        } catch (\PDOException $e) {
            $error = $e->getMessage();
            Debug::dump(debug_backtrace());
            throw new \Exception("PDO Exception ($error)",500);
        }
        return $statement;
    }

    public function defineDefaultSettings($host,$name,$username,$password,$charset='utf8')
    {
        $defaultSettings = [
            'host' => $host,
            'name' => $name,
            'username' => $username,
            'password' => $password,
            'charset' => $charset,
        ];
        return $defaultSettings;
    }

    public function clearCache()
    {
        $cacheTable = $this->cacheTableSettings['table'];
        $truncateSql = "TRUNCATE TABLE `$cacheTable`;";
        $truncateResult = Database::query($truncateSql);
        if (!$truncateResult) {
            return false;
        }
        return true;
    }

    private function cacheLookup($rawSql, array $varsToPrepare, $queryType)
    {
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
        $findResult = Database::fetch($findSql,$bindParams,false);

        // log the lookup
        $this->logCacheLookup($rawSql,$varsToPrepare,$queryType);

        if ($findResult) {
            return unserialize($findResult['package']);
        }
        return false;
    }

    private function cacheResult($rawSql, array $varsToPrepare, $queryType, $cacheTimeout, $result, $timeElapsed)
    {
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
        $insertResult = Database::query($insertSql,$bindParams,'query',false);
        if ($insertResult) {
            return true;
        }
        return false;
    }


    public function logQuery($query,$boundVars,$elapsed,$file,$line) {
        $this->queries[] = [
            'query' => $query,
            'params' => $boundVars,
            'elapsed' => $elapsed,
            'file' => $file,
            'line' => $line,
        ];
    }

    public function logCacheLookup($query,$boundVars,$queryType) {
        $this->cacheLookups[] = [
            'query' => $query,
            'params' => $boundVars,
            'type',
        ];
    }

}