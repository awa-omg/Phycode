<?php

declare(strict_types=1);

namespace Phycode\Tests\Unit\Support;

use Phycode\Support\JsonSchema;
use Phycode\Support\PhycodeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JsonSchemaTest extends TestCase
{
    private const PERSON = [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string', 'minLength' => 1],
            'age' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 150],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 3, 'uniqueItems' => true],
            'role' => ['enum' => ['admin', 'user']],
        ],
        'required' => ['name'],
        'additionalProperties' => false,
    ];

    public static function people(): iterable
    {
        yield 'valid' => [['name' => 'Ana', 'age' => 30, 'tags' => ['a', 'b'], 'role' => 'admin'], []];
        yield 'only the required one' => [['name' => 'Ana'], []];
        yield 'an integer written as 30.0' => [['name' => 'Ana', 'age' => 30.0], []];
        yield 'missing required' => [['age' => 3], ['$: the property \'name\' is required']];
        yield 'wrong type' => [['name' => 5], ['$.name: expected string, got integer']];
        yield 'fraction is not an integer' => [['name' => 'A', 'age' => 3.5], ['$.age: expected integer, got number']];
        yield 'too small' => [['name' => 'A', 'age' => -1], ['$.age: must be at least 0, got -1']];
        yield 'too big' => [['name' => 'A', 'age' => 151], ['$.age: must be at most 150, got 151']];
        yield 'empty name' => [['name' => ''], ['$.name: must have at least 1 characters, has 0']];
        yield 'extra property' => [['name' => 'A', 'extra' => 1], ['$.extra: no value is allowed here']];
        yield 'item of the wrong type' => [['name' => 'A', 'tags' => ['a', 2]], ['$.tags[1]: expected string, got integer']];
        yield 'too many items' => [['name' => 'A', 'tags' => ['a', 'b', 'c', 'd']], ['$.tags: must have at most 3 items, has 4']];
        yield 'duplicates' => [['name' => 'A', 'tags' => ['a', 'b', 'a']], ['$.tags[2]: duplicates the item at [0]']];
        yield 'enum' => [['name' => 'A', 'role' => 'root'], ['$.role: must be one of ["admin","user"], got "root"']];
        yield 'not an object' => [['a', 'b'], ['$: expected object, got array']];
        yield 'a string is not an object' => ['text', ['$: expected object, got string']];
        yield 'two problems' => [['age' => 'x'], ['$: the property \'name\' is required', '$.age: expected integer, got string']];
    }

    #[DataProvider('people')]
    public function testAValueIsHeldToTheSchemaAndEveryProblemHasItsPath(mixed $value, array $expected): void
    {
        self::assertSame($expected, JsonSchema::errors($value, self::PERSON));
    }

    public function testAnEmptyArrayIsAnEmptyObjectOrAnEmptyListAsTheSchemaAsks(): void
    {
        self::assertSame(['$: the property \'name\' is required'], JsonSchema::errors([], self::PERSON));
        self::assertSame([], JsonSchema::errors([], ['type' => 'array']));
        self::assertSame(['$: must have at least 1 items, has 0'], JsonSchema::errors([], ['type' => 'array', 'minItems' => 1]));
        self::assertSame([], JsonSchema::errors([], ['type' => 'object']));
    }

    public function testRefsReachTheDefinitionsOfTheSameSchema(): void
    {
        $schema = [
            'type' => 'object',
            '$defs' => ['point' => ['type' => 'object', 'properties' => ['x' => ['type' => 'number']], 'required' => ['x']]],
            'properties' => ['from' => ['$ref' => '#/$defs/point'], 'to' => ['$ref' => '#/$defs/point']],
        ];
        JsonSchema::check($schema);

        self::assertSame([], JsonSchema::errors(['from' => ['x' => 1], 'to' => ['x' => 2.5]], $schema));
        self::assertSame(['$.to: the property \'x\' is required'], JsonSchema::errors(['from' => ['x' => 1], 'to' => []], $schema));
    }

    public function testATreeThatRefersToItselfIsValidatedDownToTheDataAndACycleStops(): void
    {
        $tree = ['type' => 'object', 'properties' => ['value' => ['type' => 'integer'], 'children' => ['type' => 'array', 'items' => ['$ref' => '#']]], 'required' => ['value']];
        JsonSchema::check($tree);

        self::assertSame([], JsonSchema::errors(['value' => 1, 'children' => [['value' => 2, 'children' => [['value' => 3]]]]], $tree));
        self::assertSame(['$.children[0].children[0].value: expected integer, got string'], JsonSchema::errors(['value' => 1, 'children' => [['value' => 2, 'children' => [['value' => 'x']]]]], $tree));

        $cycle = ['$ref' => '#'];
        self::assertSame(['$: the schema nests too deeply'], JsonSchema::errors(1, $cycle));
    }

    public function testCombinationsAndNot(): void
    {
        $schema = ['anyOf' => [['type' => 'string'], ['type' => 'integer', 'minimum' => 10]]];
        self::assertSame([], JsonSchema::errors('x', $schema));
        self::assertSame([], JsonSchema::errors(12, $schema));
        self::assertSame(['$: matches none of the alternatives (anyOf)'], JsonSchema::errors(3, $schema));

        $one = ['oneOf' => [['type' => 'integer'], ['type' => 'number', 'minimum' => 0]]];
        self::assertSame([], JsonSchema::errors(-1, $one));
        self::assertSame(['$: must match exactly one alternative (oneOf), matches 2'], JsonSchema::errors(5, $one));

        self::assertSame([], JsonSchema::errors('a', ['allOf' => [['type' => 'string'], ['minLength' => 1]]]));
        self::assertSame(['$: must have at least 2 characters, has 1'], JsonSchema::errors('a', ['allOf' => [['type' => 'string'], ['minLength' => 2]]]));
        self::assertSame(['$: must not match the schema in \'not\''], JsonSchema::errors('a', ['not' => ['type' => 'string']]));
        self::assertSame(['$: expected string or null, got integer'], JsonSchema::errors(1, ['type' => ['string', 'null']]));
        self::assertSame([], JsonSchema::errors(null, ['type' => ['string', 'null']]));
    }

    public function testPatternConstAndNumberBounds(): void
    {
        self::assertSame([], JsonSchema::errors('a/b~c', ['type' => 'string', 'pattern' => '^a/b~c$']));
        self::assertSame(['$: must match the pattern ^\d+$'], JsonSchema::errors('x1', ['pattern' => '^\d+$']));
        self::assertSame([], JsonSchema::errors(1.0, ['const' => 1]));
        self::assertSame(['$: must be "1", got 1'], JsonSchema::errors(1, ['const' => '1']));
        self::assertSame(['$: must be more than 0, got 0'], JsonSchema::errors(0, ['exclusiveMinimum' => 0]));
        self::assertSame(['$: must be less than 5, got 5'], JsonSchema::errors(5, ['exclusiveMaximum' => 5]));
        self::assertSame([], JsonSchema::errors(['a' => 1], ['minProperties' => 1, 'maxProperties' => 2]));
        self::assertSame(['$: must have at most 1 properties, has 2'], JsonSchema::errors(['a' => 1, 'b' => 2], ['maxProperties' => 1]));
    }

    public function testNoMoreThanTenProblemsAreReported(): void
    {
        $errors = JsonSchema::errors(range(1, 30), ['type' => 'array', 'items' => ['type' => 'string']]);

        self::assertCount(10, $errors);
    }

    public function testTheDescribingKeywordsAreIgnored(): void
    {
        $schema = ['$schema' => 'https://json-schema.org/draft/2020-12/schema', 'title' => 'x', 'description' => 'd', 'type' => 'string', 'format' => 'email', 'default' => 'a', 'examples' => ['a@b.c']];
        JsonSchema::check($schema);

        self::assertSame([], JsonSchema::errors('not an email', $schema), 'format is an annotation');
    }

    public static function refused(): iterable
    {
        yield 'a keyword that is not supported' => [['type' => 'object', 'patternProperties' => ['^a' => ['type' => 'string']]], "schema #: the keyword 'patternProperties' is not supported"];
        yield 'nested' => [['properties' => ['a' => ['multipleOf' => 2]]], "schema #/properties/a: the keyword 'multipleOf' is not supported"];
        yield 'unknown type' => [['type' => 'float'], "schema #: the type 'float' is not one of"];
        yield 'type as a number' => [['type' => 5], "schema #: the type '5' is not one of"];
        yield 'required not a list' => [['required' => 'name'], 'schema #: required must be a list of property names'];
        yield 'properties as a list' => [['properties' => [['type' => 'string']]], 'schema #/properties must be an object of schemas'];
        yield 'bad pattern' => [['pattern' => '(['], 'schema #: pattern is not a valid regular expression'];
        yield 'a ref outside the schema' => [['$ref' => 'https://example.com/s.json'], "schema #: \$ref 'https://example.com/s.json' does not name a place"];
        yield 'a ref to nothing' => [['$ref' => '#/$defs/missing'], "schema #: \$ref '#/\$defs/missing' does not name a place"];
        yield 'negative size' => [['minItems' => -1], 'schema #: minItems must be a whole number of at least 0'];
        yield 'empty enum' => [['enum' => []], 'schema #: enum must be a non-empty list'];
        yield 'a schema that is a list' => [['allOf' => [['type' => 'string'], 5]], 'schema #/allOf/1 must be an object'];
        yield 'a bad definition' => [['$defs' => ['a' => ['oops' => 1]]], "schema #/\$defs/a: the keyword 'oops' is not supported"];
    }

    #[DataProvider('refused')]
    public function testASchemaThatCannotBeEnforcedIsRefusedWithWhereAndWhy(array $schema, string $message): void
    {
        $this->expectException(PhycodeException::class);
        $this->expectExceptionMessage($message);

        JsonSchema::check($schema);
    }

    public static function answers(): iterable
    {
        yield 'plain' => ['{"a":1}', ['a' => 1]];
        yield 'with spaces and newlines' => ["\n  {\"a\": 1}\n", ['a' => 1]];
        yield 'in a fence' => ["Here it is:\n```json\n{\"a\": [1, 2]}\n```\nDone.", ['a' => [1, 2]]];
        yield 'in a fence without a language' => ["```\n[1,2]\n```", [1, 2]];
        yield 'inside a sentence' => ['The result is {"ok": true} as asked.', ['ok' => true]];
        yield 'an array in text' => ['Items: ["a","b"] end', ['a', 'b']];
        yield 'a number' => ['42', 42];
        yield 'null' => ['null', null];
    }

    #[DataProvider('answers')]
    public function testTheJsonInTheAnswerOfAModelIsFound(string $text, mixed $expected): void
    {
        $value = 'untouched';

        self::assertTrue(JsonSchema::extract($text, $value));
        self::assertSame($expected, $value);
    }

    public function testAnAnswerWithoutJsonIsNotFound(): void
    {
        $value = 'untouched';

        self::assertFalse(JsonSchema::extract('I could not find anything {sorry', $value));
        self::assertFalse(JsonSchema::extract('', $value));
        self::assertSame('untouched', $value);
    }
}
