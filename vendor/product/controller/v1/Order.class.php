<?php

namespace Vendor\Product\Controller\v1;

use \Rxn\Utility\Debug;
use \Vendor\Product\Model\Order AS OrderModel;

class Order extends \Rxn\Api\Controller
{
    static public $create_v1_contract = [
        'billing_address' => 'int(11)',
    ];

    public function test_v1() {
        $order = $this->service->get(OrderModel::class);
        $response = [
            'order' => $order,
        ];
        return $response;
    }

    public function create_v1() {
        $order = $this->service->get(OrderModel::class);
        $id = $order->create($this->request->get);
        return [
            'created_order_id' => $id
        ];
    }
}