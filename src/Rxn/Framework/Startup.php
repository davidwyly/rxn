<?php declare(strict_types=1);

namespace Rxn\Framework;

use Dotenv\Dotenv;

class Startup
{
    /**
     * @var Dotenv
     */
    private $env;

    /**
     * @var Autoloader
     */
    private $autoloader;

    private $time_elapsed;

    public function __construct()
    {
        $this->defineConstants();
        $this->setAutoloader();
        $this->setEnv();
        $this->verifyMultibyte();
        $this->setDefaultTimezone();
        $this->setDatabases();
        $this->finalize();
    }

    private function defineConstants()
    {
        define(__NAMESPACE__ . '\\START', microtime(true));
        define(__NAMESPACE__ . '\\ROOT', __DIR__ . '/../src');
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
        $this->env->required(constant('Rxn\Framework\REQUIRED_ENV_KEYS'));
        foreach (constant('Rxn\Framework\BOOLEAN_ENV_KEYS') as $boolean_env_key) {
            $this->env->required($boolean_env_key)->isBoolean();
        }
        foreach (constant('Rxn\Framework\INTEGER_ENV_KEYS') as $integer_env_key) {
            $this->env->required($integer_env_key)->isInteger();
        }
        foreach (constant('Rxn\Framework\ALLOWED_VALUES_ENV_KEYS') as $env_key => $allowed_values) {
            $this->env->required($env_key)->allowedValues($allowed_values);
        }
    }

    private function verifyMultibyte()
    {
        $multibyte_enabled = getenv('APP_MULTIBYTE_ENABLED');
        if ($multibyte_enabled && !function_exists('mb_strlen')) {
            throw new \Exception("Multibyte functions are not available");
        }
    }

    private function setDefaultTimezone()
    {
        date_default_timezone_set(getenv('APP_TIMEZONE'));
    }

    private function setDatabases()
    {
        $env_defined_databases = $this->getEnvDefinedDatabases();
        foreach ($env_defined_databases as $env_defined_database) {
            $this->databases[$env_defined_database] = new Data\Database($env_defined_database);
        }
    }

    private function getEnvDefinedDatabases()
    {
        return explode(',',getenv('APP_ENABLE_DATABASES'));
    }

    private function finalize()
    {
        $this->time_elapsed = round((microtime(true) - constant(__NAMESPACE__ . '\\START')) * 1000, 4);
    }
}
