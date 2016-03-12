<?php

namespace Rxn\Data;

use \Rxn\Config as Config;
use \Rxn\Utility\Debug as Debug;

class Database {

    // set in config via the 'Database::setDefaultConnection()' method
    static private $defaultSettings = [
        'host' => null,
        'name' => null,
        'username' => null,
        'password' => null,
        'charset' => 'utf8',
    ];

    static private $cacheTableSettings = [
        'table' => null,
        'expiresColumn' => null,
        'sqlColumn' => null,
        'paramColumn' => null,
        'typeColumn' => null,
        'packageColumn' => null,
        'elapsedColumn' => null,
    ];

    static private $defaultCacheTimeout = 5;

    static private $cacheLookups;
    static private $connection; // PDO connection
    static private $executionCount = 0;
    static private $executionSeconds = 0.0;
    static private $queries;
    static private $stayAlive = false;
    static private $transactionDepth = 0;
    static private $lastInsertId;

    public function __construct()
    {
        self::setDefaultSettings(Config::$databaseDefaultSettings);
        self::setCacheSettings(Config::$databaseCacheSettings);
    }

    static public function getHost() {
        return self::$defaultSettings['host'];
    }

    static public function getName() {
        return self::$defaultSettings['name'];
    }

    static public function getUsername() {
        return self::$defaultSettings['username'];
    }

    static public function getPassword() {
        return self::$defaultSettings['password'];
    }

    static public function getCharset() {
        return self::$defaultSettings['charset'];
    }

    static public function getLastInsertId() {
        return self::$lastInsertId;
    }

    static public function setDefaultSettings(array $defaultSettings) {
        $requiredKeys = array_keys(self::$defaultSettings);
        foreach ($requiredKeys as $requiredKey) {
            if (!array_key_exists($requiredKey,$defaultSettings)) {
                throw new \Exception("Required key '$requiredKey' missing");
            }
        }
        self::$defaultSettings = $defaultSettings;
        return null;
    }

    static public function setCacheSettings(array $cacheTableSettings) {
        $requiredKeys = array_keys(self::$cacheTableSettings);
        foreach ($requiredKeys as $requiredKey) {
            if (!array_key_exists($requiredKey,$cacheTableSettings)) {
                throw new \Exception("Required key '$requiredKey' missing");
            }
        }
        self::$cacheTableSettings = $cacheTableSettings;
        return null;
    }

    static public function getDefaultSettings() {
        return (array)self::$defaultSettings;
    }

    static public function getCacheTableSettings() {
        return (array)self::$cacheTableSettings;
    }

    static public function getConnection() {
        return self::$connection;
    }

    static public function transactionOpen (\PDO $connection = null, $stayAlive = true)
    {
        self::verifyConnection();
        $connection = self::$connection;
        if (!empty(self::$transactionDepth)) {
            self::$transactionDepth++;
            return true;
        }
        try {
            /** @var $connection \PDO */
            $connection->beginTransaction();
        } catch (\PDOException $e) {
            throw new \Exception($e->getMessage(),500);
        }
        self::$transactionDepth++;
        self::$stayAlive = true;
        return true;
    }

    static private function verifyConnection() {
        if (empty(self::$connection)) {
            throw new \Exception(__METHOD__ . ": connection does not exist",500);
        }
        return true;
    }

    static public function transactionClose ()
    {
        self::verifyConnection();
        $connection = self::$connection;
        if (self::$transactionDepth < 1) {
            throw new \Exception(__METHOD__ . ": transaction does not exist",500);
        }
        if (self::$transactionDepth > 1) {
            self::$transactionDepth--;
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

    static private function transactionRollback ()
    {
        self::verifyConnection();
        $connection = self::$connection;
        if (self::$transactionDepth < 1) {
            throw new \Exception(__METHOD__ . ": transaction does not exist",500);
        }
        try {
            /** @var $connection \PDO */
            $connection->rollBack();
        } catch (\PDOException $e) {
            $error = $e->getCode();
            throw new \Exception("PDO Exception (code $error)",500);
        }
        self::$transactionDepth--;
        return true;
    }

    static public function getAttributes() {
        $connection = self::$connection;
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

    static public function connect(\PDO $connection = null, $stayAlive = false)
    {
        if (is_null($connection)) {
            $connection = self::createConnection();
        }
        self::$stayAlive = $stayAlive;
        // set connection to static variable and return connection
        return self::$connection = $connection;
    }

    static public function createConnection() {
        $host = self::getHost();
        $name = self::getName();
        $charset = self::getCharset();
        try {
            $connection = new \PDO(
                "mysql:host=$host;dbname=$name;charset=$charset",
                self::getUsername(),
                self::getPassword()
            );
        } catch (\PDOException $e) {
            $error = $e->getMessage();
            throw new \Exception("PDO Exception (code $error)",500);
        }
        $connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $connection;
    }



    static public function disconnect()
    {
        if (self::$transactionDepth > 0) {
            self::transactionRollback();
        }
        self::$stayAlive = false;
        self::$transactionDepth = 0;
        self::$connection = null;
        return true;
    }

    static public function splitStatement($rawSql)
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

    static public function getTransactionProblemStatements($rawSql) {
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

    static public function query($rawSql, array $varsToPrepare = array(), $queryType = 'query', $useCaching = false, $cacheTimeout = null)
    {
        // check if an existing connection exists
        if (!self::$connection) {
            // connect to the default connection settings if necessary
            if (!self::connect()) {
                throw new \Exception("Connection could not be created",500);
            }
        } else {
            self::$stayAlive = true;
        }

        if ($useCaching) {
            if (!is_numeric($cacheTimeout)) {
                $cacheTimeout = self::$defaultCacheTimeout;
            }
            $cache = self::cacheLookup($rawSql,$varsToPrepare,$queryType);
            if ($cache) {
                return $cache;
            }
        }

        // verify that statements which may cause an implicit commit are not used in transactions
        if (self::$transactionDepth > 0) {
            $problemStatements = self::getTransactionProblemStatements($rawSql);
            if (is_array($problemStatements)) {
                $problems = implode(', ',$problemStatements);
                throw new \Exception("Transactions used when implicit commit statements exist in query: $problems",500);
            }
        }

        // prepare the statement
        $preparedStatement = self::prepare($rawSql);
        if (!$preparedStatement) {
            throw new \Exception("Could not prepare statement",500);
        }

        // check for values to bind
        if (!empty($varsToPrepare)) {
            // bind values to the the prepared statement
            $preparedStatement = self::bind($preparedStatement, $rawSql, $varsToPrepare);
            if (!$preparedStatement) {
                throw new \Exception("Could not bind prepared statement",500);
            }
        }

        // execute the statement
        $timeStart = microtime(true);
        $executedStatement = self::execute($preparedStatement);
        $timeElapsed = microtime(true) - $timeStart;

        // set the static 'lastInsertId' property
        $connection = self::$connection; /** @var $connection \PDO */
        self::$lastInsertId = $connection->lastInsertId();

        // validate the response
        if (!$executedStatement) {
            $varString = implode("','",$varsToPrepare);
            throw new \Exception("Could not execute prepared statement: '$rawSql' (prepared values: '$varString')",500);
        }

        // log the execution event
        self::$executionSeconds = round(self::$executionSeconds + $timeElapsed,4);
        self::$executionCount++;
        self::logQuery($preparedStatement->queryString, $varsToPrepare, $timeElapsed,null,null);

        // check to see if the connection should close after execution
        if (!self::$stayAlive) {
            // disconnect normally
            self::disconnect();
        }

        // return results of the query
        switch ($queryType) {
            case "query":
                return true;
            case "fetchAll":
                $result = $executedStatement->fetchAll(\PDO::FETCH_ASSOC);
                if ($useCaching) {
                    self::cacheResult($rawSql,$varsToPrepare,$queryType,$cacheTimeout,$result,$timeElapsed);
                }
                return $result;
            case "fetchArray":
                $result = $executedStatement->fetchAll(\PDO::FETCH_COLUMN);
                if ($useCaching) {
                    self::cacheResult($rawSql,$varsToPrepare,$queryType,$cacheTimeout,$result,$timeElapsed);
                }
                return $result;
            case "fetch":
                $result = $executedStatement->fetch(\PDO::FETCH_ASSOC);
                if ($useCaching) {
                    self::cacheResult($rawSql,$varsToPrepare,$queryType,$cacheTimeout,$result,$timeElapsed);
                }
                return $result;
            default:
                throw new \Exception("Incorrect query type");
        }
    }

    static public function fetchAll($rawSql, array $varsToPrepare = array(), $cacheResult = false, $cacheTimeout = null)
    {
        $queryType = "fetchAll";
        return self::query($rawSql,$varsToPrepare,$queryType,$cacheResult,$cacheTimeout);
    }

    static public function fetch($rawSql, array $varsToPrepare = array(), $cacheResult = false, $cacheTimeout = null)
    {
        $queryType = "fetch";
        return self::query($rawSql,$varsToPrepare,$queryType,$cacheResult,$cacheTimeout);
    }

    static public function fetchArray($rawSql, array $varsToPrepare = array(), $cacheResult = false, $cacheTimeout = null)
    {
        $queryType = "fetchArray";
        return self::query($rawSql,$varsToPrepare,$queryType,$cacheResult,$cacheTimeout);
    }

    static private function prepare($rawSql)
    {
        // prepare the statement
        try {
            /** @noinspection PhpUndefinedMethodInspection */
            $statement = self::$connection->prepare($rawSql);
        } catch (\PDOException $e) {
            $error = $e->getCode();
            throw new \Exception("PDO Exception (code $error)",500);
        }
        return $statement;
    }

    static private function bind(\PDOStatement $statement, $rawSql, array $varsToPrepare)
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

    static private function execute(\PDOStatement $statement)
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

    static public function defineDefaultSettings($host,$name,$username,$password,$charset='utf8')
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

    static public function clearCache()
    {
        $cacheTable = self::$cacheTableSettings['table'];
        $truncateSql = "TRUNCATE TABLE `$cacheTable`;";
        $truncateResult = Database::query($truncateSql);
        if (!$truncateResult) {
            return false;
        }
        return true;
    }

    static private function cacheLookup($rawSql, array $varsToPrepare, $queryType)
    {
        // hash the raw SQL and values array
        $sqlHash = md5(serialize($rawSql));
        $paramHash = md5(serialize($varsToPrepare));

        $table = self::$cacheTableSettings['table'];
        $sqlColumn = self::$cacheTableSettings['sqlColumn'];
        $paramColumn = self::$cacheTableSettings['paramColumn'];
        $typeColumn = self::$cacheTableSettings['typeColumn'];
        $expiresColumn = self::$cacheTableSettings['expiresColumn'];

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
        self::logCacheLookup($rawSql,$varsToPrepare,$queryType);

        if ($findResult) {
            return unserialize($findResult['package']);
        }
        return false;
    }

    static private function cacheResult($rawSql, array $varsToPrepare, $queryType, $cacheTimeout, $result, $timeElapsed)
    {
        // sanitize cacheTimeout
        if (!is_numeric($cacheTimeout)) {
            throw new \Exception(__METHOD__ . ": cache timeout needs to be numeric",500);
        }

        $serializedResult = serialize($result);
        $sqlHash = md5(serialize($rawSql));
        $paramHash = md5(serialize($varsToPrepare));
        $table =self::$cacheTableSettings['table'];
        $timestampColumn = self::$cacheTableSettings['timestampColumn'];
        $expiresColumn = self::$cacheTableSettings['expiresColumn'];
        $sqlColumn = self::$cacheTableSettings['sqlColumn'];
        $paramColumn = self::$cacheTableSettings['paramColumn'];
        $typeColumn = self::$cacheTableSettings['typeColumn'];
        $packageColumn = self::$cacheTableSettings['packageColumn'];
        $elapsedColumn = self::$cacheTableSettings['elapsedColumn'];
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


    static public function logQuery($query,$boundVars,$elapsed,$file,$line) {
        self::$queries[] = [
            'query' => $query,
            'params' => $boundVars,
            'elapsed' => $elapsed,
            'file' => $file,
            'line' => $line,
        ];
    }

    static public function logCacheLookup($query,$boundVars,$queryType) {
        self::$cacheLookups[] = [
            'query' => $query,
            'params' => $boundVars,
            'type',
        ];
    }

}