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

use \Rxn\Error\OrmException;
use \Rxn\Data\Database;

class Orm
{
    /**
     * @var Database[]
     */
    private $databases;

    public function __construct()
    {
        //
    }

    public function registerDatabase(Database $database)
    {
        $this->databases[] = $database;
    }
}
