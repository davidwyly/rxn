<?php
/**
 * This file is part of Reaction (RXN).
 *
 * @license MIT License (MIT)
 * @author  David Wyly (davidwyly) <david.wyly@gmail.com>
 */

namespace Rxn\Router;

/**
 * Class Session
 *
 * @package Rxn\Router
 */
class Session
{

    /**
     * Session constructor.
     */
    public function __construct()
    {
        $this->startSession();
    }

    /**
     * @return void
     */
    public function startSession()
    {
        // server should keep session data for AT LEAST 40 minutes
        ini_set('session.gc_maxlifetime', 2400);

        // each client should remember their session id for EXACTLY 40 minutes
        session_set_cookie_params(2400);

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
        } else {
            ob_start(null, 0, false);
        }
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
    static public function getSessionParams()
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