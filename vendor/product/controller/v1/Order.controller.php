<?php

namespace Vendor\Product\Controller\v1;

use \Rxn\Service;
use \Rxn\Data\Database;
use \Rxn\Api\Request;
use \Rxn\Api\CrudController;
use \Rxn\Utility\Debug;
use \Vendor\Product\Model\Order AS OrderRecord;

/**
 * Example Order controller
 *
 * @package Vendor\Product\Controller\v1
 */
class Order extends CrudController
{
    static public $create_v1_contract = [
        'billing_address' => 'int(11)',
    ];
    
    /**
     * Example custom action
     *
     * @param Service $service
     * @return array
     * @throws \Exception
     */
    public function test_v1(Service $service) {
        $order = $service->get(OrderRecord::class);
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
        $order = $service->get(OrderRecord::class);
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
        $order = $service->get(OrderRecord::class);
        $id = $order->create($database, $this->request->post);
        return [
            'created_order_id' => $id
        ];
    }
}