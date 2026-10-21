<?php

declare(strict_types=1);

$request = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$question = (string) ($request['question'] ?? '');

if (str_contains($question, 'crash')) {
    fwrite(STDERR, "boom\n");
    exit(3);
}
if (str_contains($question, 'garbage')) {
    echo "not json\n";
    exit(0);
}
if (str_contains($question, 'refuse')) {
    echo json_encode(['ok' => false, 'error' => 'the model refused'], JSON_THROW_ON_ERROR), "\n";
    exit(0);
}
if (str_contains($question, 'slow')) {
    sleep(2);
}
$messages = is_array($request['messages'] ?? null) ? count($request['messages']) : 0;
echo json_encode(['ok' => true, 'answer' => "answer to: {$question} ({$messages} messages)"], JSON_THROW_ON_ERROR), "\n";
