<?php

namespace Rxn;

use \Rxn\Data\Database;
use \Rxn\Utility\Debug;

class Application
{
    static public $timeStart;
    static public $config;
    public $api;
    public $auth;
    public $data;
    public $model;
    public $registry;
    public $router;
    public $stats;
    public $utility;

    public function __construct(Config $config)
    {
        self::$timeStart = microtime(true);
        self::$config = $config;
        $database = new Database;
        $registry = new Service\Registry;
        $this->initialize($database, $registry);
        $this->api = new Service\Api();
        $this->auth = new Service\Auth();
        $this->data = new Service\Data($database);
        $this->model = new Service\Model();
        $this->router = new Service\Router();
        $this->stats = new Service\Stats();
        $this->utility = new Service\Utility();
        $this->finalize($this->registry);
    }

    private function initialize(\Rxn\Data\Database $database, Service\Registry $registry)
    {
        // read from config
        date_default_timezone_set(Config::$timezone);

        // registry class registers itself
        $registryClass = get_class($registry);
        $this->registerClass($registry, $registryClass);
        $this->registry = $registry;

        // trigger the autoloading of unknown classes
        $this->registerAutoload();

        // initialize registry
        $this->registry->initialize(self::$config);
    }

    private function registerAutoload()
    {
        spl_autoload_register(array($this, 'load'));
    }

    private function load($classReference, $extension = '.class.php')
    {
        // determine the file path of the class reference
        $classPath = $this->getClassPathByClassReference($classReference, $extension);

        // load the class
        include($classPath);

        // register the class
        $this->registerClass($this->registry, $classReference);
    }

    private function registerClass(Service\Registry $registry, $classReference)
    {
        return $registry->registerClass($classReference);
    }

    private function getClassPathByClassReference($classReference, $extension)
    {
        // break the class namespace into an array
        $pathArray = explode("\\",$classReference);

        // remove the root namespace from the array
        $root = mb_strtolower(array_shift($pathArray));

        if ($root != Config::$appFolder) {
            if ($root != Config::$vendorFolder) {
                Debug::dump(debug_backtrace());
                throw new \Exception("Root path '$root' in reference '$classReference' not defined in config");
            }
        }

        // determine the name of the class without the namespace
        $classShortName = array_pop($pathArray);

        // convert the namespaces into lowercase
        foreach($pathArray as $key=>$value) {
            $pathArray[$key] = mb_strtolower($value);
        }

        // tack the short name of the class back onto the end
        array_push($pathArray,$classShortName);

        // convert back into a string for directory reference
        $classPath = implode("/",$pathArray);
        $loadPath = __DIR__ . "/../" . $root . "/" . $classPath . $extension;
        if (!file_exists($loadPath)) {
            Debug::dump(debug_backtrace());
            throw new \Exception("Load path '$loadPath' does not exist");
        }
        $loadPath = realpath($loadPath);

        // validate the path
        if (!file_exists($loadPath)) {
            throw new \Exception("Cannot autoload path '$loadPath'");
        }
        return $loadPath;
    }

    private function finalize(Service\Registry $registry)
    {
        $registry->sortClasses();
        $this->stats->stop();
    }
}