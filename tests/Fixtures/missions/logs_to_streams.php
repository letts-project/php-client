<?php
require __DIR__ . '/../../../vendor/autoload.php';
use Letts\Mission;
$m = Mission::start();
fwrite(STDOUT, "stdout-marker\n");
fwrite(STDERR, "stderr-marker\n");
$m->success(['ok' => true]);
