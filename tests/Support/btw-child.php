<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$request = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
echo json_encode(\Phycode\Btw\BtwWorker::run($request), JSON_THROW_ON_ERROR), "\n";
