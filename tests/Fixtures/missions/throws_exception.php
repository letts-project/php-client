<?php
require __DIR__ . '/../../../vendor/autoload.php';
use Letts\Mission;
$m = Mission::start();
throw new \RuntimeException('kaboom');
