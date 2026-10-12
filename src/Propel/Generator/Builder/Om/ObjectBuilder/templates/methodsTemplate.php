
    /**
     * Returns whether the object has been modified.
     *
     * @return bool True if the object has been modified.
     */
    public function isModified(): bool
    {
        return (bool)$this->modifiedColumns;
    }

    /**
     * Has specified column been modified?
     *
     * @param string $col column fully qualified name (TableMap::TYPE_COLNAME), e.g. Book::AUTHOR_ID
     *
     * @return bool True if $col has been modified.
     */
    public function isColumnModified(string $col): bool
    {
        return $this->modifiedColumns && isset($this->modifiedColumns[$col]);
    }

    /**
     * Get the columns that have been modified in this object.
     *
     * @return array<string> A unique list of the modified column names for this object.
     */
    public function getModifiedColumns(): array
    {
        return $this->modifiedColumns ? array_keys($this->modifiedColumns) : [];
    }

    /**
     * Returns whether the object has ever been saved. This will
     * be false, if the object was retrieved from storage or was created
     * and then saved.
     *
     * @return bool True, if the object has never been persisted.
     */
    public function isNew(): bool
    {
        return $this->new;
    }

    /**
     * Setter for the isNew attribute.
     *
     * Called by Propel-generated children and objects.
     *
     * @param bool $b the state of the object.
     *
     * @return void
     */
    public function setNew(bool $b): void
    {
        $this->new = $b;
    }

    /**
     * Whether this object has been deleted.
     *
     * @return bool The deleted state of this object.
     */
    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    /**
     * Specify whether this object has been deleted.
     *
     * @param bool $b The deleted state of this object.
     *
     * @return void
     */
    public function setDeleted(bool $b): void
    {
        $this->deleted = $b;
    }

    /**
     * Sets the modified state for the object to be false.
     *
     * @param string|null $col If supplied, only the specified column is reset.
     *
     * @return void
     */
    public function resetModified(?string $col = null): void
    {
        if ($col !== null) {
            unset($this->modifiedColumns[$col]);
        } else {
            $this->modifiedColumns = [];
        }
    }

    /**
     * Compares this with another <code><?= $className ?></code> instance. If
     * <code>obj</code> is an instance of <code><?= $className ?></code>, delegates to
     * <code>equals(<?= $className ?>)</code>. Otherwise, returns <code>false</code>.
     *
     * @param mixed $obj The object to compare to.
     *
     * @return bool Whether equal to the object specified.
     */
    public function equals($obj): bool
    {
        if (!$obj instanceof static) {
            return false;
        }

        if ($this === $obj) {
            return true;
        }
<?php if (!$hasArrayKey): ?>
        if ($this->getPrimaryKey() === null || $obj->getPrimaryKey() === null) {
<?php else: ?>
        $checkNull = fn (mixed $v): bool => $v === null;
        $allNull = fn (array $array): bool => array_all($array, $checkNull);
        if ($allNull($this->getPrimaryKey()) || $allNull($obj->getPrimaryKey())) {
<?php endif; ?>
            return false;
        }

        return $this->getPrimaryKey() === $obj->getPrimaryKey();
    }

    /**
     * Get the associative array of the virtual columns in this object
     *
     * @return array<mixed>
     */
    public function getVirtualColumns(): array
    {
        return $this->virtualColumns;
    }

    /**
     * Checks the existence of a virtual column in this object
     *
     * @param string $name The virtual column name
     *
     * @return bool
     */
    public function hasVirtualColumn(string $name): bool
    {
        return array_key_exists($name, $this->virtualColumns);
    }

    /**
     * Get the value of a virtual column in this object
     *
     * @param string $name The virtual column name
     *
     * @throws \Propel\Runtime\Exception\PropelException
     *
     * @return mixed
     */
    public function getVirtualColumn(string $name)
    {
        if (!$this->hasVirtualColumn($name)) {
            throw new PropelException("Cannot get value of nonexistent virtual column `$name`");
        }

        return $this->virtualColumns[$name];
    }

    /**
     * Set the value of a virtual column in this object
     *
     * @param string $name The virtual column name
     * @param mixed $value The value to give to the virtual column
     *
     * @return $this
     */
    public function setVirtualColumn(string $name, $value)
    {
        $this->virtualColumns[$name] = $value;

        return $this;
    }

    /**
     * Logs a message using Perpl::log().
     *
     * @param string $msg
     * @param int $priority One of the Perpl::LOG_* logging levels
     *
     * @return void
     */
    protected function log(string $msg, int $priority = Perpl::LOG_INFO): void
    {
        Perpl::log(static::class . ': ' . $msg, $priority);
    }

    /**
     * Export the current object properties to a string, using a given parser format
     * <code>
     * $book = BookQuery::create()->findPk(9012);
     * echo $book->exportTo('JSON');
     *  => {"Id":9012,"Title":"Don Juan","ISBN":"0140422161","Price":12.99,"PublisherId":1234,"AuthorId":5678}');
     * </code>
     *
     * @param \Propel\Runtime\Parser\AbstractParser|string $parser An AbstractParser instance, or a format name ('JSON')
     * @param bool $includeLazyLoadColumns (optional) Whether to include lazy load(ed) columns. Defaults to TRUE.
     * @param string $keyType (optional) One of the class type constants TableMap::TYPE_PHPNAME, TableMap::TYPE_CAMELNAME, TableMap::TYPE_COLNAME, TableMap::TYPE_FIELDNAME, TableMap::TYPE_NUM. Defaults to TableMap::TYPE_PHPNAME.
     * 
     * @return string The exported data
     */
    public function exportTo($parser, bool $includeLazyLoadColumns = true, string $keyType = TableMap::TYPE_PHPNAME): string
    {
        if (!$parser instanceof AbstractParser) {
            $parser = AbstractParser::getParser($parser);
        }

<?php if ($hasFks): ?>
        return $parser->fromArray($this->toArray($keyType, $includeLazyLoadColumns, [], true));
<?php else: ?>
        return $parser->fromArray($this->toArray($keyType, $includeLazyLoadColumns));
<?php endif?>
    }

    /**
     * Properties holding related objects and internal state, which are not serialized.
     * Keys are property names as found in an array cast of the object.
     *
     * @var array<string, true>
     */
    private const TRANSIENT_PROPERTIES = [/*%TRANSIENT_PROPERTIES%*/];

    /**
     * Names of the serialized properties declared in this class, by their key in an array cast of the object.
     *
     * @var array<string, string>
     */
    private const SERIALIZED_PROPERTIES = [/*%SERIALIZED_PROPERTIES%*/];

    /**
     * Returns column values and state of the object, without related objects.
     * The object itself is not changed.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
<?php if ($hasParentClass): ?>
        return array_diff_key((array)$this, $this->getTransientProperties());
<?php else: ?>
        return array_diff_key((array)$this, self::TRANSIENT_PROPERTIES);
<?php endif?>
    }

    /**
     * Restores the object from the output of __serialize(). Also reads objects
     * serialized with all their properties, related objects in them are ignored.
     *
     * @param array<string, mixed> $data
     *
     * @return void
     */
    public function __unserialize(array $data): void
    {
<?php if ($hasParentClass): ?>
        $transientProperties = $this->getTransientProperties();
<?php endif?>
        foreach ($data as $key => $value) {
            $propertyName = self::SERIALIZED_PROPERTIES[$key] ?? null;
            if ($propertyName !== null) {
                $this->$propertyName = $value;

                continue;
            }
<?php if ($hasParentClass): ?>
            if (isset($transientProperties[$key])) {
<?php else: ?>
            if (isset(self::TRANSIENT_PROPERTIES[$key])) {
<?php endif?>
                continue;
            }
            // property of a subclass or a property this class no longer has
            $key = (string)$key;
            if ($key === '' || $key[0] !== "\0") {
                $this->$key = $value;
            } elseif ($key[1] === '*') {
                $propertyName = substr($key, 3);
                // other protected properties are from previous versions of the class, like state of relations
                if (property_exists($this, $propertyName)) {
                    $this->$propertyName = $value;
                }
            } else {
                $separatorPosition = (int)strpos($key, "\0", 1);
                $propertyName = substr($key, $separatorPosition + 1);
                $declaringClass = substr($key, 1, $separatorPosition - 1);
                if ($declaringClass === self::class) {
                    $this->$propertyName = $value;
                } elseif (class_exists($declaringClass) && property_exists($declaringClass, $propertyName)) {
                    (function () use ($propertyName, $value): void {
                        $this->$propertyName = $value;
                    })->bindTo($this, $declaringClass)();
                }
            }
        }
    }
<?php if ($hasParentClass): ?>

    /**
     * @return array<string, true>
     */
    protected function getTransientProperties(): array
    {
        $parentClass = get_parent_class(self::class);
        if ($parentClass && method_exists($parentClass, 'getTransientProperties')) {
            return self::TRANSIENT_PROPERTIES + parent::getTransientProperties();
        }

        return self::TRANSIENT_PROPERTIES;
    }
<?php endif?>
