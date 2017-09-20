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
    static public $create_contract = [
        'billing_address' => 'int(11)',
    ];

    /**
     * Example custom action
     *
     * @param Container $container
     *
     * @return array
     * @throws \Exception
     */
    public function test(Container $container)
    {
        $order    = $container->get(OrderRecord::class);
        $response = [
            'order' => $order,
        ];
        return $response;
    }

    /**
     * Example action that utilizes the record's CRUD interface
     *
     * @param Request   $request
     * @param Container $container
     * @param Database  $database
     *
     * @return array
     * @throws \Exception
     */
    public function create(Request $request, Container $container, Database $database)
    {
        $response = $this->create($request, $container, $database);
        $order    = $container->get(OrderRecord::class);
        $id       = $order->create($database, $this->request->post);
        return [
            'created_order_id' => $id,
        ];
    }

    public function read()
    {

    }

    public function update()
    {
    }

    public function delete()
    {

    }
}
