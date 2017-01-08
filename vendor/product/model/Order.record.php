<?php

namespace Vendor\Product\Model;

use \Rxn\Data\Database;
use \Rxn\Model\Record;
use \Rxn\Utility\Debug;

/**
 * Example Order model
 *
 * @package Vendor\Product\Model
 */
class Order extends Record
{
    /**
     * Tie the record to a particular database table
     *
     * @var string
     */
    protected $table = 'orders';
}