<?php
/**
 *
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 *
 * For full copyright and license information, please see the docs/CREDITS.txt file.
 *
 * @author David Wyly (davidwyly) <david.wyly@gmail.com>
 *
 */

namespace Rxn\Service;

use \Rxn\Application;

class Stats
{
    private $start;
    private $stop;
    public $loadSeconds;

    public function __construct() {
        $this->start = Application::$timeStart;
    }

    public function stop() {
        $this->stop = microtime(true);
        $this->loadSeconds = round($this->stop - $this->start,4);
    }

}