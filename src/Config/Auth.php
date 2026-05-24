<?php
declare(strict_types=1);

namespace Letts\Config;

final readonly class Auth
{
    public function __construct(
        public string $token = '',
        public string $adminToken = '',
        public string $execToken = '',
    ) {}
}
