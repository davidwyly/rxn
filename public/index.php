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

namespace Rxn;

require_once('../bootstrap.php');

/**
 * Begin output buffering
 */
ob_start();

/**
 * Validate environment
 */
$config   = new Config();
$autoload = new Autoload($config);
$autoload->validateEnvironment(ROOT, APP_ROOT);

try {
    $app = new App($config, new Datasources(), new Container());
} catch (\Exception $e) {
    App::renderEnvironmentErrors($e);
    die();
}

$app->run();