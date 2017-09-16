<?php

namespace Rxn\Error;

class RegistryException extends CoreException
{
    public function __construct(string $message, int $code = 500, \Exception $e = null)
    {
        parent::__construct($message, $code, $e);
    }
}