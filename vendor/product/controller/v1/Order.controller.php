<?php

namespace Vendor\Product\Controller\v1;

use \Rxn\Service;
use \Rxn\Data\Database;
use \Rxn\Api\Request;
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

    public function create_vx(Request $request, Service $service, Database $database) {
        $keyValues = $request->collectAll();
        $order = $service->get(OrderModel::class);
        $createdId = $order->create($database,$keyValues);
        return ['created_id' => $createdId];
    }
    
    public function read_vx(Request $request, Service $service, Database $database) {
        $id = $request->collect('id');
        $order = $service->get(OrderModel::class);
        return $order->read($database,$id);
    }

    public function update_vx(Request $request, Service $service, Database $database) {
        $id = $request->collect('id');
        $keyValues = $request->collectAll();
        unset($keyValues['id']);
        $order = $service->get(OrderModel::class);
        $updatedId = $order->update($database,$id,$keyValues);
        return ['updated_id' => $updatedId];
    }

    public function delete_vx(Request $request, Service $service, Database $database) {
        $id = $request->collect('id');
        $order = $service->get(OrderModel::class);
        $deletedId = $order->delete($database,$id);
        return ['deleted_id' => $deletedId];
    }
    
    /**
     * Example custom action
     *
     * @param Service $service
     * @return array
     * @throws \Exception
     */
    public function test_v1(Service $service) {
        $order = $service->get(OrderModel::class);
        $response = [
            'order' => $order,
        ];
        return $response;
    }

    /**
     * @param Request $request
     * @param Service $service
     * @param Database $database
     * @return array
     * @throws \Exception
     */
    public function test_v2(Request $request, Service $service, Database $database) {
        $order = $service->get(OrderModel::class);
        $response = [
            'order' => $order,
            'request' => (array)$request,
        ];
        return $response;
    }

    /**
     * Example action that utilizes the record's CRUD interface
     *
     * @param Service $service
     * @param Database $database
     * @return array
     * @throws \Exception
     */
    public function create_v1(Service $service, Database $database) {
        $order = $service->get(OrderModel::class);
        $id = $order->create($database, $this->request->post);
        return [
            'created_order_id' => $id
        ];
    }
}