<?php

namespace App\Exceptions;

use RuntimeException;

class OwnerAlreadyExistsException extends RuntimeException
{
    public function __construct(string $message = 'Cannot create owner: an account already exists.')
    {
        parent::__construct($message);
    }
}
