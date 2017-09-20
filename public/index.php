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

autoload();

try {
    $app = new App(new Config(), new Datasources(), new Container());
} catch (\Exception $e) {
    App::renderEnvironmentErrors($e);
    die();
}

$app->run();