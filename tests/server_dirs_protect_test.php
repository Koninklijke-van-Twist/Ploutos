<?php
/** Run: php tests/server_dirs_protect_test.php */
require __DIR__ . '/../web/lib_server_dirs.php';

$fail = 0;
$root = sys_get_temp_dir() . '/ploutos-protect-' . getmypid();
@mkdir($root . '/feestdagen', 0777, true);
file_put_contents($root . '/feestdagen/.htaccess', "Options -Indexes\n");

ploutos_protect_server_dirs($root);
ploutos_protect_server_dirs($root); // tweede keer: niets dubbel
foreach (PLOUTOS_PROTECTED_DIRS as $name) {
    $c = (string) @file_get_contents("$root/$name/.htaccess");
    if (substr_count($c, 'Require all denied') !== 1) {
        $fail++;
        echo "FAIL {$name}: " . json_encode($c) . "\n";
    }
}
if (strpos((string) file_get_contents("$root/feestdagen/.htaccess"), "Options -Indexes\n\n#") !== 0) {
    $fail++;
    echo "FAIL bestaande inhoud niet behouden\n";
}
array_map('unlink', glob("$root/*/.htaccess"));
array_map('rmdir', glob("$root/*"));
rmdir($root);
echo $fail ? "{$fail} fout(en)\n" : "OK\n";
exit($fail ? 1 : 0);
