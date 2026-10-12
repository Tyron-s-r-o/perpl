<?php

declare(strict_types = 1);

namespace Propel\Runtime\ActiveQuery\FilterExpression;

class LikeColumnFilter extends ColumnFilter
{
    /**
     * @param array $paramCollector A list to which Prepared Statement parameters will be appended
     *
     * @return string
     */
    #[\Override]
    protected function buildFilterClause(array &$paramCollector): string
    {
        $field = $this->getLocalColumnName(true);
        $bindParam = $this->addParameter($paramCollector);

        if ($this->isIgnoreCase()) {
            // If selection is case insensitive use SQL UPPER() function on column name and param.

            /** @var \Propel\Runtime\Adapter\SqlAdapterInterface $adapter */
            $adapter = $this->query->getAdapter();
            $bindParam = $adapter->ignoreCase($bindParam);
            $field = $adapter->ignoreCase($field);
        }

        return "$field{$this->operator}$bindParam";
    }
}
