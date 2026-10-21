<?php

declare(strict_types=1);

if (!is_file('/run/.containerenv') && !is_file('/.dockerenv')) {
    fwrite(STDERR, "this test deletes real folders if the hard line fails, so it runs only inside a throwaway container: tests/E2e/containers.sh\n");
    exit(2);
}
if (posix_geteuid() !== 0) {
    fwrite(STDERR, "run this test as root, so a command that slips through the hard line could really delete the folders\n");
    exit(2);
}

$repo = dirname(__DIR__, 2);
$home = (string) getenv('HOME');
$project = $home . '/e2e-project';
$state = sys_get_temp_dir() . '/phycode-e2e-' . getmypid();
$mount = '/srv/disk/vol';

$guarded = [
    'a neighbour home that is in the passwd file' => ['bash', ['command' => 'rm -rf /home/neighbour'], '/home/neighbour'],
    'a home under the home root that is in no file' => ['bash', ['command' => 'rm -rf /home/ghost'], '/home/ghost'],
    'a neighbour home by tilde' => ['bash', ['command' => 'rm -rf ~neighbour/'], '/home/neighbour'],
    'every home by glob' => ['bash', ['command' => 'rm -rf /home/*'], '/home/ghost'],
    'the /mnt mount-point root' => ['bash', ['command' => 'rm -rf /mnt'], '/mnt/data'],
    'the /media mount-point root' => ['bash', ['command' => 'sudo rm -rf /media/'], '/media/usb'],
    'a mounted filesystem' => ['bash', ['command' => "rm -rf {$mount}"], $mount],
    'the folder that holds a mount' => ['bash', ['command' => 'find /srv/disk -delete'], $mount],
    'the contents of the home folder reached from the project' => ['bash', ['command' => 'rm -rf ../*'], $home . '/keep'],
    'a folder on the search path' => ['bash', ['command' => 'rm -rf /usr/bin'], '/usr/bin/env'],
    'a system folder next to one on the search path' => ['bash', ['command' => 'rm -rf /usr/lib'], '/usr/lib'],
    'a custom tool whose command deletes a home' => ['custom__wipe', [], '/home/neighbour'],
    'a deploy step that deletes a mount-point root' => ['deploy', ['target' => 'wipe', 'action' => 'apply'], '/media/usb'],
];
$controls = [
    'bash deletes a project folder' => ['bash', ['command' => 'rm -rf build'], $project . '/build'],
    'a custom tool deletes a project folder' => ['custom__tidy', [], $project . '/scratch'],
    'a deploy step deletes a project folder' => ['deploy', ['target' => 'tidy', 'action' => 'apply'], $project . '/dist'],
];

function plant(string $folder): void
{
    if (!is_dir($folder) && !mkdir($folder, 0755, true)) {
        throw new RuntimeException("cannot make {$folder}");
    }
    file_put_contents($folder . '/keep.txt', "keep\n");
}

function mounted(string $path): bool
{
    foreach (file('/proc/self/mounts', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if ((explode(' ', $line)[1] ?? '') === $path) {
            return true;
        }
    }

    return false;
}

if (!mounted($mount)) {
    fwrite(STDERR, "{$mount} is not a mount point; start the container with --mount type=tmpfs,destination={$mount}\n");
    exit(2);
}
if (posix_getpwnam('neighbour') === false) {
    fwrite(STDERR, "the user neighbour does not exist; create it before the test\n");
    exit(2);
}
foreach (['/home/neighbour', '/home/ghost', '/mnt/data', '/media/usb', $mount, $home . '/keep', $project . '/build', $project . '/scratch', $project . '/dist'] as $folder) {
    plant($folder);
}
mkdir($state . '/config/phycode', 0700, true);

$probe = stream_socket_server('tcp://127.0.0.1:0');
$address = (string) stream_socket_get_name($probe, false);
fclose($probe);
$port = (int) substr($address, (int) strrpos($address, ':') + 1);
$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", $repo . '/tests/Support/fake-model.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $state . '/server.log', 'w'], 2 => ['file', $state . '/server.log', 'a']],
    $pipes,
    $state,
    ['PATH' => (string) getenv('PATH'), 'PHYCODE_FAKE_MODEL' => $state],
);
for ($deadline = microtime(true) + 10; @fsockopen('127.0.0.1', $port) === false; usleep(50000)) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, "the fake model did not start\n");
        exit(1);
    }
}

$config = [
    'default_profile' => 'fake',
    'profiles' => ['fake' => ['base_url' => "http://127.0.0.1:{$port}", 'model' => 'scripted', 'tool_mode' => 'text']],
    'permissions' => ['mode' => 'yolo'],
    'custom_tools' => [
        ['name' => 'wipe', 'description' => 'Deletes the neighbour home.', 'command' => ['rm', '-rf', '/home/neighbour'], 'stdin' => false],
        ['name' => 'tidy', 'description' => 'Deletes the scratch folder.', 'command' => ['rm', '-rf', 'scratch'], 'stdin' => false],
    ],
    'deploy' => ['targets' => [
        'wipe' => ['kind' => 'custom', 'environment' => 'staging', 'confirm' => 'rules', 'commands' => ['apply' => [['rm', '-rf', '/media']]]],
        'tidy' => ['kind' => 'custom', 'environment' => 'staging', 'confirm' => 'rules', 'commands' => ['apply' => [['rm', '-rf', 'dist']]]],
    ]],
];
file_put_contents($state . '/config/phycode/config.json', json_encode($config, JSON_THROW_ON_ERROR));

$call = static function (string $tool, array $arguments) use ($repo, $state, $project, $home): array {
    $reply = "<tool_call>\n" . json_encode(['name' => $tool, 'arguments' => (object) $arguments], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n</tool_call>";
    file_put_contents($state . '/replies.json', json_encode([['content' => $reply], ['content' => 'done']], JSON_THROW_ON_ERROR));
    file_put_contents($state . '/next', '0');
    $run = proc_open(
        [PHP_BINARY, $repo . '/bin/phycode', 'run', '--yolo', '--output-format', 'stream-json', '--cwd', $project, 'clean up'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $project,
        ['PATH' => (string) getenv('PATH'), 'HOME' => $home, 'XDG_CONFIG_HOME' => $state . '/config', 'XDG_CACHE_HOME' => $state . '/cache'],
    );
    if (!is_resource($run)) {
        return [-1, null, 'phycode could not start'];
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    $status = proc_close($run);
    foreach (explode("\n", $out) as $line) {
        $event = json_decode($line, true);
        if (is_array($event) && ($event['type'] ?? null) === 'tool_end') {
            return [$status, $event['ok'], (string) $event['summary']];
        }
    }

    return [$status, null, 'no tool result: ' . trim($err)];
};

$failures = 0;
$report = static function (bool $passed, string $label, string $detail) use (&$failures): void {
    echo ($passed ? 'PASS' : 'FAIL') . "  {$label}" . ($passed ? '' : "  ({$detail})") . "\n";
    $failures += $passed ? 0 : 1;
};

echo 'phycode e2e of the hard line on ' . (is_file('/etc/os-release') && preg_match('/^PRETTY_NAME="?([^"\n]+)/m', (string) file_get_contents('/etc/os-release'), $name) === 1 ? $name[1] : PHP_OS) . ', PHP ' . PHP_VERSION . "\n";
foreach ($guarded as $label => [$tool, $arguments, $witness]) {
    [$status, $ok, $summary] = $call($tool, $arguments);
    $report($status === 0 && $ok === false && str_contains($summary, 'is never run') && file_exists($witness), "refused: {$label}", "status {$status}: {$summary}");
}
foreach ($controls as $label => [$tool, $arguments, $target]) {
    [$status, $ok, $summary] = $call($tool, $arguments);
    $report($status === 0 && $ok === true && !file_exists($target), "runs: {$label}", "status {$status}: {$summary}");
}
foreach (['/home/neighbour', '/home/ghost', '/mnt/data', '/media/usb', $mount, $home . '/keep'] as $folder) {
    $report(is_file($folder . '/keep.txt'), "still there: {$folder}", 'deleted');
}
$report(is_file('/usr/bin/env'), 'still there: /usr/bin/env', 'deleted');

proc_terminate($server);
proc_close($server);

echo $failures === 0 ? "all passed\n" : "{$failures} failed\n";
exit($failures === 0 ? 0 : 1);
