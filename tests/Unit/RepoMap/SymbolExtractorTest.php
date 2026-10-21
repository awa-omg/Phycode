<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\RepoMap;

use Phycode\RepoMap\SymbolExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SymbolExtractorTest extends TestCase
{
    private static function brief(string $path, string $text): array
    {
        return array_map(static fn (array $s): string => $s['kind'] . ' ' . ($s['parent'] !== null ? $s['parent'] . '.' : '') . $s['name'], SymbolExtractor::extract($path, $text));
    }

    public function testPhpClassesMethodsAndFunctionsWithTheirSignatures(): void
    {
        $text = <<<'PHP'
<?php

namespace App;

/** A class. */
final class Loader extends Base implements Thing
{
    public const NAME = 'x';

    public function __construct(private readonly string $root, int $depth = 2)
    {
        $this->go($depth);
    }

    public static function load(string $path): ?array
    {
        if (is_file($path)) {
            return foo($path);
        }
    }

    private function go(int $n): void {}
}

interface Thing
{
    public function run(): void;
}

function helper(array $items = []): string
{
    return '';
}
PHP;

        $symbols = SymbolExtractor::extract('src/Loader.php', $text);

        self::assertSame([
            'class Loader', 'method Loader.__construct', 'method Loader.load', 'method Loader.go', 'interface Thing', 'method Thing.run', 'function helper',
        ], array_map(static fn (array $s): string => $s['kind'] . ' ' . ($s['parent'] !== null ? $s['parent'] . '.' : '') . $s['name'], $symbols));
        self::assertSame('load(string $path): ?array', $symbols[2]['sig']);
        self::assertSame('__construct(private readonly string $root, int $depth = 2)', $symbols[1]['sig']);
        self::assertSame(15, $symbols[2]['line']);
        self::assertSame('helper(array $items = []): string', $symbols[6]['sig']);
    }

    public function testTypeScriptClassesFunctionsArrowFunctionsTypesAndMethods(): void
    {
        $text = <<<'TS'
import { x } from './x';

export interface Options {
  retries: number;
}

export type Id = string | number;

export class Client extends Base {
  private cache = new Map();

  constructor(private readonly url: string) {
    super();
  }

  async fetch(path: string, options?: Options): Promise<Response> {
    if (path === '') {
      return this.get(path);
    }
    for (const x of y) {
      doIt(x);
    }
  }

  static create(url: string): Client {
    return new Client(url);
  }
}

export async function load(id: Id): Promise<void> {
  await run(id);
}

export const parse = (text: string): number => {
  return 1;
};

const double = async (n) => n * 2;
TS;

        self::assertSame([
            'interface Options', 'type Id', 'class Client', 'method Client.constructor', 'method Client.fetch', 'method Client.create', 'function load', 'function parse', 'function double',
        ], self::brief('src/client.ts', $text));
    }

    public function testPythonClassesMethodsAndFunctionsButNotNestedOnes(): void
    {
        $text = <<<'PY'
import os


class Store(Base):
    """A store."""

    def __init__(self, path: str) -> None:
        self.path = path

        def inner():
            pass

    async def save(self, data: dict) -> bool:
        if data:
            return True


def helper(x, y=2):
    def local():
        pass
    return x


class Other:
    def run(self): ...
PY;

        self::assertSame(['class Store', 'method Store.__init__', 'method Store.save', 'function helper', 'class Other', 'method Other.run'], self::brief('app/store.py', $text));
    }

    public function testGoFunctionsMethodsWithTheirReceiverAndTypes(): void
    {
        $text = <<<'GO'
package main

type Server struct {
	addr string
}

type Handler interface {
	Serve() error
}

type ID string

func New(addr string) *Server {
	return &Server{addr}
}

func (s *Server) Start(port int) error {
	return nil
}

func (s Server) name() string { return "" }
GO;

        self::assertSame(['struct Server', 'interface Handler', 'type ID', 'function New', 'method Server.Start', 'method Server.name'], self::brief('main.go', $text));
    }

    public function testRustItemsAndTheMethodsOfAnImpl(): void
    {
        $text = <<<'RS'
pub struct Config {
    pub name: String,
}

pub trait Run {
    fn run(&self) -> Result<(), Error>;
}

impl Config {
    pub fn new(name: &str) -> Self {
        Self { name: name.into() }
    }

    pub async fn load(&self) -> Result<(), Error> {
        Ok(())
    }
}

impl Run for Config {
    fn run(&self) -> Result<(), Error> {
        Ok(())
    }
}

pub fn main() {}
RS;

        self::assertSame(['struct Config', 'trait Run', 'method Run.run', 'method Config.new', 'method Config.load', 'method Config.run', 'function main'], self::brief('src/lib.rs', $text));
    }

    public function testJavaAndKotlinClassesAndMethodsWithoutTheStatementsInsideThem(): void
    {
        $java = <<<'JAVA'
package app;

public class Greeter implements Named {
    private final String name;

    public Greeter(String name) {
        this.name = name;
    }

    public String greet(String other) {
        if (other == null) {
            return "hi";
        }
        System.out.println(other);
        return name + other;
    }

    static void main(String[] args) {
        new Greeter("x").greet("y");
    }
}
JAVA;
        self::assertSame(['class Greeter', 'method Greeter.Greeter', 'method Greeter.greet', 'method Greeter.main'], self::brief('Greeter.java', $java));

        $kotlin = <<<'KT'
class Repo(private val db: Db) {
    fun find(id: Int): User? {
        return db.get(id)
    }

    suspend fun save(user: User) {
        db.put(user)
    }
}
KT;
        self::assertSame(['class Repo', 'method Repo.find', 'method Repo.save'], self::brief('Repo.kt', $kotlin));
    }

    public function testRubyModulesClassesAndMethods(): void
    {
        $text = <<<'RB'
module App
  class User
    def initialize(name)
      @name = name
    end

    def self.build(attrs)
      new(attrs[:name])
    end

    def valid?
      !@name.empty?
    end
  end
end
RB;

        self::assertSame(['module App', 'class App.User', 'method User.initialize', 'method User.build', 'method User.valid?'], self::brief('app/user.rb', $text));
    }

    public function testCAndCppStructsAndClassesOnly(): void
    {
        $text = "struct Point {\n  int x;\n};\n\nclass Shape : public Base {\npublic:\n  void draw();\n};\n\nint main() { return 0; }\n";

        self::assertSame(['struct Point', 'class Shape'], self::brief('shapes.h', $text));
    }

    public static function nothing(): iterable
    {
        yield 'a text file' => ['README.md'];
        yield 'json' => ['composer.json'];
        yield 'no extension' => ['Makefile'];
    }

    #[DataProvider('nothing')]
    public function testOtherFilesHaveNoSymbols(string $path): void
    {
        self::assertNull(SymbolExtractor::languageOf($path));
        self::assertSame([], SymbolExtractor::extract($path, "class Fake {\n  function x() {}\n}\n"));
    }

    public function testCommentsAndVeryLongLinesAreNotDefinitions(): void
    {
        $text = "<?php\n// class Fake {}\n/*\n * function nope()\n */\n# function alsoNot()\n" . str_repeat('x', 500) . "\nfunction real() {}\n";

        self::assertSame(['function real'], self::brief('a.php', $text));
    }

    public function testALongSignatureIsCut(): void
    {
        $symbols = SymbolExtractor::extract('a.php', "<?php\nfunction big(" . str_repeat('int $argument, ', 12) . "int \$last) {}\n");

        self::assertSame(100, strlen($symbols[0]['sig']));
        self::assertStringEndsWith('...', $symbols[0]['sig']);
    }
}
