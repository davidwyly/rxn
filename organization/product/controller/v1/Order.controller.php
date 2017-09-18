<?php

namespace Organization\Product\Controller\v1;

use \Rxn\Container;
use \Rxn\Data\Database;
use \Rxn\Api\Request;
use \Rxn\Api\CrudController;
use \Rxn\Utility\Debug;
use \Organization\Product\Model\Order as OrderRecord;

/**
 * Example Order controller
 *
 * @package Organization\Product\Controller\v1
 */
class Order extends CrudController
{
    static public $create_v1_contract = [
        'billing_address' => 'int(11)',
    ];
    
    /**
     * Example custom action
     *
     * @param Container $container
     * @return array
     * @throws \Exception
     */
    public function test_v1(Container $container) {
        $order = $container->get(OrderRecord::class);
        $response = [
            'order' => $order,
        ];
        return $response;
    }

    /**
     * @param Request $request
     * @param Container $container
     * @param Database $database
     * @return array
     * @throws \Exception
     */
    public function test_v2(Request $request, Container $container, Database $database) {
        $order = $container->get(OrderRecord::class);
        $response = [
            'order' => $order,
            'request' => (array)$request,
        ];
        return $response;
    }

    /**
     * Example action that utilizes the record's CRUD interface
     *
     * @param Request  $request
     * @param Container  $container
     * @param Database $database
     *
     * @return array
     * @throws \Exception
     */
    public function create_v1(Request $request, Container $container, Database $database) {
        $response = $this->create_vx($request,$container,$database);
        $order = $container->get(OrderRecord::class);
        $id = $order->create($database, $this->request->post);
        return [
            'created_order_id' => $id
        ];
    }
}