<?php

declare(strict_types=1);

namespace Phycode\Web;

final class Reply
{
    public function __construct(
        public readonly int $status,
        public readonly ?string $location,
        public readonly ?string $contentType,
        public readonly string $body,
        public readonly bool $truncated,
    ) {
    }

    public function mediaType(): ?string
    {
        return self::mediaTypeOf($this->contentType);
    }

    public function charset(): ?string
    {
        if ($this->contentType === null || preg_match('/;\s*charset\s*=\s*"?([^";\s]+)/i', $this->contentType, $match) !== 1) {
            return null;
        }

        return strtolower($match[1]);
    }

    public static function mediaTypeOf(?string $contentType): ?string
    {
        return $contentType === null ? null : strtolower(trim(explode(';', $contentType, 2)[0]));
    }
}
