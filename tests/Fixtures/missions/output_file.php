<?php
require __DIR__ . '/../../../vendor/autoload.php';
use Letts\Mission;
$m = Mission::start();
file_put_contents($m->outputPath('result'), 'hello-from-mission');
$m->outputFile('result');
$m->success(['written' => true]);
