<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Web;

use Phycode\Web\WebTrigger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebTriggerTest extends TestCase
{
    public static function searchSpellings(): iterable
    {
        yield 'lower case' => ['Find it (web search)'];
        yield 'upper case' => ['Find it (WEB SEARCH)'];
        yield 'mixed case' => ['Find it (Web Search)'];
        yield 'Spanish with accent' => ['Busca (búsqueda web)'];
        yield 'Spanish upper case with accent' => ['Busca (BÚSQUEDA WEB)'];
        yield 'Spanish without accent' => ['Busca (busqueda web)'];
        yield 'spaces inside the parentheses' => ['Busca ( web   search )'];
    }

    #[DataProvider('searchSpellings')]
    public function testTheSearchTriggerTurnsSearchOnForTheTurn(string $message): void
    {
        $found = WebTrigger::detect($message);

        self::assertNotNull($found);
        self::assertTrue($found['search']);
        self::assertFalse($found['fetch']);
        self::assertNull($found['query']);
        self::assertSame([], $found['urls']);
        self::assertSame('web search ON · this turn', $found['chip']);
    }

    public function testTheTextAfterTheColonIsTheQueryAndKeepsItsAccents(): void
    {
        $found = WebTrigger::detect('Explica (búsqueda web: qué es la fotosíntesis)');

        self::assertNotNull($found);
        self::assertTrue($found['search']);
        self::assertSame('qué es la fotosíntesis', $found['query']);
        self::assertSame('Explica', $found['text'], 'the trigger is taken out of the text sent to the model');
    }

    public function testTheEnglishQueryFormGivesTheQuery(): void
    {
        $found = WebTrigger::detect('(WEB SEARCH: php 8.4 release date)');

        self::assertNotNull($found);
        self::assertSame('php 8.4 release date', $found['query']);
        self::assertSame('', $found['text']);
    }

    public function testAnEmptyColonLeavesNoQueryButSearchStaysOn(): void
    {
        $found = WebTrigger::detect('(web search:   )');

        self::assertNotNull($found);
        self::assertTrue($found['search']);
        self::assertNull($found['query']);
    }

    public function testTheFirstNonEmptyQueryWins(): void
    {
        $found = WebTrigger::detect('(web search:) one (web search: two)');

        self::assertNotNull($found);
        self::assertSame('two', $found['query']);
        self::assertSame('one', $found['text']);
    }

    public function testFetchWithoutAUrlTurnsFetchOnWithNoUrls(): void
    {
        $found = WebTrigger::detect('Summarize the page (web fetch)');

        self::assertNotNull($found);
        self::assertTrue($found['fetch']);
        self::assertFalse($found['search']);
        self::assertSame([], $found['urls']);
        self::assertSame('web fetch ON · this turn', $found['chip']);
    }

    public function testFetchWithUrlsListsEveryUrlOfTheMessage(): void
    {
        $found = WebTrigger::detect('Read (web fetch https://example.com/a) and https://example.org/b. Also HTTP://EXAMPLE.NET/c?x=1.');

        self::assertNotNull($found);
        self::assertTrue($found['fetch']);
        self::assertSame(['https://example.com/a', 'https://example.org/b', 'HTTP://EXAMPLE.NET/c?x=1'], $found['urls']);
        self::assertSame('Read and https://example.org/b. Also HTTP://EXAMPLE.NET/c?x=1.', $found['text']);
    }

    public function testTheSameUrlTwiceIsListedOnce(): void
    {
        $found = WebTrigger::detect('(web fetch https://example.com/a) again https://example.com/a');

        self::assertSame(['https://example.com/a'], $found['urls'] ?? []);
    }

    public function testUrlsAreNotListedWhenFetchIsOff(): void
    {
        $found = WebTrigger::detect('(web search) see https://example.com/a');

        self::assertNotNull($found);
        self::assertSame([], $found['urls']);
        self::assertFalse($found['fetch']);
    }

    public function testBothTriggersGiveBothFlagsAndOneChip(): void
    {
        $found = WebTrigger::detect('(web search: cats) and (web fetch https://example.com)');

        self::assertNotNull($found);
        self::assertTrue($found['search']);
        self::assertTrue($found['fetch']);
        self::assertSame('cats', $found['query']);
        self::assertSame('web search ON, web fetch ON · this turn', $found['chip']);
    }

    public static function notTriggers(): iterable
    {
        yield 'no trigger at all' => ['hello there'];
        yield 'a word after the trigger' => ['(web searching) is not a trigger'];
        yield 'a fetch with words that are not URLs' => ['(web fetch later)'];
        yield 'a query over two lines' => ["(web search: two\nlines)"];
        yield 'an unclosed trigger' => ['(web search'];
        yield 'a trigger in inline code' => ['Show `(web search)` only'];
        yield 'a double-backtick span' => ['Show ``(web fetch)`` only'];
        yield 'a trigger in a fenced block' => ["Look:\n```\n(web search)\n```"];
        yield 'a trigger in a tilde fence' => ["~~~php\n(web fetch https://example.com)\n~~~"];
        yield 'an unclosed fence' => ["```\n(web search)"];
    }

    #[DataProvider('notTriggers')]
    public function testCodeAndOtherTextAreNeverTriggers(string $message): void
    {
        self::assertNull(WebTrigger::detect($message));
    }

    public function testAFenceNextToATriggerLeavesTheCodeInTheText(): void
    {
        $found = WebTrigger::detect("Look at this:\n```php\n    \$x = (web search);\n```\nNow (web search: php)");

        self::assertNotNull($found);
        self::assertSame("Look at this:\n```php\n    \$x = (web search);\n```\nNow", $found['text']);
    }

    public function testAccentsDoNotMoveTheOffsetsOfTheText(): void
    {
        $found = WebTrigger::detect('ñandú ÁÉÍ (búsqueda web: niño) fin');

        self::assertNotNull($found);
        self::assertSame('niño', $found['query']);
        self::assertSame('ñandú ÁÉÍ fin', $found['text']);
    }

    public function testTheTextOfAMessageWithoutTriggersIsNotReturned(): void
    {
        self::assertNull(WebTrigger::detect(''));
    }

    public static function wordTriggers(): iterable
    {
        yield 'web search in English' => ['can you do web search?', true, false];
        yield 'busca en internet' => ['busca en internet la fecha de la luna', true, false];
        yield 'buscar en la web' => ['quiero buscar en la web algo', true, false];
        yield 'busqueda web' => ['hazme una busqueda web sobre Lisboa', true, false];
        yield 'search the web' => ['search the web for php 8.4', true, false];
        yield 'read a URL' => ['lee https://example.com/a y resúmelo', false, true];
        yield 'summarize a URL' => ['resume este articulo https://example.com/b', false, true];
        yield 'web fetch with a URL' => ['web fetch https://example.com/d', false, true];
        yield 'search and read' => ['busca en internet y lee https://example.com/c', true, true];
    }

    #[DataProvider('wordTriggers')]
    public function testWordsAskForTheTools(string $message, bool $search, bool $fetch): void
    {
        $found = WebTrigger::detect($message);

        self::assertNotNull($found);
        self::assertSame($search, $found['search']);
        self::assertSame($fetch, $found['fetch']);
    }

    public function testAWordTriggerStaysInTheTextOfTheMessage(): void
    {
        $found = WebTrigger::detect('busca en internet la fecha de la luna');

        self::assertNotNull($found);
        self::assertSame('busca en internet la fecha de la luna', $found['text']);
        self::assertSame('web search ON · this turn', $found['chip']);
    }

    public static function wordsThatAreNotTriggers(): iterable
    {
        yield 'a read verb without a URL' => ['lee el libro de cocina'];
        yield 'a fetch word without a URL' => ['web fetch later please'];
        yield 'a search phrase after an open parenthesis' => ['(busca en internet'];
        yield 'research is not a search' => ['my research is about cats'];
    }

    #[DataProvider('wordsThatAreNotTriggers')]
    public function testWordsThatAreNotTriggersDoNothing(string $message): void
    {
        self::assertNull(WebTrigger::detect($message));
    }
}
