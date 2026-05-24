<?php
declare(strict_types=1);

namespace Letts\Config;

enum Scope: string
{
    case Dispatch = 'dispatch';
    case Admin    = 'admin';
    case Exec     = 'exec';
}
