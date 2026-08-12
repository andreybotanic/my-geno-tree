<?php

namespace App\Tests\Unit\Infrastructure\Doctrine\Dql;

use App\Entity\Person;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CustomDqlFunctionsTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
    }

    /** @param array<string, string|int> $parameters */
    #[DataProvider('concatWithSeparatorSql')]
    public function testConcatWithSeparatorFunctionCompiles(
        string $expression,
        string $expectedSql,
        array $parameters,
    ): void {
        self::assertStringContainsString($expectedSql, $this->compile($expression, $parameters));
    }

    /** @return iterable<string, array{string, string, array<string, string|int>}> */
    public static function concatWithSeparatorSql(): iterable
    {
        yield 'three name parts' => [
            "CONCAT_WS(' ', person.birthSurname.value, person.givenName.value, person.patronymic.value)",
            "concat_ws(' ', p0_.birth_surname_value, p0_.given_name_value, p0_.patronymic_value)",
            [],
        ];
        yield 'parameter separator and a literal value' => [
            "CONCAT_WS(:separator, person.birthSurname.value, 'Unknown')",
            "concat_ws(?, p0_.birth_surname_value, 'Unknown')",
            ['separator' => ' / '],
        ];
    }

    /** @param array<string, string|int> $parameters */
    #[DataProvider('leastIntegerSql')]
    public function testLeastIntegerFunctionCompiles(
        string $expression,
        string $expectedSql,
        array $parameters,
    ): void {
        self::assertStringContainsString($expectedSql, $this->compile($expression, $parameters));
    }

    /** @return iterable<string, array{string, string, array<string, string|int>}> */
    public static function leastIntegerSql(): iterable
    {
        yield 'integer literals' => [
            'LEAST_INTEGER(1, 3, 2)',
            'LEAST(1, 3, 2)',
            [],
        ];
        yield 'field arithmetic expression and parameter' => [
            'LEAST_INTEGER(person.birthDate.year, 2000 - 10, :limit)',
            'LEAST(p0_.birth_date_year, 2000 - 10, ?)',
            ['limit' => 1990],
        ];
    }

    /** @param array<string, string|int> $parameters */
    #[DataProvider('levenshteinLessEqualSql')]
    public function testLevenshteinLessEqualFunctionCompiles(
        string $expression,
        string $expectedSql,
        array $parameters,
    ): void {
        self::assertStringContainsString($expectedSql, $this->compile($expression, $parameters));
    }

    /** @return iterable<string, array{string, string, array<string, string|int>}> */
    public static function levenshteinLessEqualSql(): iterable
    {
        yield 'literal query and person field' => [
            "LEVENSHTEIN_LESS_EQUAL('ivanov', person.birthSurname.value, 2)",
            "levenshtein_less_equal('ivanov', p0_.birth_surname_value, 2)",
            [],
        ];
        yield 'parameters for every argument' => [
            'LEVENSHTEIN_LESS_EQUAL(:query, :value, :maximumDistance)',
            'levenshtein_less_equal(?, ?, ?)',
            ['query' => 'ivanov', 'value' => 'ivanova', 'maximumDistance' => 2],
        ];
    }

    /** @param array<string, string|int> $parameters */
    #[DataProvider('regexpReplaceTextSql')]
    public function testRegexpReplaceTextFunctionCompiles(
        string $expression,
        string $expectedSql,
        array $parameters,
    ): void {
        self::assertStringContainsString($expectedSql, $this->compile($expression, $parameters));
    }

    /** @return iterable<string, array{string, string, array<string, string|int>}> */
    public static function regexpReplaceTextSql(): iterable
    {
        yield 'field and literal arguments' => [
            "REGEXP_REPLACE_TEXT(person.birthSurname.value, '[^[:alnum:]]+', ' ', 'g')",
            "regexp_replace(p0_.birth_surname_value, '[^[:alnum:]]+', ' ', 'g')",
            [],
        ];
        yield 'parameters for every argument' => [
            'REGEXP_REPLACE_TEXT(:value, :pattern, :replacement, :flags)',
            'regexp_replace(?, ?, ?, ?)',
            ['value' => 'Ivanov-Sidorov', 'pattern' => '-', 'replacement' => ' ', 'flags' => 'g'],
        ];
    }

    /** @param array<string, string|int> $parameters */
    #[DataProvider('strictWordMatchSql')]
    public function testStrictWordMatchFunctionCompiles(
        string $expression,
        string $expectedSql,
        array $parameters,
    ): void {
        self::assertStringContainsString($expectedSql, $this->compile($expression, $parameters));
    }

    /** @return iterable<string, array{string, string, array<string, string|int>}> */
    public static function strictWordMatchSql(): iterable
    {
        yield 'literal query and person field' => [
            "STRICT_WORD_MATCH('ivanov', person.birthSurname.value)",
            "'ivanov' <<% p0_.birth_surname_value",
            [],
        ];
        yield 'parameter query and nested custom function' => [
            "STRICT_WORD_MATCH(:query, CONCAT_WS(' ', person.birthSurname.value, person.givenName.value))",
            "? <<% concat_ws(' ', p0_.birth_surname_value, p0_.given_name_value)",
            ['query' => 'ivanov ivan'],
        ];
    }

    /** @param array<string, string|int> $parameters */
    #[DataProvider('strictWordSimilaritySql')]
    public function testStrictWordSimilarityFunctionCompiles(
        string $expression,
        string $expectedSql,
        array $parameters,
    ): void {
        self::assertStringContainsString($expectedSql, $this->compile($expression, $parameters));
    }

    /** @return iterable<string, array{string, string, array<string, string|int>}> */
    public static function strictWordSimilaritySql(): iterable
    {
        yield 'literal query and person field' => [
            "STRICT_WORD_SIMILARITY('ivanov', person.birthSurname.value)",
            "strict_word_similarity('ivanov', p0_.birth_surname_value)",
            [],
        ];
        yield 'parameter query and nested custom function' => [
            "STRICT_WORD_SIMILARITY(:query, CONCAT_WS(' ', person.birthSurname.value, person.givenName.value))",
            "strict_word_similarity(?, concat_ws(' ', p0_.birth_surname_value, p0_.given_name_value))",
            ['query' => 'ivanov ivan'],
        ];
    }

    /** @param array<string, string|int> $parameters */
    #[DataProvider('translateTextSql')]
    public function testTranslateTextFunctionCompiles(
        string $expression,
        string $expectedSql,
        array $parameters,
    ): void {
        self::assertStringContainsString($expectedSql, $this->compile($expression, $parameters));
    }

    /** @return iterable<string, array{string, string, array<string, string|int>}> */
    public static function translateTextSql(): iterable
    {
        yield 'person field and literals' => [
            "TRANSLATE_TEXT(person.givenName.value, 'Ёё', 'Ее')",
            "translate(p0_.given_name_value, 'Ёё', 'Ее')",
            [],
        ];
        yield 'parameters for every argument' => [
            'TRANSLATE_TEXT(:value, :from, :to)',
            'translate(?, ?, ?)',
            ['value' => 'Ёлка', 'from' => 'Ёё', 'to' => 'Ее'],
        ];
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function compile(string $expression, array $parameters): string
    {
        $queryBuilder = $this->entityManager
            ->createQueryBuilder()
            ->select($expression . ' AS result')
            ->from(Person::class, 'person');

        foreach ($parameters as $name => $value) {
            $queryBuilder->setParameter($name, $value);
        }

        $sql = $queryBuilder->getQuery()->getSQL();
        self::assertIsString($sql);

        return $sql;
    }
}
