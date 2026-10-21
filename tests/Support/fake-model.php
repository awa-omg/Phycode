<?php

declare(strict_types=1);

$folder = (string) getenv('PHYCODE_FAKE_MODEL');
if (($_SERVER['REQUEST_URI'] ?? '') !== '/v1/chat/completions') {
    http_response_code(404);

    return true;
}

$replies = json_decode((string) file_get_contents($folder . '/replies.json'), true, 512, JSON_THROW_ON_ERROR);
$index = (int) @file_get_contents($folder . '/next');
file_put_contents($folder . '/next', (string) ($index + 1));
if (!isset($replies[$index])) {
    http_response_code(500);
    echo 'no scripted reply is left';

    return true;
}

$reply = $replies[$index];
if (($reply['delay'] ?? 0) > 0) {
    usleep((int) ($reply['delay'] * 1000000));
}
header('Content-Type: text/event-stream');
echo 'data: ' . json_encode(['choices' => [['delta' => ['content' => $reply['content']]]]], JSON_THROW_ON_ERROR) . "\n\n";
echo 'data: ' . json_encode(['choices' => [['delta' => [], 'finish_reason' => $reply['finish'] ?? 'stop']]], JSON_THROW_ON_ERROR) . "\n\n";
echo "data: [DONE]\n\n";

return true;
