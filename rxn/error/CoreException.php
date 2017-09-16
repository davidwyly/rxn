<?php

namespace Rxn\Error;

class CoreException extends \Exception
{
    public function __construct(string $message, int $code = 500, \Exception $e = null)
    {
        parent::__construct($message, $code, $e);
    }
}