<?php

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