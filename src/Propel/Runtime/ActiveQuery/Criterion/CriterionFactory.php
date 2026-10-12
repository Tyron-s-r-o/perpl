<?php

declare(strict_types = 1);

namespace Propel\Runtime\ActiveQuery\Criterion;

use Propel\Runtime\ActiveQuery\Criteria;
use function is_int;

/**
 * Creates Criterion objects, extracted from Criteria class
 */
class CriterionFactory
{
    /**
     * @param \Propel\Runtime\ActiveQuery\Criteria $criteria
     * @param string $column
     * @param mixed $comparison
     * @param mixed $value
     *
     * @return \Propel\Runtime\ActiveQuery\Criterion\AbstractCriterion
     */
    public static function build(Criteria $criteria, string $column, $comparison = null, $value = null): AbstractCriterion
    {
        if ($value instanceof Criteria) {
            return static::buildCriterionWithCriteria($criteria, $column, $comparison, $value);
        } elseif ($comparison === null) {
            $comparison = Criteria::EQUAL;
        }

        if (is_int($comparison)) {
            // $comparison is a PDO::PARAM_* constant value
            // something like $c->add('foo like ?', '%bar%', PDO::PARAM_STR);
            return new RawCriterion($criteria, $column, $value, $comparison);
        }

        return match ($comparison) {
            // custom expression with no parameter binding
            // something like $c->add(BookTableMap::TITLE, "CONCAT(book.TITLE, 'bar') = 'foobar'", Criteria::CUSTOM);
            Criteria::CUSTOM => new CustomCriterion($criteria, $value),
            // table.column IN (?, ?) or table.column NOT IN (?, ?)
            // something like $c->add(BookTableMap::TITLE, array('foo', 'bar'), Criteria::IN);
            Criteria::IN, Criteria::NOT_IN => new InCriterion($criteria, $column, $value, $comparison),
            // table.column LIKE ? or table.column NOT LIKE ?
            // something like $c->add(BookTableMap::TITLE, 'foo%', Criteria::LIKE);
            Criteria::LIKE, Criteria::NOT_LIKE, Criteria::ILIKE, Criteria::NOT_ILIKE => new LikeCriterion($criteria, $column, $value, $comparison),
            // table.column & ? = 0 (Similar to  "NOT IN")
            // something like $c->add(BookTableMap::SOME_ARRAY_VAR, 26, Criteria::BINARY_NONE);
            Criteria::BINARY_NONE, Criteria::BINARY_ALL => new BinaryCriterion($criteria, $column, $value, $comparison),
            // simple comparison
            // something like $c->add(BookTableMap::PRICE, 12, Criteria::GREATER_THAN);
            default => new BasicCriterion($criteria, $column, $value, $comparison),
        };
    }

    /**
     * @param \Propel\Runtime\ActiveQuery\Criteria $criteria
     * @param string $column
     * @param string|null $comparison
     * @param \Propel\Runtime\ActiveQuery\Criteria $innerQuery
     *
     * @return \Propel\Runtime\ActiveQuery\Criterion\AbstractInnerQueryCriterion
     */
    protected static function buildCriterionWithCriteria(
        Criteria $criteria,
        string $column,
        ?string $comparison,
        Criteria $innerQuery
    ): AbstractInnerQueryCriterion {
        $comparison ??= Criteria::IN;

        return match ($comparison) {
            ExistsQueryCriterion::TYPE_EXISTS, ExistsQueryCriterion::TYPE_NOT_EXISTS => new ExistsQueryCriterion($criteria, null, $comparison, $innerQuery),
            default => new ColumnToQueryOperatorCriterion($criteria, $column, $comparison, $innerQuery),
        };
    }
}
