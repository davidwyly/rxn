<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author  David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn\Data;

use \Rxn\Error\DatabaseException;
use \Rxn\Config;
use \Rxn\Datasources;

/**
 * Class Database
 *
 * @package Rxn\Data
 */
class Database
{
    /**
     * @var \PDO|null
     */
    private $connection;

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
     * Database constructor.
     *
     * @param Config      $config
     * @param Datasources $datasources
     * @param string|null $source_name
     *
     * @throws DatabaseException
     */
    public function __construct(Config $config, Datasources $datasources, string $source_name = null)
    {
        if (is_null($source_name)) {
            $source_name = $datasources->default_read;
        }
        $this->setConfiguration($config, $datasources, $source_name);
        $this->connect();
    }

    public function createQuery(
        string $sql,
        array $bindings = [],
        string $type,
        bool $caching = false,
        $timeout = null
    ) {
        return new Query($this->connection, $sql, $bindings, $type, $caching, $timeout);
    }

    /**
     * @param Config      $config
     * @param Datasources $datasources
     * @param string      $source_name
     *
     * @throws DatabaseException
     */
    private function setConfiguration(Config $config, Datasources $datasources, string $source_name)
    {
        $databases = $datasources->getDatabases();
        $this->setDefaultSettings($databases[$source_name]);
        $this->allow_caching = $config->use_query_caching;
    }

    /**
     * @param \PDO|null $connection
     *
     * @return \PDO
     * @throws DatabaseException
     */
    public function connect(\PDO $connection = null)
    {
        if (is_null($connection)) {
            $connection = $this->createConnection();
        }
        return $this->connection = $connection;
    }

    /**
     * @return \PDO
     * @throws DatabaseException
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
            throw new DatabaseException("PDO Exception (code $error)", 500, $e);
        }
        $connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $connection;
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
     * @param array $default_settings
     *
     * @return null
     * @throws DatabaseException
     */
    public function setDefaultSettings(array $default_settings)
    {
        $required_keys = array_keys($this->default_settings);
        foreach ($required_keys as $required_key) {
            if (!array_key_exists($required_key, $default_settings)) {
                throw new DatabaseException("Required key '$required_key' missing", 500);
            }
        }
        $this->default_settings = $default_settings;
        return null;
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
     * @param bool   $cache
     * @param null   $timeout
     *
     * @return array|mixed
     * @throws \Rxn\Error\QueryException
     */
    public function fetchAll(string $sql, array $bindings = [], $cache = false, $timeout = null)
    {
        $type = "fetchAll";
        return $this->createQuery($sql, $bindings, $type, $cache, $timeout)->run();
    }

    /**
     * @param string $sql
     * @param array  $bindings
     * @param bool   $cache
     * @param null   $timeout
     *
     * @return array|mixed
     * @throws \Rxn\Error\QueryException
     */
    public function fetchArray(string $sql, array $bindings = [], $cache = false, $timeout = null)
    {
        $type = "fetchArray";
        return $this->createQuery($sql, $bindings, $type, $cache, $timeout)->run();
    }

    /**
     * @param string $sql
     * @param array  $bindings
     * @param bool   $cache
     * @param null   $timeout
     *
     * @return array|mixed
     * @throws \Rxn\Error\QueryException
     */
    public function fetch(string $sql, array $bindings = [], $cache = false, $timeout = null)
    {
        $type = "fetch";
        return $this->createQuery($sql, $bindings, $type, $cache, $timeout)->run();
    }

}