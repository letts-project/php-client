<?php
require __DIR__ . '/../../../vendor/autoload.php';
use Letts\Mission;
$m = Mission::start();
// Writes a `mib`-sized output file in 1 MiB chunks (the mission itself must
// not blow its own memory limit) and reports the content sha256 so the test
// can verify the downloaded copy byte-for-byte.
$mib = (int) $m->input('mib', 8);
$path = $m->outputPath('blob');
$fh = fopen($path, 'wb');
$hash = hash_init('sha256');
for ($i = 0; $i < $mib; $i++) {
    // Vary the chunk content so accidental truncation/reordering changes the hash.
    $chunk = str_repeat(chr(65 + ($i % 26)), 1024 * 1024);
    fwrite($fh, $chunk);
    hash_update($hash, $chunk);
}
fclose($fh);
$m->outputFile('blob');
$m->success(['mib' => $mib, 'sha256' => hash_final($hash)]);
