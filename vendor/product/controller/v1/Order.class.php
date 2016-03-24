<?php

namespace Vendor\Product\Controller\v1;

use \Rxn\Utility\Debug;
use \Vendor\Product\Model\Order AS OrderModel;

/**
 * Example Order controller
 *
 * @package Vendor\Product\Controller\v1
 */
class Order extends \Rxn\Api\Controller
{
    static public $create_v1_contract = [
        'billing_address' => 'int(11)',
    ];

    /**
     * Example custom action
     *
     * @return array
     * @throws \Exception
     */
    public function test_v1() {
        $order = $this->service->get(OrderModel::class);
        $response = [
            'order' => $order,
        ];
        return $response;
    }

    /**
     * Example action that utilizes the record's CRUD interface
     *
     * @return array
     * @throws \Exception
     */
    public function create_v1() {
        $order = $this->service->get(OrderModel::class);
        $id = $order->create($this->request->get);
        return [
            'created_order_id' => $id
        ];
    }
}