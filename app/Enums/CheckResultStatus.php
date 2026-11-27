<?php

namespace App\Enums;

/**
 * Outcome status recorded on each historical monitor probe attempt.
 */
enum CheckResultStatus: string
{
    case SUCCESS = 'success';
    case FAILURE = 'failure';

    public function label(): string
    {
        return match ($this) {
            self::SUCCESS => 'Success',
            self::FAILURE => 'Failure',
        };
    }
}
