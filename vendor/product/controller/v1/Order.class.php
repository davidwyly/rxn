<?php

namespace Vendor\Product\Controller\v1;

use \Rxn\Utility\Debug;
use \Vendor\Product\Model\Order AS OrderModel;

class Order extends \Rxn\Api\Controller
{
    public function test_v1() {
        $order = new OrderModel();
        $response = [
            'order' => $order,
        ];
        return $response;
    }

    public function create_v1() {
        $order = new OrderModel();
        $test = [
            'billing_address' => '1',
        ];
        $id = $order->create($test);
        return [
            'created_order_id' => $id
        ];
    }
}