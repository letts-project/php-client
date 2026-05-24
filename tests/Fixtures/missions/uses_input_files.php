<?php
require __DIR__ . '/../../../vendor/autoload.php';
use Letts\Mission;
$m = Mission::start();
$path = $m->file('photo');
$sha = hash_file('sha256', $path);
$m->success(['sha' => $sha, 'size' => filesize($path)]);
