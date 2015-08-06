<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Database\Entities\Schemas;

use Psr\Log\LoggerAwareInterface;
use Spiral\Database\Entities\Driver;
use Spiral\Database\Exceptions\SchemaException;
use Spiral\Database\Schemas\TableInterface;
use Spiral\Debug\Traits\LoggerTrait;

/**
 * Abstract table schema with read (see TableInterface) and write abilities. Must be implemented
 * by driver to support DBMS specific syntax and creation rules.
 *
 * Most of table operation like column, index or foreign key creation/altering will be applied when save() method will be
 * called.
 *
 * Column configuration shortcuts:
 * @method AbstractColumn primary($column)
 * @method AbstractColumn bigPrimary($column)
 * @method AbstractColumn enum($column, array $values)
 * @method AbstractColumn string($column, $length = 255)
 * @method AbstractColumn decimal($column, $precision, $scale)
 * @method AbstractColumn boolean($column)
 * @method AbstractColumn integer($column)
 * @method AbstractColumn tinyInteger($column)
 * @method AbstractColumn bigInteger($column)
 * @method AbstractColumn text($column)
 * @method AbstractColumn tinyText($column)
 * @method AbstractColumn longText($column)
 * @method AbstractColumn double($column)
 * @method AbstractColumn float($column)
 * @method AbstractColumn datetime($column)
 * @method AbstractColumn date($column)
 * @method AbstractColumn time($column)
 * @method AbstractColumn timestamp($column)
 * @method AbstractColumn binary($column)
 * @method AbstractColumn tinyBinary($column)
 * @method AbstractColumn longBinary($column)
 */
abstract class AbstractTable implements TableInterface
{
    /**
     * AbstractTable will raise few warning and debug messages to console.
     */
    use LoggerTrait;

    /**
     * Rename SQL statement is usually the same... usually.
     */
    const RENAME_STATEMENT = "ALTER TABLE {table} RENAME TO {name}";

    /**
     * Indication that table is exists and current schema is fetched from database.
     *
     * @var bool
     */
    protected $exists = false;

    /**
     * Table name including table prefix.
     *
     * @var string|AbstractColumn
     */
    protected $name = '';

    /**
     * Database specific tablePrefix. Required for table renames.
     *
     * @var string
     */
    protected $tablePrefix = '';

    /**
     * Primary key columns are stored separately from other indexes and can be modified only during
     * table creation.
     *
     * @var array
     */
    protected $primaryKeys = [];

    /**
     * Primary keys fetched from database.
     *
     * @invisible
     * @var array
     */
    protected $dbPrimaryKeys = [];

    /**
     * Column schemas fetched from db or created by user.
     *
     * @var AbstractColumn[]
     */
    protected $columns = [];

    /**
     * Column schemas fetched from db. Synced with $columns in table save() method.
     *
     * @invisible
     * @var AbstractColumn[]
     */
    protected $dbColumns = [];

    /**
     * Index schemas fetched from db or created by user.
     *
     * @var AbstractIndex[]
     */
    protected $indexes = [];

    /**
     * Index schemas fetched from db. Synced with $indexes in table save() method.
     *
     * @invisible
     * @var AbstractIndex[]
     */
    protected $dbIndexes = [];

    /**
     * Foreign key schemas fetched from db or created by user.
     *
     * @var AbstractReference[]
     */
    protected $references = [];

    /**
     * Foreign key schemas fetched from db. Synced with $references in table save() method.
     *
     * @invisible
     * @var AbstractReference[]
     */
    protected $dbReferences = [];

    /**
     * @invisible
     * @var Driver
     */
    protected $driver = null;

    /**
     * @param string $name        Table name, must include table prefix.
     * @param string $tablePrefix Database specific table prefix.
     * @param Driver $driver
     */
    public function __construct($name, $tablePrefix, Driver $driver)
    {
        $this->name = $name;
        $this->tablePrefix = $tablePrefix;
        $this->driver = $driver;

        if (!$this->driver->hasTable($this->name)) {
            return;
        }

        //Loading table information
        $this->loadColumns();
        $this->loadIndexes();
        $this->loadReferences();

        $this->exists = true;
    }

    /**
     * @return Driver
     */
    public function driver()
    {
        return $this->driver;
    }

    /**
     * {@inheritdoc}
     */
    public function exists()
    {
        return $this->exists;
    }

    /**
     * {@inheritdoc}
     *
     * @param bool $quoted Quote name.
     */
    public function getName($quoted = false)
    {
        return $quoted ? $this->driver->identifier($this->name) : $this->name;
    }

    /**
     * {@inheritdoc}
     */
    public function getPrimaryKeys()
    {
        return $this->primaryKeys;
    }

    /**
     * {@inheritdoc}
     */
    public function hasColumn($name)
    {
        return isset($this->columns[$name]);
    }

    /**
     * {@inheritdoc}
     *
     * @return AbstractColumn[]
     */
    public function getColumns()
    {
        return $this->columns;
    }

    /**
     * {@inheritdoc}
     */
    public function hasIndex(array $columns = [])
    {
        return !empty($this->findIndex(is_array($columns) ? $columns : func_get_args()));
    }

    /**
     * {@inheritdoc}
     *
     * @return AbstractIndex[]
     */
    public function getIndexes()
    {
        return $this->indexes;
    }

    /**
     * {@inheritdoc}
     */
    public function hasForeign($column)
    {
        return !empty($this->findForeign($column));
    }

    /**
     * {@inheritdoc}
     *
     * @return AbstractReference[]
     */
    public function getForeigns()
    {
        return $this->references;
    }

    /**
     * {@inheritdoc}
     */
    public function getDependencies()
    {
        $tables = [];

        foreach ($this->getForeigns() as $foreign) {
            $tables[] = $foreign->getForeignTable();
        }

        return $tables;
    }

    /**
     * Set table primary keys. Operation can be applied for newly created tables. Now every database might support
     * compound indexes.
     *
     * @param array|mixed $columns Array or comma separated set of column names.
     * @return $this
     * @throws SchemaException
     */
    public function setPrimaryKeys($columns)
    {
        if ($this->exists()) {
            throw new SchemaException("Unable to change primary keys for already exists table.");
        }

        $this->primaryKeys = is_array($columns) ? $columns : func_get_args();

        return $this;
    }

    /**
     * Get/create instance of AbstractColumn associated with current table based on column name.
     *
     * Examples:
     * $table->column('name')->string();
     *
     * @param string $column Column name.
     * @return AbstractColumn
     */
    public function column($column)
    {
        if (!isset($this->columns[$column])) {
            $this->columns[$column] = $this->driver->columnSchema($this, $column);
        }

        $result = $this->columns[$column];
        if ($result instanceof LoggerAwareInterface) {
            $result->setLogger($this->logger());
        }

        return $this->columns[$column];
    }

    /**
     * Get/create instance of AbstractIndex associated with current table based on list of forming column names.
     *
     * Example:
     * $table->index('key');
     * $table->index('key', 'key2');
     * $table->index(['key', 'key2']);
     *
     * @param mixed $columns Column name, or array of columns.
     * @return AbstractIndex
     */
    public function index($columns)
    {
        $columns = is_array($columns) ? $columns : func_get_args();
        if (!empty($index = $this->findIndex($columns))) {
            return $index;
        }

        //New index
        $index = $this->driver->indexSchema($this, false);
        $index->columns($columns)->unique(false);

        //Adding to declared schema
        $this->indexes[$index->getName()] = $index;
        if ($index instanceof LoggerAwareInterface) {
            $index->setLogger($this->logger());
        }

        return $index;
    }

    /**
     * Get/create instance of AbstractIndex associated with current table based on list of forming column names. Index
     * type must be forced as UNIQUE.
     *
     * Example:
     * $table->unique('key');
     * $table->unique('key', 'key2');
     * $table->unique(['key', 'key2']);
     *
     * @param mixed $columns Column name, or array of columns.
     * @return AbstractColumn|null
     */
    public function unique($columns)
    {
        $columns = is_array($columns) ? $columns : func_get_args();

        return $this->index($columns)->unique();
    }

    /**
     * Get/create instance of AbstractReference associated with current table based on local column name.
     *
     * @param string $column Column name.
     * @return AbstractReference|null
     */
    public function foreign($column)
    {
        if (!empty($foreign = $this->findForeign($column))) {
            return $foreign;
        }

        //Create foreign key
        $foreign = $this->driver->referenceSchema($this, false);
        $foreign->column($column);

        //Adding to declared schema
        $this->references[$foreign->getName()] = $foreign;

        if ($foreign instanceof LoggerAwareInterface) {
            $foreign->setLogger($this->logger());
        }

        return $foreign;
    }

    /**
     * Rename existed column or change name of scheduled column schema. This operation can be safe to use on recurring
     * basis as rename will be skipped if target column does not exists or already named so.
     *
     * @param string $column
     * @param string $name New column name.
     * @return $this
     */
    public function renameColumn($column, $name)
    {
        foreach ($this->columns as $columnSchema) {
            if ($columnSchema->getName() == $column) {
                $columnSchema->name($name);
                break;
            }
        }

        return $this;
    }

    /**
     * Drop column from table schema.
     *
     * @param string $column
     * @return $this
     */
    public function dropColumn($column)
    {
        foreach ($this->columns as $id => $columnSchema) {
            if ($columnSchema->getName() == $column) {
                unset($this->columns[$id]);
                break;
            }
        }

        return $this;
    }

    /**
     * Rename existed index or change name of scheduled index schema. Index name must be used. This operation can be
     * safe to use on recurring basis as rename will be skipped if target index does not exists or already named so.
     *
     * @param string $index
     * @param string $name New index name.
     * @return $this
     */
    public function renameIndex($index, $name)
    {
        foreach ($this->indexes as $indexSchema) {
            if ($indexSchema->getName() == $index) {
                $indexSchema->name($name);
                break;
            }
        }

        return $this;
    }

    /**
     * Drop index from table schema using it's name.
     *
     * @param string $index
     * @return $this
     */
    public function dropIndex($index)
    {
        foreach ($this->indexes as $id => $indexSchema) {
            if ($indexSchema->getName() == $index) {
                unset($this->indexes[$id]);
                break;
            }
        }

        return $this;
    }

    /**
     * Drop foreign key from table schema using it's name.
     *
     * @param string $foreign
     * @return $this
     */
    public function dropForeign($foreign)
    {
        foreach ($this->references as $id => $foreignSchema) {
            if ($foreignSchema->getName() == $foreign) {
                unset($this->references[$id]);
                break;
            }
        }

        return $this;
    }

    /**
     * Check if table schema were altered and must be synced.
     *
     * @return bool
     */
    public function hasChanges()
    {
        return (
            !empty($this->alteredColumns())
            || !empty($this->alteredIndexes())
            || !empty($this->alteredReferences())
        ) || $this->primaryKeys != $this->dbPrimaryKeys;
    }

    /**
     * List of altered column schemas.
     *
     * @return array|AbstractColumn[]
     */
    public function alteredColumns()
    {
        $altered = [];
        foreach ($this->columns as $column => $schema) {
            if (!isset($this->dbColumns[$column])) {
                $altered[$column] = $schema;
                continue;
            }

            if (!$schema->compare($this->dbColumns[$column])) {
                $altered[$column] = $schema;
            }
        }

        foreach ($this->dbColumns as $column => $schema) {
            if (!isset($this->columns[$column])) {
                //Going to be dropped
                $altered[$column] = null;
            }
        }

        return $altered;
    }

    /**
     * List of altered index schemas.
     *
     * @return array|AbstractIndex[]
     */
    public function alteredIndexes()
    {
        $altered = [];
        foreach ($this->indexes as $index => $schema) {
            if (!isset($this->dbIndexes[$index])) {
                $altered[$index] = $schema;
                continue;
            }

            if (!$schema->compare($this->dbIndexes[$index])) {
                $altered[$index] = $schema;
            }
        }

        foreach ($this->dbIndexes as $index => $schema) {
            if (!isset($this->indexes[$index])) {
                //Going to be dropped
                $altered[$index] = null;
            }
        }

        return $altered;
    }

    /**
     * List of altered foreign key schemas.
     *
     * @return array|AbstractReference[]
     */
    public function alteredReferences()
    {
        $altered = [];
        foreach ($this->references as $constraint => $schema) {
            if (!isset($this->dbReferences[$constraint])) {
                $altered[$constraint] = $schema;
                continue;
            }

            if (!$schema->compare($this->dbReferences[$constraint])) {
                $altered[$constraint] = $schema;
            }
        }

        foreach ($this->dbReferences as $constraint => $schema) {
            if (!isset($this->references[$constraint])) {
                //Going to be dropped
                $altered[$constraint] = null;
            }
        }

        return $altered;
    }

    /**
     * Rename table. Operation must be applied immediately.
     *
     * @param string $name New table name without prefix. Database prefix will be used.
     */
    public function rename($name)
    {
        if ($this->exists()) {
            $this->driver->statement(\Spiral\interpolate(static::RENAME_STATEMENT, [
                'table' => $this->getName(true),
                'name'  => $this->driver->identifier($this->tablePrefix . $name)
            ]));
        }

        $this->name = $this->tablePrefix . $name;
    }

    /**
     * Drop table schema in database. This operation must be applied immediately.
     */
    public function drop()
    {
        if (!$this->exists()) {
            $this->columns = $this->dbColumns = $this->primaryKeys = $this->dbPrimaryKeys = [];
            $this->indexes = $this->dbIndexes = $this->references = $this->dbReferences = [];

            return;
        }

        //Dropping syntax is the same everywhere, for now...
        $this->driver->statement(\Spiral\interpolate("DROP TABLE {table}", [
            'table' => $this->getName(true)
        ]));

        $this->exists = false;
        $this->columns = $this->dbColumns = $this->primaryKeys = $this->dbPrimaryKeys = [];
        $this->indexes = $this->dbIndexes = $this->references = $this->dbReferences = [];
    }

    /**
     * Save table schema including every column, index, foreign key creation/altering. If table does not exist it must
     * be created.
     */
    public function save()
    {
        if (!$this->exists()) {
            $this->createSchema(true);
        } else {
            $this->hasChanges() && $this->updateSchema();
        }

        //Refreshing schema
        $this->exists = true;
        $this->dbPrimaryKeys = $this->primaryKeys;

        $columns = $this->columns;
        $indexes = $this->indexes;
        $references = $this->references;

        //Syncing schema lists
        $this->columns = $this->dbColumns = [];
        foreach ($columns as $column) {
            $this->columns[$column->getName()] = $column;
            $this->dbColumns[$column->getName()] = clone $column;
        }

        $this->indexes = $this->dbIndexes = [];
        foreach ($indexes as $index) {
            $this->indexes[$index->getName()] = $index;
            $this->dbIndexes[$index->getName()] = clone $index;
        }

        $this->references = $this->dbReferences = [];
        foreach ($references as $reference) {
            $this->references[$reference->getName()] = $reference;
            $this->dbReferences[$reference->getName()] = clone $reference;
        }
    }

    /**
     * Shortcut for column() method.
     *
     * @param string $column
     * @return AbstractColumn
     */
    public function __get($column)
    {
        return $this->column($column);
    }

    /**
     * Column creation/altering shortcut, call chain is identical to: AbstractTable->column($name)->$type($arguments)
     *
     * Example:
     * $table->string("name");
     * $table->text("some_column");
     *
     * @param string $type
     * @param array  $arguments Type specific parameters.
     * @return AbstractColumn
     */
    public function __call($type, array $arguments)
    {
        return call_user_func_array([$this->column($arguments[0]), $type], array_slice($arguments, 1));
    }

    /**
     * Driver specific columns information load. Columns must be added using registerColumn method
     *
     * @see registerColumn()
     */
    abstract protected function loadColumns();

    /**
     * Register column schema supplied by database.
     *
     * @param string $name   Column name.
     * @param mixed  $schema Driver specific column information schema.
     * @return AbstractColumn
     */
    protected function registerColumn($name, $schema)
    {
        $column = $this->driver->columnSchema($this, $name, $schema);
        $this->dbColumns[$name] = clone $column;

        return $this->columns[$name] = $column;
    }

    /**
     * Driver specific indexes information load. Columns must be added using registerColumn method
     *
     * @see registerIndex()
     */
    abstract protected function loadIndexes();

    /**
     * Register index schema supplied by database.
     *
     * @param string $name   Index name.
     * @param mixed  $schema Driver specific index information schema.
     * @return AbstractIndex
     */
    protected function registerIndex($name, $schema)
    {
        $index = $this->driver->indexSchema($this, $name, $schema);
        $this->dbIndexes[$name] = clone $index;

        return $this->indexes[$name] = $index;
    }

    /**
     * Driver specific foreign keys information load. Columns must be added using registerColumn method
     *
     * @see registerReference()
     */
    abstract protected function loadReferences();

    /**
     * Register foreign key schema supplied by database.
     *
     * @param string $name   Foreign key name.
     * @param mixed  $schema Driver specific foreign key information schema.
     * @return AbstractReference
     */
    protected function registerReference($name, $schema)
    {
        $reference = $this->driver->referenceSchema($this, $name, $schema);
        $this->dbReferences[$name] = clone $reference;

        return $this->references[$name] = $reference;
    }

    /**
     * Generate (and execute if specified) table creation syntax.
     *
     * @param bool $execute If true generated statement will be automatically executed.
     * @return string
     * @throws \Exception
     */
    protected function createSchema($execute = true)
    {
        $statement = [];
        $statement[] = "CREATE TABLE {$this->getName(true)} (";

        $inner = [];

        //Columns
        foreach ($this->columns as $column) {
            $inner[] = $column->sqlStatement();
        }

        //Primary key
        if (!empty($this->primaryKeys)) {
            $inner[] = 'PRIMARY KEY (' . join(', ', array_map(
                    [$this->driver, 'identifier'],
                    $this->primaryKeys
                )) . ')';
        }

        //Constraints
        foreach ($this->references as $reference) {
            $inner[] = $reference->sqlStatement();
        }

        $statement[] = "    " . join(",\n    ", $inner);
        $statement[] = ')';

        $statement = join("\n", $statement);

        $this->driver->beginTransaction();

        try {
            if ($execute) {
                $this->driver->statement($statement);

                //Not all databases support adding index while table creation, so we can do it after
                foreach ($this->indexes as $index) {
                    $this->doIndexAdd($index);
                }
            }
        } catch (\Exception $exception) {
            $this->driver->rollbackTransaction();
            throw $exception;
        }

        $this->driver->commitTransaction();

        return $statement;
    }

    /**
     * Perform set of atomic operations required to update table schema.
     *
     * @throws SchemaException
     * @throws \Exception
     */
    protected function updateSchema()
    {
        if ($this->primaryKeys != $this->dbPrimaryKeys) {
            throw new SchemaException("Primary keys can not be changed for already exists table.");
        }

        $this->driver->beginTransaction();
        try {
            foreach ($this->alteredColumns() as $name => $schema) {
                $dbColumn = isset($this->dbColumns[$name]) ? $this->dbColumns[$name] : null;

                if (empty($schema)) {
                    $this->logger()->info(
                        "Dropping column [{statement}] from table {table}.",
                        [
                            'statement' => $dbColumn->sqlStatement(),
                            'table'     => $this->getName(true)
                        ]
                    );

                    $this->doColumnDrop($dbColumn);
                    continue;
                }

                if (empty($dbColumn)) {
                    $this->logger()->info(
                        "Adding column [{statement}] into table {table}.",
                        [
                            'statement' => $schema->sqlStatement(),
                            'table'     => $this->getName(true)
                        ]
                    );

                    $this->doColumnAdd($schema);
                    continue;
                }

                //Altering
                $this->logger()->info(
                    "Altering column [{statement}] to [{new}] in table {table}.",
                    [
                        'statement' => $dbColumn->sqlStatement(),
                        'new'       => $schema->sqlStatement(),
                        'table'     => $this->getName(true)
                    ]
                );

                $this->doColumnChange($schema, $dbColumn);
            }

            foreach ($this->alteredIndexes() as $name => $schema) {
                $dbIndex = isset($this->dbIndexes[$name]) ? $this->dbIndexes[$name] : null;

                if (empty($schema)) {
                    $this->logger()->info(
                        "Dropping index [{statement}] from table {table}.",
                        [
                            'statement' => $dbIndex->sqlStatement(true),
                            'table'     => $this->getName(true)
                        ]
                    );

                    $this->doIndexDrop($dbIndex);
                    continue;
                }

                if (empty($dbIndex)) {
                    $this->logger()->info(
                        "Adding index [{statement}] into table {table}.",
                        [
                            'statement' => $schema->sqlStatement(false),
                            'table'     => $this->getName(true)
                        ]
                    );

                    $this->doIndexAdd($schema);
                    continue;
                }

                //Altering
                $this->logger()->info(
                    "Altering index [{statement}] to [{new}] in table {table}.",
                    [
                        'statement' => $dbIndex->sqlStatement(false),
                        'new'       => $schema->sqlStatement(false),
                        'table'     => $this->getName(true)
                    ]
                );

                $this->doIndexChange($schema, $dbIndex);
            }

            foreach ($this->alteredReferences() as $name => $schema) {
                $dbForeign = isset($this->dbReferences[$name]) ? $this->dbReferences[$name] : null;

                if (empty($schema)) {
                    $this->logger()->info(
                        "Dropping foreign key [{statement}] in table {table}.",
                        [
                            'statement' => $dbForeign->sqlStatement(),
                            'table'     => $this->getName(true)
                        ]
                    );

                    $this->doForeignDrop($this->dbReferences[$name]);
                    continue;
                }

                if (empty($dbForeign)) {
                    $this->logger()->info(
                        "Adding foreign key [{statement}] into table {table}.",
                        [
                            'statement' => $schema->sqlStatement(),
                            'table'     => $this->getName(true)
                        ]
                    );

                    $this->doForeignAdd($schema);
                    continue;
                }

                //Altering
                $this->logger()->info(
                    "Altering foreign key [{statement}] to [{new}] in table {table}.",
                    [
                        'statement' => $dbForeign->sqlStatement(),
                        'new'       => $schema->sqlStatement(),
                        'table'     => $this->getName(true)
                    ]
                );

                $this->doForeignChange($schema, $dbForeign);
            }
        } catch (\Exception $exception) {
            $this->driver->rollbackTransaction();
            throw $exception;
        }

        $this->driver->commitTransaction();
    }

    /**
     * Driver specific column add command.
     *
     * @param AbstractColumn $column
     */
    protected function doColumnAdd(AbstractColumn $column)
    {
        $this->driver->statement("ALTER TABLE {$this->getName(true)} "
            . "ADD COLUMN {$column->sqlStatement()}");
    }

    /**
     * Driver specific column remove (drop) command.
     *
     * @param AbstractColumn $column
     */
    protected function doColumnDrop(AbstractColumn $column)
    {
        //We have to erase all associated constraints
        foreach ($column->getConstraints() as $constraint) {
            $this->doConstraintDrop($constraint);
        }

        if ($this->hasForeign($column->getName())) {
            $this->doForeignDrop($this->foreign($column->getName()));
        }

        $this->driver->statement("ALTER TABLE {$this->getName(true)} "
            . "DROP COLUMN {$column->getName(true)}");
    }

    /**
     * Driver specific column alter command.
     *
     * @param AbstractColumn $column
     * @param AbstractColumn $dbColumn
     */
    abstract protected function doColumnChange(AbstractColumn $column, AbstractColumn $dbColumn);

    /**
     * Driver specific index adding command.
     *
     * @param AbstractIndex $index
     */
    protected function doIndexAdd(AbstractIndex $index)
    {
        $this->driver->statement("CREATE {$index->sqlStatement()}");
    }

    /**
     * Driver specific index remove (drop) command.
     *
     * @param AbstractIndex $index
     */
    protected function doIndexDrop(AbstractIndex $index)
    {
        $this->driver->statement("DROP INDEX {$index->getName(true)}");
    }

    /**
     * Driver specific index alter command, by default it will remove and add index.
     *
     * @param AbstractIndex $index
     * @param AbstractIndex $dbIndex
     */
    protected function doIndexChange(AbstractIndex $index, AbstractIndex $dbIndex)
    {
        $this->doIndexDrop($dbIndex);
        $this->doIndexAdd($index);
    }

    /**
     * Driver specific foreign key adding command.
     *
     * @param AbstractReference $foreign
     */
    protected function doForeignAdd(AbstractReference $foreign)
    {
        $this->driver->statement("ALTER TABLE {$this->getName(true)} ADD {$foreign->sqlStatement()}");
    }

    /**
     * Driver specific foreign key remove (drop) command.
     *
     * @param AbstractReference $foreign
     */
    protected function doForeignDrop(AbstractReference $foreign)
    {
        $this->driver->statement("ALTER TABLE {$this->getName(true)} "
            . "DROP CONSTRAINT {$foreign->getName(true)}");
    }

    /**
     * Drop column constraint using it's name.
     *
     * @param string $constraint
     */
    protected function doConstraintDrop($constraint)
    {
        $this->driver->statement(
            "ALTER TABLE {$this->getName(true)} DROP CONSTRAINT " . $this->driver->identifier($constraint)
        );
    }

    /**
     * Driver specific foreign key alter command, by default it will remove and add foreign key.
     *
     * @param AbstractReference $foreign
     * @param AbstractReference $dbForeign
     */
    protected function doForeignChange(AbstractReference $foreign, AbstractReference $dbForeign)
    {
        $this->doForeignDrop($dbForeign);
        $this->doForeignAdd($foreign);
    }

    /**
     * Find index using it's forming columns.
     *
     * @param array $columns
     * @return AbstractIndex|null
     */
    private function findIndex(array $columns)
    {
        foreach ($this->indexes as $index) {
            if ($index->getColumns() == $columns) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Find foreign using it's forming column.
     *
     * @param string $column
     * @return AbstractReference|null
     */
    private function findForeign($column)
    {
        foreach ($this->references as $reference) {
            if ($reference->getColumn() == $column) {
                return $reference;
            }
        }

        return null;
    }
}