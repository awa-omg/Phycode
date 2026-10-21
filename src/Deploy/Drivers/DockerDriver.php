<?php

declare(strict_types=1);

namespace Phycode\Deploy\Drivers;

use Phycode\Deploy\Check;
use Phycode\Deploy\DeployContext;
use Phycode\Deploy\DeployDriver;
use Phycode\Deploy\DeployPlan;
use Phycode\Deploy\DeployStep;
use Phycode\Deploy\DeployTarget;
use Phycode\Deploy\DeployUnsupported;

final class DockerDriver implements DeployDriver
{
    private const COMPOSE_FILES = ['compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml'];

    public function __construct(private readonly string $engine)
    {
    }

    public function keys(): array
    {
        return ['image', 'container', 'ports', 'compose', 'compose_file', ...($this->engine === 'docker' ? ['host'] : [])];
    }

    public function options(array $raw, string $label): array
    {
        $compose = $raw['compose'] ?? null;
        if ($compose !== null) {
            Check::flag($compose, "{$label}.compose");
        }

        return [
            'image' => Check::optionalWord($raw['image'] ?? null, "{$label}.image", '~^[a-z0-9][a-z0-9._/-]{0,99}$~D'),
            'container' => Check::optionalWord($raw['container'] ?? null, "{$label}.container", '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D'),
            'ports' => Check::words($raw['ports'] ?? null, "{$label}.ports", 16, '~^(?:[0-9]{1,3}(?:\.[0-9]{1,3}){3}:)?[0-9]{1,5}:[0-9]{1,5}(?:/(?:tcp|udp))?$~D'),
            'compose' => $compose,
            'compose_file' => isset($raw['compose_file']) ? Check::relativePath($raw['compose_file'], "{$label}.compose_file") : null,
            'host' => Check::optionalWord($raw['host'] ?? null, "{$label}.host", '/^[a-z0-9][a-z0-9_-]{0,31}$/D'),
        ];
    }

    public function plan(DeployTarget $target, string $action, DeployContext $context): DeployPlan
    {
        $options = $target->options;
        $engine = $this->engine;
        $env = [];
        $notes = [Drivers::missing($context->which, [$engine])];
        if ($options['host'] !== null) {
            if ($context->ssh === null || !$context->ssh->has($options['host'])) {
                throw new DeployUnsupported("host '{$options['host']}' is not in the ssh section of the config");
            }
            $host = $context->ssh->host($options['host']);
            $env['DOCKER_HOST'] = 'ssh://' . $host->destination . ($host->port === null ? '' : ':' . $host->port);
            $notes[] = "the daemon is the one of {$options['host']}, reached over ssh with your ssh config";
        }

        $composeFile = $options['compose_file'] ?? $context->firstOf(self::COMPOSE_FILES);
        $compose = $options['compose'] ?? ($composeFile !== null);
        if ($compose) {
            $steps = $this->composeSteps($target, $action, $context, $options['compose_file'] ?? $composeFile, $env);
        } else {
            if (!$context->has('Dockerfile') && $action !== 'status' && $action !== 'logs') {
                throw new DeployUnsupported("the folder of {$target->name} has no Dockerfile and no compose file");
            }
            $steps = $this->plainSteps($target, $action, $context, $env);
        }

        return new DeployPlan($target->name, $action, $steps, Drivers::notes($notes));
    }

    private function plainSteps(DeployTarget $target, string $action, DeployContext $context, array $env): array
    {
        $o = $target->options;
        $engine = $this->engine;
        $image = $o['image'] ?? "phycode-{$target->name}";
        $container = $o['container'] ?? $target->name;
        $run = [$engine, 'run', '-d', '--name', $container, '--restart', 'unless-stopped'];
        foreach ($o['ports'] as $port) {
            array_push($run, '-p', $port);
        }
        $run[] = "{$image}:latest";
        $start = [
            new DeployStep("remove the container {$container}", [$engine, 'rm', '-f', $container], env: $env, optional: true),
            new DeployStep("start the container {$container}", $run, env: $env),
        ];

        return match ($action) {
            'dry_run' => [new DeployStep("build the image {$image}:{$context->tag}", [$engine, 'build', '-t', "{$image}:{$context->tag}", '.'], env: $env)],
            'apply' => [
                new DeployStep("keep {$image}:latest as :previous", [$engine, 'tag', "{$image}:latest", "{$image}:previous"], env: $env, optional: true),
                new DeployStep("build the image {$image}:latest", [$engine, 'build', '-t', "{$image}:latest", '-t', "{$image}:{$context->tag}", '.'], env: $env),
                ...$start,
            ],
            'status' => [new DeployStep('list the container', [$engine, 'ps', '-a', '--filter', "name=^{$container}\$", '--format', 'table {{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}'], env: $env)],
            'logs' => [new DeployStep('read the log of the container', [$engine, 'logs', '--tail', (string) $context->lines, $container], env: $env)],
            'rollback' => [
                new DeployStep("make {$image}:previous the image to run", [$engine, 'tag', "{$image}:previous", "{$image}:latest"], env: $env),
                ...$start,
            ],
            default => throw new DeployUnsupported("a docker target has no action '{$action}'"),
        };
    }

    private function composeSteps(DeployTarget $target, string $action, DeployContext $context, ?string $file, array $env): array
    {
        $compose = [$this->engine, 'compose', ...($file === null ? [] : ['-f', $file]), '-p', $target->name];

        return match ($action) {
            'dry_run' => [new DeployStep('build the services', [...$compose, 'build'], env: $env)],
            'apply' => [new DeployStep('build and start the services', [...$compose, 'up', '-d', '--build', '--wait', '--remove-orphans'], env: $env)],
            'status' => [new DeployStep('list the services', [...$compose, 'ps'], env: $env)],
            'logs' => [new DeployStep('read the logs of the services', [...$compose, 'logs', '--tail', (string) $context->lines, '--no-color'], env: $env)],
            'rollback' => throw new DeployUnsupported('a compose project has no rollback: revert the code and apply again'),
            default => throw new DeployUnsupported("a docker target has no action '{$action}'"),
        };
    }
}
