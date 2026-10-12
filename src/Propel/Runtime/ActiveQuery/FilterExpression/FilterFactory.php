<?php

declare(strict_types = 1);

namespace Propel\Runtime\ActiveQuery\FilterExpression;

use LogicException;
use Propel\Runtime\ActiveQuery\ColumnResolver\ColumnExpression\AbstractColumnExpression;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\ActiveQuery\Criterion\Exception\InvalidClauseException;
use function is_int;
use function is_string;
use function print_r;

class FilterFactory
{
    /**
     * @param \Propel\Runtime\ActiveQuery\Criteria $query
     * @param \Propel\Runtime\ActiveQuery\ColumnResolver\ColumnExpression\AbstractColumnExpression|string|null $columnOrClause
     * @param string|int|null $comparison
     * @param mixed $value
     *
     * @throws \LogicException
     * @throws \Propel\Runtime\ActiveQuery\Criterion\Exception\InvalidClauseException
     *
     * @return \Propel\Runtime\ActiveQuery\FilterExpression\ColumnFilterInterface
     */
    public static function build(Criteria $query, $columnOrClause, $comparison = null, $value = null): ColumnFilterInterface
    {
        if (is_int($comparison)) {
            // $comparison is a PDO::PARAM_* constant value
            // something like $c->add('foo like ?', '%bar%', PDO::PARAM_STR);

            if ($columnOrClause === null) {
                throw new InvalidClauseException('Empty clause in column filter - Passing PDO type to Criteria::where()/Criteria::and() requires a non-empty clause.');
            } elseif ($columnOrClause instanceof AbstractColumnExpression) {
                throw new LogicException('Column of clause with PDO types was resolved, but should have remained a string. Resolved column: ' . print_r($columnOrClause, true));
            }

            return new FilterClauseLiteralWithPdoTypes($query, $columnOrClause, $value, $comparison);
        }

        if ($value instanceof Criteria) {
            // $value is a subquery
            // something like $c->add(null, BookClause::create()->filterByAuthorId(42), Criteria::EXISTS);
            if (is_string($columnOrClause)) {
                throw new LogicException("Cannot use unresolved column name as input for subquery filter ($comparison)");
            }

            return static::buildSubqueryFilter($query, $columnOrClause, $comparison, $value);
        }

        if ($comparison === Criteria::CUSTOM) {
            // custom expression with no parameter binding
            // something like $c->add(BookTableMap::TITLE, "CONCAT(book.TITLE, 'bar') = 'foobar'", Criteria::CUSTOM);
            return new FilterClauseLiteralWithColumns($query, $value);
        }

        if (is_string($columnOrClause)) {
            return new FilterClauseLiteralWithColumns($query, $columnOrClause);
        }

        $comparison ??= Criteria::EQUAL;

        return static::buildFilterCondition($query, $columnOrClause, $comparison, $value);
    }

    /**
     * @param \Propel\Runtime\ActiveQuery\Criteria $query
     * @param \Propel\Runtime\ActiveQuery\ColumnResolver\ColumnExpression\AbstractColumnExpression|null $column
     * @param string|null $comparison
     * @param mixed $value
     *
     * @return \Propel\Runtime\ActiveQuery\FilterExpression\AbstractFilter
     */
    protected static function buildFilterCondition(Criteria $query, ?AbstractColumnExpression $column, $comparison = null, $value = null): AbstractFilter
    {
        return match ($comparison) {
            // table.column IN (?, ?) or table.column NOT IN (?, ?)
            // something like $c->add(BookTableMap::TITLE, array('foo', 'bar'), Criteria::IN);
            Criteria::IN, Criteria::NOT_IN => new InColumnFilter($query, $column, $comparison, $value),
            // table.column LIKE ? or table.column NOT LIKE ?
            // something like $c->add(BookTableMap::TITLE, 'foo%', Criteria::LIKE);
            Criteria::LIKE, Criteria::NOT_LIKE, Criteria::ILIKE, Criteria::NOT_ILIKE => new LikeColumnFilter($query, $column, $comparison, $value),
            // table.column & ? = 0 (Similar to  "NOT IN")
            // something like $c->add(BookTableMap::SOME_ARRAY_VAR, 26, Criteria::BINARY_NONE);
            Criteria::BINARY_NONE, Criteria::BINARY_ALL => new BinaryColumnFilter($query, $column, $comparison, $value),
            // simple comparison
            // something like $c->add(BookTableMap::PRICE, 12, Criteria::GREATER_THAN);
            default => new ColumnFilter($query, $column, $comparison, $value),
        };
    }

    /**
     * @param \Propel\Runtime\ActiveQuery\Criteria $outerQuery
     * @param \Propel\Runtime\ActiveQuery\ColumnResolver\ColumnExpression\AbstractColumnExpression|null $column
     * @param string|null $comparison
     * @param \Propel\Runtime\ActiveQuery\Criteria $innerQuery
     *
     * @return \Propel\Runtime\ActiveQuery\FilterExpression\AbstractFilter
     */
    protected static function buildSubqueryFilter(
        Criteria $outerQuery,
        $column,
        ?string $comparison,
        Criteria $innerQuery
    ): AbstractFilter {
        $comparison ??= Criteria::IN;

        return match ($comparison) {
            ExistsFilter::TYPE_EXISTS, ExistsFilter::TYPE_NOT_EXISTS => new ExistsFilter($outerQuery, null, $comparison, $innerQuery),
            default => new ColumnToQueryFilter($outerQuery, $column, $comparison, $innerQuery),
        };
    }
}
