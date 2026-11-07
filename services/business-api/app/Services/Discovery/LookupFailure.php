<?php

namespace App\Services\Discovery;

final class LookupFailure extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $status = 502)
    {
        parent::__construct('Discovery provider lookup could not be completed.');
    }
}
