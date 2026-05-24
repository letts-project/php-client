<?php
require __DIR__ . '/../../../vendor/autoload.php';
use Letts\Mission;
$m = Mission::start();
usleep(1_000_000); // 1.0s — long enough to expose sequential vs concurrent fan-out
$m->success(['slept' => true]);
