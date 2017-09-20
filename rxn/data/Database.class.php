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

use \Rxn\Config;
use \Rxn\Datasources;
use \Rxn\Error\DatabaseException;

class Database
{
    /**
     * @var \PDO|null
     */
    private $connection;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var Datasources
     */
    private $datasources;

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

    public function __construct(Config $config, Datasources $datasources, string $source = null)
    {
        $this->config = $config;
        $this->datasources = $datasources;
        $this->soruce = $source;


        if (is_null($this->source)) {
            $this->source = Datasources::DEFAULT_READ;
        }
        $this->setConfiguration();
        $this->connect();
    }

    /**
     * @throws DatabaseException
     */
    private function setConfiguration()
    {

        $databases = $this->datasources->getDatabases();
        $this->setConnectionSettings($databases[$this->source]);
    }

    private function setConnectionSettings(array $database_settings)
    {
        foreach ($this->datasources->getRequiredFields() as $required_field) {
            if (!array_key_exists($required_field, $database_settings)) {
                throw new DatabaseException("Required database setting '$required_field' is missing");
            }
            $this->{$required_field} = $database_settings[$required_field];
        }
        if (!in_array($this->source, $this->datasources->getAllowedSources())) {
            throw new DatabaseException("Data source '$this->source' is not whitelisted");
        }
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

    public function connect(\PDO $connection = null)
    {
        if (is_null($connection)) {
            $connection = $this->createConnection();
        }
        return $this->connection = $connection;
    }

    public function createConnection()
    {
        $host    = $this->host;
        $name    = $this->name;
        $charset = $this->charset;
        try {
            $connection = new \PDO(
                "mysql:host=$host;dbname=$name;charset=$charset",
                $this->username,
                $this->password
            );
        } catch (\PDOException $e) {
            $error = $e->getMessage();
            throw new DatabaseException("PDO Exception (code $error)", 500, $e);
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