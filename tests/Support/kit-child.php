<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Phycode\Tests\Support\Kit;

$folder = Kit::folder('kit-child');
mkdir($folder . '/deep/er', 0700, true);
file_put_contents($folder . '/deep/er/file.txt', "x\n");
symlink($argv[1], $folder . '/outside');
fwrite(STDOUT, $folder . "\n");

if (($argv[2] ?? null) === 'wait') {
    sleep(60);
}

exit(3);
