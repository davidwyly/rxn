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

namespace Rxn\Framework\Data;

use \Rxn\Framework\BaseConfig;
use \Rxn\Framework\BaseDatasource;
use \Rxn\Framework\Error\DatabaseException;

class Database
{

    const DEFAULT_READ = 'DATABASE_READ';

    const DEFAULT_WRITE = 'DATABASE_WRITE';

    const REQUIRED_PROPERTIES = [
        'HOST',
        'NAME',
        'USERNAME',
        'PASSWORD',
        'CHARSET',
    ];

    /**
     * @var \PDO|null
     */
    private $connection;

    /**
     * @var string
     */
    private $source;

    /**
     * @var string
     */
    private $host;

    /**
     * @var string
     */
    private $name;

    /**
     * @var string
     */
    private $username;

    /**
     * @var string
     */
    private $password;

    /**
     * @var string
     */
    private $charset = 'utf8';

    /**
     * @var int
     */
    private $transaction_depth;

    /**
     * @var bool
     */
    private $in_transaction = false;

    /**
     * @var bool
     */
    private $using_cache = false;

    public function __construct(string $source_name = null)
    {
        if (is_null($source_name)) {
            $source_name = self::DEFAULT_READ;
        }
        $this->setConfiguration($source_name);
        //$this->connect();
    }

    /**
     * @param string $source_name
     *
     * @throws \Exception
     */
    private function setConfiguration(string $source_name)
    {
        foreach (self::REQUIRED_PROPERTIES as $required_property) {
            $env_key = $source_name . '_' . $required_property;
            $matching_env_value = getenv($env_key);
            if ($matching_env_value === false) {
                throw new \Exception("Cannot get value for .env key '$env_key'");
            }
            $property = mb_strtolower($required_property);
            if (!property_exists($this, $property)) {
                throw new \Exception("Cannot set value for .env key '$env_key'");

            }
            $this->{$property} = $matching_env_value;
        }
    }

    public function createQuery(string $type, string $sql, array $bindings = [])
    {
        $query = new Query($this->connection, $type, $sql, $bindings);
        $query->setInTransaction($this->in_transaction);
        return $query;
    }

    public function connect(\PDO $connection = null)
    {
        if (is_null($connection)) {
            $connection = $this->createConnection();
        }
        return $this->connection = $connection;
    }

    private function createConnection()
    {
        $host    = $this->host;
        $name    = $this->name;
        $charset = $this->charset;
        try {
            $connection = new \PDO("mysql:host=$host;dbname=$name;charset=$charset", $this->username, $this->password);
        } catch (\PDOException $exception) {
            $error = $exception->getMessage();
            throw new DatabaseException("PDO Exception (code $error)", 500, $exception);
        }
        $connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $connection;
    }

    public function getHost()
    {
        return $this->host;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getUsername()
    {
        return $this->username;
    }

    public function getPassword()
    {
        return $this->password;
    }

    public function getCharset()
    {
        return $this->charset;
    }

    /**
     * @param array $cache_table_settings
     *
     * @return null
     * @throws DatabaseException
     */
    public function setCacheSettings(array $cache_table_settings)
    {
        $required_keys = array_keys($this->cache_table_settings);
        foreach ($required_keys as $required_key) {
            if (!array_key_exists($required_key, $cache_table_settings)) {
                throw new DatabaseException("Required key '$required_key' missing", 500);
            }
        }
        $this->cache_table_settings = $cache_table_settings;
        return null;
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
     * @param string $sql
     * @param array  $bindings
     *
     * @return array|mixed
     * @throws \Rxn\Framework\Error\QueryException
     */
    public function fetchAll(string $sql, array $bindings = [])
    {
        return $this->createQuery(Query::TYPE_FETCH_ALL, $sql, $bindings)->run();
    }

    /**
     * @param string $sql
     * @param array  $bindings
     *
     * @return array|mixed
     * @throws \Rxn\Framework\Error\QueryException
     */
    public function fetchArray(string $sql, array $bindings = [])
    {
        return $this->createQuery(Query::TYPE_FETCH_ARRAY, $sql, $bindings)->run();
    }

    /**
     * @param string $sql
     * @param array  $bindings
     *
     * @return array|mixed
     * @throws \Rxn\Framework\Error\QueryException
     */
    public function fetch(string $sql, array $bindings = [])
    {
        return $this->createQuery(Query::TYPE_FETCH, $sql, $bindings)->run();
    }

    /**
     * @return bool
     * @throws DatabaseException
     */
    public function transactionOpen()
    {
        if (!empty($this->transaction_depth)) {
            $this->transaction_depth++;
            return true;
        }
        try {
            $this->connection->beginTransaction();
        } catch (\PDOException $exception) {
            throw new DatabaseException($exception->getMessage(), 500, $exception);
        }
        $this->transaction_depth++;
        $this->in_transaction = true;
        return true;
    }


    /**
     * @return bool
     * @throws DatabaseException
     */
    public function transactionClose()
    {
        if ($this->transaction_depth < 1) {
            throw new DatabaseException(__METHOD__ . ": transaction does not exist", 500);
        }
        if ($this->transaction_depth > 1) {
            $this->transaction_depth--;
            return true;
        }
        try {
            $this->connection->commit();
        } catch (\PDOException $exception) {
            $error = $exception->getCode();
            throw new DatabaseException("PDO Exception (code $error)", 500, $exception);
        }
        return true;
    }

    /**
     * @return bool
     * @throws DatabaseException
     */
    private function transactionRollback()
    {
        if ($this->transaction_depth < 1) {
            throw new DatabaseException(__METHOD__ . ": transaction does not exist", 500);
        }
        try {
            $this->connection->rollBack();
        } catch (\PDOException $exception) {
            $error = $exception->getCode();
            throw new DatabaseException("PDO Exception (code $error)", 500, $exception);
        }
        $this->transaction_depth--;
        return true;
    }

    /**
     * @return bool
     * @throws DatabaseException
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
     *
     */
    public function enableCache()
    {
        $this->using_cache = true;
    }

}
