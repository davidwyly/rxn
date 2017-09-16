<?php

namespace Rxn\Error;

class RequestException extends CoreException
{
    public function __construct(string $message, int $code = 400, \Exception $e = null)
    {
        parent::__construct($message, $code, $e);
    }
}