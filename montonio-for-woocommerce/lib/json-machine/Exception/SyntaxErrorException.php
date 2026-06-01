<?php

declare(strict_types=1);

namespace MontonioJsonMachine\Exception;

class SyntaxErrorException extends MontonioJsonMachineException
{
    public function __construct(string $message, int $position)
    {
        parent::__construct($message." At position $position.");
    }
}
