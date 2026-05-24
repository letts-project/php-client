<?php
require __DIR__ . '/../../../vendor/autoload.php';
use Letts\Mission;
$m = Mission::start();
// A business failure that mistakenly passes exit code 0. The library must
// still make the process exit non-zero so the daemon records a clean
// explicit failure, not the contradictory "failed but exited 0" reason.
$m->fail('deliberate failure', exitCode: 0);
