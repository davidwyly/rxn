<?php

namespace Organization\Product\Model\v1;

use Rxn\Orm\DataModel;

/**
 * Example Order model
 */
class Order extends DataModel
{
    /**
     * Tie the record to a particular database table
     *
     * @var string
     */
    protected $table = 'orders';
}
