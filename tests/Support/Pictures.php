<?php

declare(strict_types=1);

namespace Phycode\Tests\Support;

final class Pictures
{
    private const DATA = [
        'png' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC',
        'jpg' => '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=',
        'gif' => 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7',
        'webp' => 'UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==',
    ];

    public static function bytes(string $type = 'png'): string
    {
        return (string) base64_decode(self::DATA[$type], true);
    }

    public static function base64(string $type = 'png'): string
    {
        return self::DATA[$type];
    }

    public static function file(string $name = 'shot.png', string $type = 'png'): string
    {
        $path = Kit::folder('pictures') . '/' . $name;
        file_put_contents($path, self::bytes($type));

        return $path;
    }
}
