<?php
require __DIR__ . '/../../../vendor/autoload.php';
use Letts\Mission;
$m = Mission::start();
for ($i = 1; $i <= 5; $i++) {
    $m->progress($i / 5, "step $i");
    usleep(20_000);
}
$m->success(['steps' => 5]);
