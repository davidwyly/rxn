<?php
/**
 * This file is part of the Rxn (Reaction) PHP API App
 *
 * @package    Rxn
 * @copyright  2015-2017 David Wyly
 * @author     David Wyly (davidwyly) <david.wyly@gmail.com>
 * @link       Github <https://github.com/davidwyly/rxn>
 * @license    MIT License (MIT) <https://github.com/davidwyly/rxn/blob/master/LICENSE>
 */

namespace Rxn\Api;

use \Rxn\Container;
use \Rxn\Config;
use \Rxn\Data\Database;
use \Rxn\Api\Controller\Response;
use \Rxn\Api\Controller\Crud;

class CrudController extends Controller implements Crud
{
    /**
     * @var string
     */
    public $record_class;

    /**
     * CrudController constructor.
     *
     * @param Config    $config
     * @param Request   $request
     * @param Database  $database
     * @param Response  $response
     * @param Container $container
     *
     * @throws \Exception
     */
    public function __construct(
        Config $config,
        Request $request,
        Database $database,
        Response $response,
        Container $container
    ) {
        parent::__construct($config, $request, $database, $response, $container);

        // if a record class is not explicitly defined, assume the short name of the crud controller
        if (empty($this->record_class)) {
            $this->record_class = $this->guessRecordClass($config);
        }
        $this->validateRecordClass();
    }

    /**
     * @return array
     * @throws \Rxn\Error\ContainerException
     */
    public function create()
    {
        $key_values = $this->request->collectAll();
        $order      = $this->container->get($this->record_class);
        $created_id = $order->create($key_values);
        return ['created_id' => $created_id];
    }

    /**
     * @return mixed
     * @throws \Rxn\Error\ContainerException
     * @throws \Rxn\Error\RequestException
     */
    public function read()
    {
        $record_id    = $this->request->collect('id');
        $order = $this->container->get($this->record_class);
        return $order->read($record_id);
    }

    public function update()
    {
        $record_id         = $this->request->collect('id');
        $key_values = $this->request->collectAll();
        unset($key_values['id']);
        $order      = $this->container->get($this->record_class);
        $updated_id = $order->update($record_id, $key_values);
        return ['updated_id' => $updated_id];
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function delete()
    {
        $record_id         = $this->request->collect('id');
        $order      = $this->container->get($this->record_class);
        $deleted_id = $order->delete($record_id);
        return ['deleted_id' => $deleted_id];
    }

    /**
     * @param Config $config
     *
     * @return string
     */
    private function guessRecordClass(Config $config)
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
    private function validateRecordClass()
    {
        $called_class = get_called_class();
        if (!class_exists($this->record_class, true)) {
            throw new \Exception("CrudController '$called_class' references nonexistent model '$this->record_class'",
                500);
        }
    }
}
