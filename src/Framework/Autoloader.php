<?php declare(strict_types=1);

namespace Rxn\Framework;

class Autoloader
{
    const RELATIVE_APP_PATH = '/../../../../';

    public function __construct()
    {
        $this->PSR4Autoloader();
    }

    private function PSR4Autoloader()
    {
        spl_autoload_register(function ($class) {
            $base_dir = constant(__NAMESPACE__ . '\\APP_ROOT');
            $length   = strlen(APP_NAMESPACE);

            if (strncmp(APP_NAMESPACE, $class, $length) !== 0) {
                return;
            }

            $relative_class = substr($class, $length);
            $file           = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
            if (file_exists($file)) {
                /** @noinspection PhpIncludeInspection */
                require $file;
            }
        });
    }
}
