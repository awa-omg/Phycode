<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Llm;

use Phycode\Agent\Conversation;
use Phycode\Llm\Image;
use Phycode\Support\PhycodeException;
use Phycode\Tests\Support\Kit;
use Phycode\Tests\Support\Pictures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageTest extends TestCase
{
    public static function formats(): iterable
    {
        yield 'png' => ['png', 'image/png'];
        yield 'jpeg' => ['jpg', 'image/jpeg'];
        yield 'gif' => ['gif', 'image/gif'];
        yield 'webp' => ['webp', 'image/webp'];
    }

    #[DataProvider('formats')]
    public function testTheFourFormatsAreRecognisedByTheirBytesWhateverTheNameSays(string $type, string $mime): void
    {
        $image = Image::fromBytes(Pictures::bytes($type), 'noname.txt');

        self::assertSame($mime, $image->mime);
        self::assertSame([1, 1], [$image->width, $image->height]);
        self::assertSame(Pictures::base64($type), $image->data);
        self::assertSame(strlen(Pictures::bytes($type)), $image->bytes);
    }

    public function testAFileBecomesAnImageWithItsNameAndAPlaceholder(): void
    {
        $image = Image::fromFile(Pictures::file('login-error.png'), 'login-error.png');

        self::assertSame('[image: login-error.png, PNG 1x1]', $image->placeholder());
        self::assertSame($image->toArray(), Image::fromArray($image->toArray())?->toArray());
    }

    public function testWhatIsNotAnImageOrIsTooLargeIsRefusedWithAReason(): void
    {
        $folder = Kit::folder('image-bad');
        file_put_contents($folder . '/text.png', "just text, with a png name\n");
        file_put_contents($folder . '/cut.png', substr(Pictures::bytes('png'), 0, 20));
        file_put_contents($folder . '/big.png', Pictures::bytes('png') . str_repeat("\0", Image::MAX_BYTES));
        symlink($folder . '/text.png', $folder . '/link.png');

        foreach ([['text.png', 'is not an image that models read'], ['cut.png', 'is not an image that models read'], ['big.png', 'an image may be at most 3.5 MB'], ['nothing.png', 'is not a file'], ['link.png', 'is not a file']] as [$name, $message]) {
            try {
                Image::fromFile($folder . '/' . $name, $name);
                self::fail($name);
            } catch (PhycodeException $error) {
                self::assertStringContainsString($message, $error->getMessage(), $name);
            }
        }
        self::assertNull(Image::sniff('GIF'));
        self::assertNull(Image::sniff(''));
    }

    public function testAConversationKeepsTheImagesOfAMessageAndAPlainMessageHasNone(): void
    {
        $conversation = new Conversation();
        $image = Image::fromBytes(Pictures::bytes(), 'a.png');
        $conversation->addUser('look', [$image]);
        $conversation->addUser('plain');

        $messages = $conversation->messages();

        self::assertSame([$image->toArray()], $messages[0]['images']);
        self::assertArrayNotHasKey('images', $messages[1]);
        self::assertCount(1, Image::of($messages[0]));
        self::assertSame("look\n[image: a.png, PNG 1x1]", Image::textWithPlaceholders($messages[0]));
        self::assertSame('plain', Image::textWithPlaceholders($messages[1]));
        self::assertSame([], Image::of(['images' => [['mime' => 'image/bmp', 'data' => 'x'], 'junk']]));
    }
}
