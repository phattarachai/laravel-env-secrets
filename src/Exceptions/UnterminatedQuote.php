<?php

namespace Phattarachai\EnvSecrets\Exceptions;

use RuntimeException;

/**
 * An env file whose double quote is never closed. phpdotenv silently drops everything from that
 * point on, so the file is already broken — but for a MERGE it is worse than broken: every
 * assignment after the open quote is swallowed into one entry, which hides those keys from the
 * protected-key check and would append them verbatim as a single dead block. Refuse instead.
 */
class UnterminatedQuote extends RuntimeException
{
    public function __construct(public readonly string $name)
    {
        parent::__construct("unterminated quote — the value of {$name} is never closed");
    }
}
