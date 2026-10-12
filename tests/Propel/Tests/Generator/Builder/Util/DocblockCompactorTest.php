<?php

/**
 * MIT License. This file is part of the Propel package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Propel\Tests\Generator\Builder\Util;

use Propel\Generator\Builder\Util\DocblockCompactor;
use Propel\Tests\TestCase;

class DocblockCompactorTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function codeProvider(): array
    {
        return [
            'tags repeating the signature are removed, description stays' => [
                <<<'PHP'
    /**
     * Saves the object.
     *
     * @param \Propel\Runtime\Connection\ConnectionInterface|null $con
     * @param bool $deep Go deep.
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return int
     */
    public function save(?ConnectionInterface $con = null, bool $deep = false): int
    {
PHP,
                <<<'PHP'
    /**
     * Saves the object.
     */
    public function save(?ConnectionInterface $con = null, bool $deep = false): int
    {
PHP,
            ],
            'docblock without anything left is removed' => [
                <<<'PHP'
    /**
     * @param string $name
     *
     * @return void
     */
    public function setName(string $name): void
PHP,
                <<<'PHP'
    public function setName(string $name): void
PHP,
            ],
            'tags with more information than the signature are kept' => [
                <<<'PHP'
    /**
     * @param array<string> $names
     * @param \Propel\Runtime\Collection\ObjectCollection<\Foo\Bar> $collection
     * @param string|int $key
     * @param mixed $value
     *
     * @return $this
     */
    public function configure(array $names, ObjectCollection $collection, $key, $value)
PHP,
                <<<'PHP'
    /**
     * @param array<string> $names
     * @param \Propel\Runtime\Collection\ObjectCollection<\Foo\Bar> $collection
     * @param string|int $key
     * @param mixed $value
     *
     * @return $this
     */
    public function configure(array $names, ObjectCollection $collection, $key, $value)
PHP,
            ],
            'fluent return with native static' => [
                <<<'PHP'
    /**
     * Sets the title.
     *
     * @param string|null $v New value
     *
     * @return $this
     */
    public function setTitle($v): static
PHP,
                <<<'PHP'
    /**
     * Sets the title.
     *
     * @param string|null $v New value
     */
    public function setTitle($v): static
PHP,
            ],
            'multi line signature and attribute' => [
                <<<'PHP'
    /**
     * @param string|null $relationAlias
     * @param string $joinType
     *
     * @return \Foo\BarQuery<static>
     */
    #[\Override]
    public function useBarQuery(
        ?string $relationAlias = null,
        string $joinType = Criteria::INNER_JOIN
    ) {
PHP,
                <<<'PHP'
    /**
     * @return \Foo\BarQuery<static>
     */
    #[\Override]
    public function useBarQuery(
        ?string $relationAlias = null,
        string $joinType = Criteria::INNER_JOIN
    ) {
PHP,
            ],
            'property with native type' => [
                <<<'PHP'
    /**
     * Value of [id] field.
     *
     * @var int|null
     */
    protected int|null $id = null;

    /**
     * @var array<string, bool>
     */
    protected array $modifiedColumns = [];

    /**
     * @var \Foo\Bar|null
     */
    protected $untyped;
PHP,
                <<<'PHP'
    /**
     * Value of [id] field.
     */
    protected int|null $id = null;

    /**
     * @var array<string, bool>
     */
    protected array $modifiedColumns = [];

    /**
     * @var \Foo\Bar|null
     */
    protected $untyped;
PHP,
            ],
            'class docblock and code are left alone' => [
                <<<'PHP'
/**
 * @method $this orderById($order = Criteria::ASC) Order by the id column
 *
 * @throws \Exception
 */
abstract class Foo
{
    public const X = '/** not a docblock */';
}
PHP,
                <<<'PHP'
/**
 * @method $this orderById($order = Criteria::ASC) Order by the id column
 *
 * @throws \Exception
 */
abstract class Foo
{
    public const X = '/** not a docblock */';
}
PHP,
            ],
        ];
    }

    /**
     * @param string $code
     * @param string $expected
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('codeProvider')]
    public function testCompact(string $code, string $expected): void
    {
        $this->assertSame($expected, DocblockCompactor::compact($code));
    }

    /**
     * @return void
     */
    public function testCompactedCodeIsStable(): void
    {
        foreach (static::codeProvider() as [, $expected]) {
            $this->assertSame($expected, DocblockCompactor::compact($expected));
        }
    }
}
