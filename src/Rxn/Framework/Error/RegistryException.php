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

namespace Rxn\Framework\Error;

class RegistryException extends CoreException
{
    public function __construct(string $message, int $code = 500, \Exception $exception = null)
    {
        parent::__construct($message, $code, $exception);
    }
}
