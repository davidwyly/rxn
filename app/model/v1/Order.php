<?php

namespace Organization\Product\Model\v1;

use \Rxn\Framework\Model\Record;

/**
 * Example Order model
 *
 * @package Organization\Product\Model
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
