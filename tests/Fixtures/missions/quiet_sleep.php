<?php
require __DIR__ . '/../../../vendor/autoload.php';
use Letts\Mission;
$m = Mission::start();
// Sleeps without emitting a single progress event — the stream stays silent
// between `running` and `done`, which is how long background jobs behave.
$seconds = (float) $m->input('seconds', 3);
usleep((int) ($seconds * 1_000_000));
$m->success(['slept' => $seconds]);
