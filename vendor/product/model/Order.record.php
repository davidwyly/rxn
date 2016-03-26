<?php

namespace Vendor\Product\Model;

use \Rxn\Data\Database;
use \Rxn\Utility\Debug;

/**
 * Example Order model
 *
 * @package Vendor\Product\Model
 */
class Order extends \Rxn\Model\Record
{
    /**
     * Tie the record to a particular database table
     *
     * @var string
     */
    public $table = 'orders';
}