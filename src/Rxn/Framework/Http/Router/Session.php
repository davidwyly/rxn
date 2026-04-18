<?php

namespace Rxn\Framework\Http\Router;

use \Rxn\Framework\Config;
use \Rxn\Framework\Service;

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
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_set_cookie_params([
            'lifetime' => $config->session_lifetime,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

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

        ob_start(null, 0, PHP_OUTPUT_HANDLER_STDFLAGS ^ PHP_OUTPUT_HANDLER_REMOVABLE);
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
     * CSRF synchronizer token. Lazily generated per session; call
     * this from any endpoint that renders a form or returns JSON the
     * frontend needs to echo back on mutating requests.
     */
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    /**
     * Verify a CSRF token from an incoming request against the
     * session. Uses a constant-time compare.
     */
    public static function validateToken(string $submitted): bool
    {
        if (empty($_SESSION['_csrf']) || !is_string($submitted)) {
            return false;
        }
        return hash_equals($_SESSION['_csrf'], $submitted);
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
