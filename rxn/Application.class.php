<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn;

use \Rxn\Data\Database;
use \Rxn\Utility\Debug;

/**
 * Class Application
 *
 * @package Rxn
 */
class Application
{
    /**
     * @var Config
     */
    public $config;

    /**
     * @var Service\Api $api
     */
    public $api;

    /**
     * @var Service\Auth $auth
     */
    public $auth;

    /**
     * @var Service\Data $data
     */
    public $data;

    /**
     * @var Service\Model $model
     */
    public $model;

    /**
     * @var Service\Registry $registry
     */
    public $registry;

    /**
     * @var Service\Router $router
     */
    public $router;

    /**
     * @var Service\Stats $stats
     */
    public $stats;

    /**
     * @var Service\Utility $utility
     */
    public $utility;

    /**
     * @var Service $service Dependency Injection (DI) container
     */
    public $service;


    /**
     * Application constructor.
     *
     * @param Config   $config
     * @param Database $database
     */
    public function __construct(Config $config, Database $database) {
        $timeStart = microtime(true);
        $this->initialize($config, $database, new Service());
        $this->api = $this->service->get(Service\Api::class);
        $this->auth = $this->service->get(Service\Auth::class);
        $this->data = $this->service->get(Service\Data::class);
        $this->model = $this->service->get(Service\Model::class);
        $this->router = $this->service->get(Service\Router::class);
        $this->stats = $this->service->get(Service\Stats::class);
        $this->utility = $this->service->get(Service\Utility::class);
        $this->finalize($this->registry, $timeStart);
    }

    /**
     * @param Config   $config
     *
     * @param Database $database
     * @param Service  $service
     *
     * @throws \Exception
     */
    private function initialize(Config $config, Database $database, Service $service) {
        $this->config = $config;
        $this->service = $service;
        $this->service->addInstance(Database::class,$database);
        $this->service->addInstance(Config::class,$config);
        $this->registry = $this->service->get(Service\Registry::class);
        date_default_timezone_set($config->timezone);
    }

    /**
     * @param Service\Registry $registry
     *
     * @param                  $timeStart
     */
    private function finalize(Service\Registry $registry, $timeStart) {
        $registry->sortClasses();
        $this->stats->stop($timeStart);
    }
}