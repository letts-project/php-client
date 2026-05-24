<?php
require __DIR__ . '/../../../vendor/autoload.php';
ini_set('memory_limit', '16M');
use Letts\Mission;
$m = Mission::start();
$buf = '';
while (true) {
    $buf .= str_repeat('x', 1024 * 1024);
}
