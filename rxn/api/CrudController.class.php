<?php
/**
 * This file is part of the Rxn (Reaction) PHP API Framework
 *
 * @license MIT License (MIT)
 * @author  David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn\Api;

use \Rxn\Service;
use \Rxn\Config;
use \Rxn\Data\Database;
use \Rxn\Api\Controller\Response;
use \Rxn\Api\Controller\Crud;

/**
 * Class CrudController
 *
 * @package Rxn\Api
 */
class CrudController extends Controller implements Crud
{
    /**
     * @var string
     */
    public $record_class;

    /**
     * CrudController constructor.
     *
     * @param Config   $config
     * @param Request  $request
     * @param Response $response
     * @param Service  $service
     *
     * @throws \Exception
     */
    public function __construct(Config $config, Request $request, Response $response, Service $service)
    {
        parent::__construct($request, $response, $service);

        // if a record class is not explicitly defined, assume the short name of the crud controller
        if (empty($this->record_class)) {
            $this->record_class = $this->guessRecordClass($config);
        }
        $this->validateRecordClass();
    }

    /**
     * @param Request  $request
     * @param Service  $service
     * @param Database $database
     *
     * @return array
     * @throws \Exception
     */
    public function create_vx(Request $request, Service $service, Database $database)
    {
        $key_values = $request->collectAll();
        $order      = $service->get($this->record_class);
        $created_id = $order->create($database, $key_values);
        return ['created_id' => $created_id];
    }

    /**
     * @param Request  $request
     * @param Service  $service
     * @param Database $database
     *
     * @return mixed
     * @throws \Exception
     */
    public function read_vx(Request $request, Service $service, Database $database)
    {
        $id    = $request->collect('id');
        $order = $service->get($this->record_class);
        return $order->read($database, $id);
    }

    /**
     * @param Request  $request
     * @param Service  $service
     * @param Database $database
     *
     * @return array
     * @throws \Exception
     */
    public function update_vx(Request $request, Service $service, Database $database)
    {
        $id         = $request->collect('id');
        $key_values = $request->collectAll();
        unset($key_values['id']);
        $order      = $service->get($this->record_class);
        $updated_id = $order->update($database, $id, $key_values);
        return ['updated_id' => $updated_id];
    }

    /**
     * @param Request  $request
     * @param Service  $service
     * @param Database $database
     *
     * @return array
     * @throws \Exception
     */
    public function delete_vx(Request $request, Service $service, Database $database)
    {
        $id         = $request->collect('id');
        $order      = $service->get($this->record_class);
        $deleted_id = $order->delete($database, $id);
        return ['deleted_id' => $deleted_id];
    }

    /**
     * @param Config $config
     *
     * @return string
     */
    protected function guessRecordClass(Config $config)
    {
        $called_class     = get_called_class();
        $reflection       = new \ReflectionClass($called_class);
        $short_name       = $reflection->getShortName();
        $potential_record = "{$config->product_namespace}\\Model\\$short_name";
        return $potential_record;
    }

    /**
     * @throws \Exception
     */
    protected function validateRecordClass()
    {
        $called_class = get_called_class();
        if (!class_exists($this->record_class, true)) {
            throw new \Exception("CrudController '$called_class' references nonexistent model '$this->record_class'",
                500);
        }
    }
}