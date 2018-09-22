<?php declare(strict_types=1);

namespace Rxn\Framework;

use Dotenv\Dotenv;

class Startup
{

    /**
     * @var BaseConfig
     */
    public $config;

    /**
     * @var BaseDatasource
     */
    public $datasource;

    /**
     * @var Dotenv
     */
    private $env;

    /**
     * @var Autoloader
     */
    private $autoloader;

    public function __construct()
    {
        $this->initialize();
        $this->setAutoloader();
        $this->setEnv();
        $this->setDefaultTimezone();
        $this->setDatabases();
    }

    private function initialize()
    {
        define(__NAMESPACE__ . '\\START', microtime(true));
        define(__NAMESPACE__ . '\\ROOT', realpath(__DIR__ . '/../..') . '/');
        define(__NAMESPACE__ . '\\APP_ROOT', constant(__NAMESPACE__ . '\\ROOT') . 'app/');
        define(__NAMESPACE__ . '\\CONFIG_ROOT', constant(__NAMESPACE__ . '\\ROOT') . 'app/Config/');
    }

    private function setAutoloader()
    {
        $this->autoloader = new Autoloader();
    }

    private function setEnv()
    {
        $this->env = new Dotenv(constant(__NAMESPACE__ . '\\CONFIG_ROOT'));
        $this->env->load();
        $this->validateRequiredEnvKeys();
    }

    private function validateRequiredEnvKeys()
    {
        $missing_keys      = [];
        $required_env_keys = constant('Rxn\Framework\REQUIRED_ENV_KEYS');
        foreach ($required_env_keys as $required_env_key) {
            if (!array_key_exists($required_env_key, $_ENV)) {
                $missing_keys[] = $required_env_key;
            }
        }
        if (!empty($missing_keys)) {
            throw new \Exception('Missing the required .env keys: ' . implode(', ', $missing_keys));
        }
    }

    private function setDefaultTimezone()
    {
        date_default_timezone_set(getenv('APP_TIMEZONE'));
    }

    private function setDatabases() {
        $database = new Data\Database('DATABASE_READ');
    }
}
