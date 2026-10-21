<?php

declare(strict_types=1);

namespace Phycode\Llm;

use Phycode\Support\PhycodeException;

final readonly class Image
{
    public const MAX_BYTES = 3_500_000;
    public const TYPES = ['image/png' => 'PNG', 'image/jpeg' => 'JPEG', 'image/gif' => 'GIF', 'image/webp' => 'WebP'];
    public const ESTIMATED_TOKENS = 1300;

    public function __construct(
        public string $mime,
        public string $data,
        public string $name,
        public int $width,
        public int $height,
        public int $bytes,
    ) {
    }

    public static function fromFile(string $path, string $name): self
    {
        if (!is_file($path) || is_link($path)) {
            throw new PhycodeException("{$name} is not a file");
        }
        $size = (int) filesize($path);
        if ($size > self::MAX_BYTES) {
            throw new PhycodeException(sprintf('%s is %.1f MB; an image may be at most %.1f MB (save it smaller, as JPEG for example)', $name, $size / 1_000_000, self::MAX_BYTES / 1_000_000));
        }

        return self::fromBytes((string) file_get_contents($path), $name);
    }

    public static function fromBytes(string $bytes, string $name): self
    {
        $mime = self::sniff($bytes);
        $info = $mime === null ? false : @getimagesizefromstring($bytes);
        if ($mime === null || $info === false || $info[0] < 1 || $info[1] < 1) {
            throw new PhycodeException("{$name} is not an image that models read: PNG, JPEG, GIF and WebP are");
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new PhycodeException("{$name} is too large for a model: at most " . (self::MAX_BYTES / 1_000_000) . ' MB');
        }

        return new self($mime, base64_encode($bytes), $name, $info[0], $info[1], strlen($bytes));
    }

    public static function sniff(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, 'GIF87a'), str_starts_with($bytes, 'GIF89a') => 'image/gif',
            strlen($bytes) > 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
    }

    public function placeholder(): string
    {
        return sprintf('[image: %s, %s %dx%d]', $this->name, self::TYPES[$this->mime] ?? $this->mime, $this->width, $this->height);
    }

    public function toArray(): array
    {
        return ['mime' => $this->mime, 'data' => $this->data, 'name' => $this->name, 'width' => $this->width, 'height' => $this->height, 'bytes' => $this->bytes];
    }

    public static function fromArray(array $image): ?self
    {
        if (!is_string($image['mime'] ?? null) || !isset(self::TYPES[$image['mime']]) || !is_string($image['data'] ?? null) || $image['data'] === '') {
            return null;
        }

        return new self(
            $image['mime'],
            $image['data'],
            is_string($image['name'] ?? null) ? $image['name'] : 'image',
            is_int($image['width'] ?? null) ? $image['width'] : 0,
            is_int($image['height'] ?? null) ? $image['height'] : 0,
            is_int($image['bytes'] ?? null) ? $image['bytes'] : (int) (strlen($image['data']) * 3 / 4),
        );
    }

    public static function of(array $message): array
    {
        $images = [];
        foreach (is_array($message['images'] ?? null) ? $message['images'] : [] as $raw) {
            $image = is_array($raw) ? self::fromArray($raw) : null;
            if ($image !== null) {
                $images[] = $image;
            }
        }

        return $images;
    }

    public static function textWithPlaceholders(array $message): string
    {
        $text = is_string($message['content'] ?? null) ? $message['content'] : '';
        $lines = array_map(static fn (self $image): string => $image->placeholder(), self::of($message));

        return trim($text . ($lines === [] ? '' : "\n" . implode("\n", $lines)));
    }
}
