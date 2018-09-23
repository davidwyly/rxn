<?php declare(strict_types=1);

namespace Rxn\Framework\Service;

use \Rxn\Framework\Service as BaseService;

class Stats extends BaseService
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
