<?php declare(strict_types=1);

/**
 * This file is part of the Rxn (Reaction) PHP API App
 *
 * @package    Rxn
 * @copyright  2015-2017 David Wyly
 * @author     David Wyly (davidwyly) <david.wyly@gmail.com>
 * @link       Github <https://github.com/davidwyly/rxn>
 * @license    MIT License (MIT) <https://github.com/davidwyly/rxn/blob/master/LICENSE>
 */

namespace Rxn\Framework\Data;

/**
 * Placeholder for the migration system listed in the README feature
 * table. Not yet implemented; instantiating this class will fail
 * loudly so callers do not silently operate on a no-op.
 */
class Migration
{
    public function __construct()
    {
        throw new \LogicException(
            __CLASS__ . ' is not yet implemented. See the project README for the current roadmap.'
        );
    }
}
