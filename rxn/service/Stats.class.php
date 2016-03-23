<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn\Service;

use \Rxn\Application;

/**
 * Class Stats
 *
 * @package Rxn\Service
 */
class Stats
{
    /**
     * @var
     */
    private $start;

    /**
     * @var
     */
    private $stop;

    /**
     * @var
     */
    public $loadSeconds;

    /**
     * Stats constructor.
     */
    public function __construct() {
        $this->start = Application::$timeStart;
    }

    /**
     *
     */
    public function stop() {
        $this->stop = microtime(true);
        $this->loadSeconds = round($this->stop - $this->start,4);
    }

}