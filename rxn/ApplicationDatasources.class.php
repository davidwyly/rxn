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

    public $defaultRead;

    public $defaultWrite;

    public $defaultAdmin;

    protected $databases;

    private $requiredFields = [
        'host',
        'name',
        'username',
        'password',
        'charset',
    ];

    public function __construct()
    {
        $this->validateDatabaseArray($this->databases);
        foreach ($this->databases as $databaseName => $connectionSettings) {

        }
    }

    private function validateDatabaseArray(array $databaseArray)
    {
        if (empty($this->databases)) {
            throw new \Exception("'Databases' param cannot be set to empty");
        }
        foreach ($databaseArray as $databaseName => $connectionSettings) {
            if (!is_array($connectionSettings)) {
                throw new \Exception ("'Databases' param is malformed");
            }
            foreach ($this->requiredFields as $requiredField) {
                if (!isset($connectionSettings[$requiredField])
                    || empty($connectionSettings[$requiredField])
                ) {
                    throw new \Exception("Database config with key '$databaseName' is missing required field '$requiredField'");
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