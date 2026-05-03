<?php declare(strict_types=1);

namespace Rxn\Framework\Error;

use Psr\Container\ContainerExceptionInterface;

class ContainerException extends CoreException implements ContainerExceptionInterface {}
