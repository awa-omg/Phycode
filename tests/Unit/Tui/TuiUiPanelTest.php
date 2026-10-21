<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Tui;

use Phycode\Tui\TuiUi;
use PHPUnit\Framework\TestCase;

final class TuiUiPanelTest extends TestCase
{
    public function testAPanelAndAListPanelAreTranscriptLinesOfPlainText(): void
    {
        $ui = (new \ReflectionClass(TuiUi::class))->newInstanceWithoutConstructor();

        $ui->panel('Status', "version 0.1.0\nprofile local");
        $ui->listPanel('Skills', [['category' => 'Project', 'title' => 'go', 'detail' => 'Go tests.', 'status' => '']], 'one skill');

        self::assertSame([
            ['style' => 'plain', 'text' => "Status\nversion 0.1.0\nprofile local"],
            ['style' => 'plain', 'text' => "Skills\nProject\n  go  Go tests.\n\none skill"],
        ], $ui->transcript());
    }
}
