<?php
declare(strict_types=1);

namespace Letts\Exceptions;

final class MissingEnvException extends ConfigException
{
    public function __construct(public readonly string $name)
    {
        parent::__construct("missing env var: $name");
    }
}
