<?php

namespace Vendor\Product\Model;

use \Rxn\Data\Database;
use \Rxn\Utility\Debug;

class Order extends \Rxn\Model\Record
{
    public $table = 'orders';
}