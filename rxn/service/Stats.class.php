<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author  David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn\Service;

/**
 * Class Stats
 *
 * @package Rxn\Service
 */
class Stats
{
    /**
     * @var float
     */
    private $timeStop;

    /**
     * @var
     */
    public $loadMs;

    /**
     * Stats constructor.
     */
    public function __construct()
    {

    }

    /**
     * @param $timeStart
     */
    public function stop($timeStart)
    {
        $this->timeStop = microtime(true);
        $this->loadMs   = round(($this->timeStop - $timeStart) * 1000, 4);
    }

}