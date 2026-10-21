<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Web;

use Phycode\Web\HtmlMarkdown;
use PHPUnit\Framework\TestCase;

final class HtmlMarkdownTest extends TestCase
{
    private const BASE = 'http://example.test/guide/page.html';

    public function testTheArticleFixtureConvertsToTheCheckedMarkdown(): void
    {
        $result = HtmlMarkdown::convert(FixtureServer::fixture('article.html'), self::BASE);

        self::assertSame('Fixture article: the basics', $result['title']);
        self::assertSame(trim(FixtureServer::fixture('article.md')), $result['markdown']);
    }

    public function testScriptsStylesAndPageFurnitureAreDropped(): void
    {
        $markdown = self::markdown(FixtureServer::fixture('article.html'));

        foreach (['SCRIPT-TEXT', 'ALSO-NOT-SHOWN', 'color: red', 'ASIDE-TEXT', 'FOOTER-TEXT', 'NOSCRIPT-TEXT', 'Menu'] as $sentinel) {
            self::assertStringNotContainsString($sentinel, $markdown);
        }
    }

    public function testRelativeLinksResolveAgainstThePageUrl(): void
    {
        self::assertSame(
            '[up](http://example.test/a/up.html) and [down](http://example.test/a/b/c/down.html)',
            self::markdown('<p><a href="../up.html">up</a> and <a href="c/down.html">down</a></p>', 'http://example.test/a/b/page.html'),
        );
    }

    public function testABaseTagChangesTheBaseOfTheLinks(): void
    {
        self::assertSame(
            '[x](http://example.test/shared/x)',
            self::markdown('<base href="/shared/"><p><a href="x">x</a></p>'),
        );
    }

    public function testLinksThatAreNotWebAddressesKeepOnlyTheirText(): void
    {
        self::assertSame(
            'Top mail js plain ftp',
            self::markdown('<p><a href="#top">Top</a> <a href="mailto:a@b.test">mail</a> <a href="javascript:void(0)">js</a> <a>plain</a> <a href="ftp://x.test/y">ftp</a></p>'),
        );
    }

    public function testLinkTextIsEscapedAndSpacesInTheUrlAreEncoded(): void
    {
        self::assertSame(
            '[see \[this\]](http://example.test/a%20b%281%29.html)',
            self::markdown('<p><a href="/a b(1).html">see [this]</a></p>'),
        );
    }

    public function testALinkWithoutTextBecomesAnAutolink(): void
    {
        self::assertSame('<http://example.test/x>', self::markdown('<p><a href="/x">   </a></p>'));
    }

    public function testNestedBulletsAreIndentedUnderTheirParent(): void
    {
        self::assertSame(
            "- one\n  - two\n- three",
            self::markdown('<ul><li>one<ul><li>two</li></ul></li><li>three</li></ul>'),
        );
    }

    public function testChildrenOfANumberedItemAreIndentedToTheNumberWidth(): void
    {
        self::assertSame("1. a\n   - b", self::markdown('<ol><li>a<ul><li>b</li></ul></li></ol>'));
        self::assertSame("9. x\n10. y", self::markdown('<ol start="9"><li>x</li><li>y</li></ol>'));
    }

    public function testCodeKeepsItsIndentationAndItsLanguage(): void
    {
        self::assertSame(
            "```js\n  if (a) {\n\tb();\n  }\n```",
            self::markdown("<pre><code class=\"language-js\">  if (a) {\n\tb();\n  }</code></pre>"),
        );
    }

    public function testACodeBlockWithBackticksInsideGetsALongerFence(): void
    {
        self::assertSame("````\na ``` b\n````", self::markdown('<pre>a ``` b</pre>'));
    }

    public function testWhitespaceCollapsesAndNonBreakingSpacesBecomeSpaces(): void
    {
        self::assertSame('a b c', self::markdown("<p>  a \n\n  b&nbsp;&nbsp;c  </p>"));
    }

    public function testHeadingsAndParagraphsAreSeparatedByBlankLines(): void
    {
        self::assertSame(
            "## Title x\n\nOne\n\nTwo",
            self::markdown('<h2>Title <em>x</em></h2><p>One</p><p>Two</p>'),
        );
    }

    public function testEntitiesAreDecodedToTheirCharacters(): void
    {
        self::assertSame('<tag> & "q"', self::markdown('<p>&lt;tag&gt; &amp; &quot;q&quot;</p>'));
    }

    public function testBlockquoteLinesAreQuotedAndARuleBecomesDashes(): void
    {
        self::assertSame("> one\n> two\n\n---\n\nafter", self::markdown('<blockquote>one<br>two</blockquote><hr><p>after</p>'));
    }

    public function testATableIsOneLinePerRow(): void
    {
        self::assertSame("a | b\nc", self::markdown('<table><tr><td>a</td><td>b</td></tr><tr><td>c</td></tr></table>'));
    }

    public function testBrokenMarkupStillConvertsToParagraphs(): void
    {
        self::assertSame("one\n\ntwo\n\nthree", self::markdown('<p>one<p>two<div>three'));
    }

    public function testAPageWithoutATitleHasAnEmptyTitle(): void
    {
        self::assertSame('', HtmlMarkdown::convert('<p>text</p>', self::BASE)['title']);
    }

    public function testTextFiveHundredLevelsDeepIsKept(): void
    {
        self::assertSame('found text', self::markdown(str_repeat('<div>', 500) . 'found text' . str_repeat('</div>', 500)));
    }

    public function testNestingFarBeyondAnyPageDoesNotExhaustMemory(): void
    {
        self::assertSame('', self::markdown(str_repeat('<div>', 200_000) . 'lost text' . str_repeat('</div>', 200_000)));
    }

    public function testAnEmptyDocumentConvertsToNothing(): void
    {
        self::assertSame(['title' => '', 'markdown' => ''], HtmlMarkdown::convert('', self::BASE));
    }

    private static function markdown(string $html, string $base = self::BASE): string
    {
        return HtmlMarkdown::convert($html, $base)['markdown'];
    }
}
