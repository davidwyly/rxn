<?php declare(strict_types=1);

class Autoloader
{
    const RELATIVE_APP_PATH = '/../../../../';

    /**
     * @var string
     */
    private $app_prefix;

    public function __construct(string $app_namespace)
    {
        $this->app_prefix = $app_namespace;
        $this->PSR4Autoloader();
    }

    private function PSR4Autoloader()
    {
        spl_autoload_register(function ($class) {
            $base_dir = realpath(__DIR__ . self::RELATIVE_APP_PATH) . 'app';
            $length   = strlen($this->app_prefix);

            if (strncmp($this->app_prefix, $class, $length) !== 0) {
                return;
            }

            $relative_class = substr($class, $length);
            $file           = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
            if (file_exists($file)) {
                require $file;
            }
        });
    }
}
