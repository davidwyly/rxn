<?php

namespace Vendor\Product\Controller\v1;

use \Rxn\Utility\Debug;
use \Vendor\Product\Model\Order AS OrderModel;

class Order extends \Rxn\Api\Controller
{
    public function test_v1() {
        $response = [
            'test' => 'response'
        ];
        return $response;
    }
}