<?php

declare(strict_types=1);

namespace Phycode\Session;

use Phycode\Config\Profile;
use Phycode\Security\PathException;
use Phycode\Support\Files;
use Phycode\Support\Json;
use Phycode\Support\PhycodeException;

final class SessionStore
{
    private const ID = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D';
    private const ROLES = ['system', 'user', 'assistant', 'tool'];

    public function __construct(private readonly string $root)
    {
    }

    public static function newId(): string
    {
        return date('Ymd-His') . '-' . bin2hex(random_bytes(3));
    }

    public static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }

    public static function validId(string $id): bool
    {
        return preg_match(self::ID, $id) === 1;
    }

    public function all(): array
    {
        $folder = Files::runtimeFolder($this->root, 'sessions');
        if (!is_dir($folder)) {
            return [];
        }
        $sessions = [];
        foreach (scandir($folder) ?: [] as $name) {
            $id = str_ends_with($name, '.json') ? substr($name, 0, -5) : '';
            if (!self::validId($id)) {
                continue;
            }
            try {
                $session = $this->load($id);
            } catch (PhycodeException) {
                continue;
            }
            $sessions[] = [
                'id' => $id,
                'title' => $session['title'],
                'updatedAt' => $session['updatedAt'],
                'messages' => count($session['messages']),
                'parent' => $session['parent'] ?? null,
            ];
        }
        usort($sessions, static fn (array $a, array $b): int => [$b['updatedAt'], $b['id']] <=> [$a['updatedAt'], $a['id']]);

        return $sessions;
    }

    public function recent(int $limit): array
    {
        $folder = Files::runtimeFolder($this->root, 'sessions');
        if (!is_dir($folder)) {
            return [];
        }
        $modified = [];
        foreach (scandir($folder) ?: [] as $name) {
            $id = str_ends_with($name, '.json') ? substr($name, 0, -5) : '';
            if (self::validId($id) && !is_link($folder . '/' . $name)) {
                $modified[$id] = (int) @filemtime($folder . '/' . $name);
            }
        }
        arsort($modified);
        $sessions = [];
        foreach (array_slice(array_keys($modified), 0, max(0, $limit)) as $id) {
            try {
                $sessions[] = $this->load((string) $id);
            } catch (PhycodeException) {
                continue;
            }
        }

        return $sessions;
    }

    public function load(string $id): array
    {
        if (!self::validId($id)) {
            throw new PhycodeException('malformed session id; /sessions lists the ids');
        }
        $file = Files::runtimeFolder($this->root, 'sessions') . '/' . $id . '.json';
        if (is_link($file)) {
            throw new PhycodeException("session {$id} cannot be read: its file is a symbolic link");
        }
        if (!is_file($file)) {
            throw new PhycodeException("no session named {$id}; /sessions lists the saved ones");
        }
        $session = self::decode((string) file_get_contents($file), $id);
        if ($session === null) {
            throw new PhycodeException("session {$id} cannot be read: its file is not a saved session");
        }

        return $session;
    }

    public function save(array $session): void
    {
        $id = $session['id'] ?? null;
        if (!is_string($id) || !self::validId($id) || !self::valid($session, $id)) {
            throw new PhycodeException('the session is not valid, so it is not saved');
        }
        Files::writeAtomic($this->folder() . '/' . $id . '.json', Json::encode($session, true) . "\n", 0600);
    }

    private function folder(): string
    {
        $folder = Files::runtimeFolder($this->root, 'sessions');
        if (!is_dir($folder) && !mkdir($folder, 0700, true) && !is_dir($folder)) {
            throw new PhycodeException("cannot create the session folder {$folder}");
        }
        $ignore = dirname($folder) . '/.gitignore';
        if (!is_file($ignore) && !is_link($ignore)) {
            file_put_contents($ignore, "*\n");
        }

        return $folder;
    }

    private static function decode(string $json, string $id): ?array
    {
        $session = Json::object($json);

        return $session !== null && self::valid($session, $id) ? $session : null;
    }

    private static function valid(array $session, string $id): bool
    {
        return ($session['id'] ?? null) === $id
            && is_string($session['title'] ?? null)
            && is_string($session['createdAt'] ?? null)
            && is_string($session['updatedAt'] ?? null)
            && is_string($session['profile'] ?? null)
            && is_string($session['model'] ?? null)
            && in_array($session['thinking'] ?? null, Profile::THINKING, true)
            && self::forkValid($session)
            && self::messagesValid($session['messages'] ?? null);
    }

    private static function forkValid(array $session): bool
    {
        $parent = $session['parent'] ?? null;
        $at = $session['forkAt'] ?? null;

        return ($parent === null || (is_string($parent) && self::validId($parent) && $parent !== $session['id']))
            && ($at === null || (is_int($at) && $at >= 0));
    }

    private static function messagesValid(mixed $messages): bool
    {
        if (!is_array($messages) || !array_is_list($messages)) {
            return false;
        }
        foreach ($messages as $message) {
            if (!is_array($message) || !in_array($message['role'] ?? null, self::ROLES, true) || !is_string($message['content'] ?? null)) {
                return false;
            }
            if ($message['role'] === 'tool' && !is_string($message['tool_call_id'] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
