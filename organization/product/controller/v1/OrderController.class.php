<?php

namespace Organization\Product\Controller\v1;

use \Rxn\Container;
use \Rxn\Api\CrudController;
use \Organization\Product\Model\Order;

/**
 * Example Order controller
 *
 * @package Organization\Product\Controller\v1
 */
class OrderController extends CrudController
{

    public function init() {

    }

    /**
     * Example custom action
     *
     * @return array
     * @throws \Exception
     */
    public function test()
    {
        $order    = $this->container->get(Order::class);
        $response = [
            'order' => $order,
        ];
        return $response;
    }

    /**
     * Example action that utilizes the record's CRUD interface
     *
     * @return array
     * @throws \Rxn\Error\ContainerException
     *
     */
    public function create()
    {
        $order    = $this->container->get(Order::class);
        $order_id = $order->create();
        return [
            'created_order_id' => $order_id,
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
