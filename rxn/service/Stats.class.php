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

namespace Rxn\Service;

use \Rxn\Service;

class Stats extends Service
{
    /**
     * @var float
     */
    private $time_stop;

    /**
     * @var
     */
    public $load_ms;

    /**
     * Stats constructor.
     */
    public function __construct()
    {
        // intentionally left blank
    }

    /**
     * @param $time_start
     */
    public function stop($time_start)
    {
        $this->time_stop = microtime(true);
        $this->load_ms   = round(($this->time_stop - $time_start) * 1000, 4);
    }

}