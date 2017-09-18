<?php
/**
 * This file is part of the Rxn (Reaction) PHP API Framework
 *
 * @package    Rxn
 * @copyright  2015-2017 David Wyly
 * @author     David Wyly (davidwyly) <david.wyly@gmail.com>
 * @link       Github <https://github.com/davidwyly/rxn>
 * @license    MIT License (MIT) <https://github.com/davidwyly/rxn/blob/master/LICENSE>
 */

namespace Rxn\Service;

use \Rxn\ApplicationService;

class Stats extends ApplicationService
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