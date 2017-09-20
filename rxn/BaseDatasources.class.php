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

namespace Rxn;

class BaseDatasources extends Service
{
    /**
     * @var string
     */
    const DEFAULT_READ = 'read-only';

    /**
     * @var string
     */
    const DEFAULT_WRITE = 'read-write';

    /**
     * @var string
     */
    const DEFAULT_ADMIN = 'admin';
    const DEFAULT_CACHE = 'cache';

    /**
     * @var string
     */
    const HOST = 'host';

    /**
     * @var string
     */
    const NAME = 'name';

    /**
     * @var string
     */
    const USERNAME = 'username';

    /**
     * @var string
     */
    const PASSWORD = 'password';

    /**
     * @var string
     */
    const CHARSET = 'charset';

    /**
     * @var
     */
    protected $databases;

    /**
     * @var array
     */
    private $allowed_sources = [
        self::DEFAULT_READ,
        self::DEFAULT_WRITE,
        self::DEFAULT_ADMIN,
        self::DEFAULT_CACHE,
    ];

    /**
     * @var array
     */
    private $required_fields = [
        self::HOST,
        self::NAME,
        self::USERNAME,
        self::PASSWORD,
        self::CHARSET,
    ];

    /**
     * @var string
     */
    private $default_source = self::DEFAULT_READ;

    public function __construct()
    {
        $this->validateDatabases($this->databases);
    }

    /**
     * @return array
     */
    public function getAllowedSources()
    {
        return $this->allowed_sources;
    }

    /**
     * @return array
     */
    public function getRequiredFields()
    {
        return $this->required_fields;
    }

    /**
     * @return string
     */
    public function getDefaultSource()
    {
        return $this->default_source;
    }

    private function validateDatabases(array $databases)
    {
        if (empty($this->databases)) {
            throw new \Exception("'Databases' param cannot be set to empty");
        }
        foreach ($databases as $database_name => $connection_settings) {
            if (!is_array($connection_settings)) {
                throw new \Exception ("'Databases' param is malformed");
            }
            foreach ($this->required_fields as $required_field) {
                if (!isset($connection_settings[$required_field])) {
                    throw new \Exception("Database config with key '$database_name' is missing "
                        . "required field '$required_field'");
                }
            }
        }
    }

    /**
     * @return mixed
     */
    public function getDatabases()
    {
        return $this->databases;
    }

    /**
     * @param array $databases
     */
    public function setDatabases(array $databases)
    {
        $this->databases = $databases;
    }
}
