<?php

declare(strict_types = 1);

namespace Propel\Runtime\ActiveQuery\Criterion;

use Propel\Runtime\ActiveQuery\Criterion\Exception\InvalidClauseException;

/**
 * Specialized ModelCriterion used for LIKE expressions
 * e.g. table.column LIKE ? or table.column NOT LIKE ?
 */
class LikeModelCriterion extends BasicModelCriterion
{
    /**
     * @var bool
     */
    protected $ignoreStringCase = false;

    /**
     * Sets ignore case.
     *
     * @param bool $b True if case should be ignored.
     *
     * @return $this A modified Criterion object.
     */
    public function setIgnoreCase(bool $b)
    {
        $this->ignoreStringCase = $b;

        return $this;
    }

    /**
     * Is ignore case on or off?
     *
     * @return bool True if case is ignored.
     */
    public function isIgnoreCase(): bool
    {
        return $this->ignoreStringCase;
    }

    /**
     * Appends a Prepared Statement representation of the ModelCriterion onto the buffer
     * Handles case insensitivity for VARCHAR columns
     *
     * @param string $sb The string that will receive the Prepared Statement
     * @param array $params A list to which Prepared Statement parameters will be appended
     *
     * @throws \Propel\Runtime\ActiveQuery\Criterion\Exception\InvalidClauseException
     *
     * @return void
     */
    #[\Override]
    protected function appendPsForUniqueClauseTo(string &$sb, array &$params): void
    {
        // no ILIKE available on MySQL and SQLite, so case insensitive LIKE is not supported
        if ($this->ignoreStringCase) {
            throw new InvalidClauseException('Case insensitive LIKE is only supported in PostreSQL');
        }
        parent::appendPsForUniqueClauseTo($sb, $params);
    }
}
