<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author  David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn\Api;

use \Rxn\Service;
use \Rxn\ApplicationConfig as Config;
use \Rxn\Data\Database;
use \Rxn\Api\Controller\Response;
use \Rxn\Api\Controller\Crud;
use \Rxn\Utility\Debug;

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
    public $recordClass;

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
        if (empty($this->recordClass)) {
            $this->recordClass = $this->guessRecordClass($config);
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
        $keyValues = $request->collectAll();
        $order     = $service->get($this->recordClass);
        $createdId = $order->create($database, $keyValues);
        return ['created_id' => $createdId];
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
        $order = $service->get($this->recordClass);
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
        $id        = $request->collect('id');
        $keyValues = $request->collectAll();
        unset($keyValues['id']);
        $order     = $service->get($this->recordClass);
        $updatedId = $order->update($database, $id, $keyValues);
        return ['updated_id' => $updatedId];
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
        $id        = $request->collect('id');
        $order     = $service->get($this->recordClass);
        $deletedId = $order->delete($database, $id);
        return ['deleted_id' => $deletedId];
    }

    /**
     * @param Config $config
     *
     * @return string
     */
    protected function guessRecordClass(Config $config)
    {
        $calledClass     = get_called_class();
        $reflection      = new \ReflectionClass($calledClass);
        $shortName       = $reflection->getShortName();
        $potentialRecord = "{$config->productNamespace}\\Model\\$shortName";
        return $potentialRecord;
    }

    /**
     * @throws \Exception
     */
    protected function validateRecordClass()
    {
        $calledClass = get_called_class();
        if (!class_exists($this->recordClass, true)) {
            throw new \Exception("CrudController '$calledClass' references nonexistent model '$this->recordClass'",
                500);
        }
    }
}