<?php
/**
 * This file is part of the Rxn (Reaction) PHP API Framework
 *
 * @package    Rxn
 * @copyright  2015-2017 David Wyly
 * @author     David Wyly (davidwyly) <david.wyly@gmail.com>
 * @link       Github <https://github.com/davidwyly/rxn>
 * @license    MIT License (MIT) <https://github.com/davidwyly/rxn/blob/master/LICENSE>
 */

namespace Rxn;

require_once('../bootstrap.php');

try {
    $app = new Application(new Config(), new Datasources(), new Service(), START);
} catch (\Exception $e) {
    Application::appendEnvironmentError($e);
    Application::renderEnvironmentErrors(new Config());
    die();
}

$app->run();