<?php
require __DIR__ . '/../../../vendor/autoload.php';
use Letts\Mission;
$m = Mission::start();
for ($i = 0; $i < 100; $i++) {
    $m->checkSignal();
    usleep(100_000);
}
$m->success();
