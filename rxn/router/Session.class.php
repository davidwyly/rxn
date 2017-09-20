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

namespace Rxn\Router;

use \Rxn\Config;
use \Rxn\Service;

class Session extends Service
{

    /**
     * Session constructor.
     * @param Config $config
     */
    public function __construct(Config $config)
    {
        $this->startSession($config);
    }

    /**
     * @param Config $config
     * @return void
     */
    public function startSession(Config $config)
    {
        // server should keep session data for AT LEAST this long
        ini_set('session.gc_maxlifetime', $config->session_lifetime);

        // each client should remember their session id for EXACTLY this long
        session_set_cookie_params($config->session_lifetime);

        // start session
        session_start();

        // determine start time
        $now = new \DateTime('now');

        // add support for angularJS posting of JSON
        $this->decodeAngular();

        if (!isset($_SESSION['_start'])
            || !($_SESSION['_start'] instanceof \DateTime)
        ) {
            $this->createNewSession($now);
        }

        if (isset($_SESSION['_expires'])
            && $_SESSION['_expires'] instanceof \DateTime
            && ($now > $_SESSION['_expires'])
        ) {
            $this->createNewSession($now);
        }

        $interval            = $now->diff($_SESSION['_start']);
        $_SESSION['_active'] = $interval->format('%H:%I:%S');

        $newExpires           = new \DateTime('now +40 minutes');
        $_SESSION['_expires'] = $newExpires;

        if (version_compare(PHP_VERSION, '5.4.0', '>=')) {
            ob_start(null, 0, PHP_OUTPUT_HANDLER_STDFLAGS ^ PHP_OUTPUT_HANDLER_REMOVABLE);
            return;
        }

        ob_start(null, 0, false);
        return;
    }

    /**
     * @param \DateTime $start
     */
    private function createNewSession(\DateTime $start)
    {
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['_start'] = $start;
    }

    /**
     * @return void
     */
    private function decodeAngular()
    {
        if ($_SERVER["REQUEST_METHOD"] == "POST" && empty($_POST)) {
            $decoded = json_decode(file_get_contents('php://input'), true);
            if ($decoded != null) {
                $_POST = $decoded;
            }
        }
    }

    /**
     * @return null
     */
    public static function getSessionParams()
    {
        if (!isset($_SESSION) || empty($_SESSION)) {
            return null;
        }
        foreach ($_SESSION as $key => $value) {
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if ($decoded) {
                    $_SESSION[$key] = $decoded;
                }
            }
        }
        return $_SESSION;
    }
}
