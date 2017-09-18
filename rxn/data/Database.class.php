<?php
/**
 * This file is part of the Rxn (Reaction) PHP API Framework
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
     * @var string
     */
    private $source;

    /**
     * @var array
     */
    private $required_settings = [
        Datasources::HOST,
        Datasources::NAME,
        Datasources::USERNAME,
        Datasources::PASSWORD,
        Datasources::CHARSET,
    ];

    /**
     * @var array
     */
    private $allowed_sources = [
        Datasources::DEFAULT_READ,
        Datasources::DEFAULT_WRITE,
        Datasources::DEFAULT_ADMIN,
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
            $source_name = Datasources::DEFAULT_READ;
        }
        $this->setConfiguration($config, $datasources, $source_name);
        $this->connect();
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
        $this->setConnectionSettings($databases[$source_name], $source_name);
        $this->allow_caching = $config->use_query_caching;
    }

    private function setConnectionSettings(array $database_settings, $source_name)
    {
        foreach ($this->required_settings as $required_setting) {
            if (!array_key_exists($required_setting, $database_settings)) {
                throw new DatabaseException("Required database setting '$required_setting' is missing");
            }
            $this->{$required_setting} = $database_settings[$required_setting];
        }
        if (!in_array($source_name, $this->allowed_sources)) {
            throw new DatabaseException("Data source '$source_name' is not whitelisted");
        }
        $this->source = $source_name;
    }

    /**
     * @param string $sql
     * @param array $bindings
     * @param string $type
     * @param bool $caching
     * @param null $timeout
     * @return Query
     */
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

    /**
     * @return mixed
     */
    public function getHost()
    {
        return $this->host;
    }

    /**
     * @return mixed
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return mixed
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * @return mixed
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * @return mixed
     */
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