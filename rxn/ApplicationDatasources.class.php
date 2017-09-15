<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author  David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn;

abstract class ApplicationDatasources
{

    public $default_read;

    public $default_write;

    public $default_admin;

    protected $databases;

    private $required_fields = [
        'host',
        'name',
        'username',
        'password',
        'charset',
    ];

    public function __construct()
    {
        $this->validateDatabases($this->databases);
        foreach ($this->databases as $database_name => $connection_settings) {

        }
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
                    throw new \Exception("Database config with key '$database_name' is missing required field '$required_field'");
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