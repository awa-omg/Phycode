<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Phycode\Browser\BrowserPolicy;
use Phycode\Browser\BrowserSession;
use Phycode\Browser\BrowserSettings;
use Phycode\Exec\ProcessRunner;

$settings = new BrowserSettings(true, $argv[1], ['127.0.0.1'], 3, ($argv[3] ?? '') === 'no-sandbox');
$session = new BrowserSession($settings, new BrowserPolicy($settings->allowHosts), new ProcessRunner(), 5.0);
$session->open($argv[4] ?? 'http://127.0.0.1:3000/');
fwrite(STDOUT, "ready\n");
if (($argv[2] ?? '') === 'finish') {
    exit(0);
}
sleep(120);
