<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\ORM\Schemas;

use Doctrine\Common\Inflector\Inflector;
use Psr\Log\LoggerAwareInterface;
use Spiral\Database\Schemas\AbstractColumn;
use Spiral\Database\Schemas\AbstractTable;
use Spiral\Database\SqlFragmentInterface;
use Spiral\Debug\Traits\LoggerTrait;
use Spiral\Models\Schemas\ReflectionEntity;
use Spiral\ORM\Model;
use Spiral\ORM\ModelAccessorInterface;
use Spiral\ORM\ORMException;
use Spiral\ORM\SchemaBuilder;

class ModelSchema extends ReflectionEntity implements LoggerAwareInterface
{
    /**
     * Logging.
     */
    use LoggerTrait;

    /**
     * Base model class.
     */
    const BASE_CLASS = Model::class;

    /**
     * ActiveRecord model class name.
     *
     * @var string
     */
    protected $class = '';

    /**
     * Parent ORM schema holds all other entities schema.
     *
     * @invisible
     * @var SchemaBuilder
     */
    protected $builder = null;

    /**
     * Table schema used to fetch information about declared or fetched columns. Empty if model is
     * abstract.
     *
     * @var AbstractTable
     */
    protected $tableSchema = null;

    /**
     * Model relationships.
     *
     * @var RelationSchemaInterface[]
     */
    protected $relations = [];

    /**
     * Column names associated with their default values.
     *
     * @var array
     */
    protected $columns = [];

    /**
     * New RecordSchema instance, schema responsible for detecting relationships, columns and indexes.
     * This class is really similar to DocumentSchema and can be merged into common parent in future.
     *
     * @param string        $class         Class name.
     * @param SchemaBuilder $schemaBuilder Parent ORM schema (all other documents).
     */
    public function __construct($class, SchemaBuilder $schemaBuilder)
    {
        $this->class = $class;
        $this->builder = $schemaBuilder;

        $this->reflection = new \ReflectionClass($class);

        //Linked table
        $this->tableSchema = $this->builder->table($this->getDatabase(), $this->getTable());

        //Casting table columns, indexes, foreign keys and etc
        $this->castTableSchema();
    }

    /**
     * Get name should be used to represent model relationship in foreign classes (default behaviour).
     *
     * Example:
     * Models\Post => HAS_ONE => post_id
     *
     * @return string
     */
    public function getRoleName()
    {
        return lcfirst($this->getName());
    }

    /**
     * True if active record allowed schema modifications.
     *
     * @return bool
     */
    public function isActiveSchema()
    {
        return $this->reflection->getConstant('ACTIVE_SCHEMA');
    }

    /**
     * Get database model data should be stored in.
     *
     * @return mixed
     */
    public function getDatabase()
    {
        return $this->property('database');
    }

    /**
     * Get table name associated with model.
     *
     * @return mixed
     */
    public function getTable()
    {
        $table = $this->property('table');

        if (empty($table))
        {
            //We can guess table name
            $table = $this->reflection->getShortName();
            $table = Inflector::tableize($table);

            //Table names are plural by default
            return Inflector::pluralize($table);
        }

        return $table;
    }

    /**
     * Get associated table schema. Result can be empty if models is abstract or schema is empty.
     *
     * @return AbstractTable|null
     */
    public function tableSchema()
    {
        return $this->tableSchema;
    }

    /**
     * Get model declared schema (merged with parent model(s) values).
     *
     * @return array
     */
    public function getSchema()
    {
        return $this->property('schema', true);
    }

    /**
     * Get declared indexes. This is not the same set of indexes which can be presented in table
     * schema, use RecordSchema->getTableSchema()->getIndexes() method for it.
     *
     * @see tableSchema()
     * @return array
     */
    public function getIndexes()
    {
        return $this->property('indexes', true);
    }

    /**
     * Get column names associated with their default values.
     *
     * @return array
     */
    public function getDefaults()
    {
        //We have to reiterate columns as schema can be altered while relation creation,
        //plus we always have to keep original columns order (this is very important)
        $defaults = [];
        foreach ($this->tableSchema->getColumns() as $column)
        {
            if (!array_key_exists($column->getName(), $this->columns))
            {
                $defaults[$column->getName()] = $this->prepareDefault(
                    $column->getName(),
                    $column->getDefaultValue()
                );
                continue;
            }

            $defaults[$column->getName()] = $this->columns[$column->getName()];
        }

        return $defaults;
    }

    /**
     * Fields associated with their type.
     *
     * @return array
     */
    public function getFields()
    {
        $result = [];
        foreach ($this->tableSchema->getColumns() as $column)
        {
            $result[$column->getName()] = $column->phpType();
        }

        return $result;
    }

    /**
     * Find all field mutators.
     *
     * @return mixed
     */
    protected function getMutators()
    {
        $mutators = parent::getMutators();

        //Default values.
        foreach ($this->tableSchema->getColumns() as $field => $column)
        {
            $type = $column->abstractType();

            $resolved = [];
            if ($filter = $this->builder->getMutators($type))
            {
                $resolved += $filter;
            }
            elseif ($filter = $this->builder->getMutators('php:' . $column->phpType()))
            {
                $resolved += $filter;
            }

            if (isset($resolved['accessor']))
            {
                //Ensuring type for accessor
                $resolved['accessor'] = [$resolved['accessor'], $type];
            }

            foreach ($resolved as $mutator => $filter)
            {
                if (!array_key_exists($field, $mutators[$mutator]))
                {
                    $mutators[$mutator][$field] = $filter;
                }
            }
        }

        foreach ($mutators as $mutator => &$filters)
        {
            foreach ($filters as $field => $filter)
            {
                $filters[$field] = $this->builder->processAlias($filter);

                if ($mutator == 'accessor' && is_string($filters[$field]))
                {
                    $type = null;
                    if (!empty($this->tableSchema->getColumns()[$field]))
                    {
                        $type = $this->tableSchema->getColumns()[$field]->abstractType();
                    }

                    $filters[$field] = [$filters[$field], $type];
                }
            }
            unset($filters);
        }

        return $mutators;
    }

    /**
     * Name of first primary key (usually sequence).
     *
     * @return string|null
     */
    public function getPrimaryKey()
    {
        if (empty($this->tableSchema->getPrimaryKeys()))
        {
            return null;
        }

        //Spiral ORM can work only with singular primary keys for now
        return array_slice($this->tableSchema->getPrimaryKeys(), 0, 1)[0];
    }

    /**
     * Fill table schema with declared columns, their default values and etc.
     */
    protected function castTableSchema()
    {
        $this->columns = $this->property('defaults', true);
        foreach ($this->property('schema', true) as $name => $definition)
        {
            //Column definition
            if (is_string($definition))
            {
                //Filling column values
                $this->columns[$name] = $this->castColumn(
                    $this->tableSchema->column($name),
                    $definition,
                    isset($this->columns[$name]) ? $this->columns[$name] : null
                );

                //Preparing default value to be stored in cache
                $this->columns[$name] = $this->prepareDefault($name, $this->columns[$name]);
            }
        }

        //We can cast declared indexes there, however some relationships may cast more indexes
        foreach ($this->getIndexes() as $definition)
        {
            $this->castIndex($definition);
        }
    }

    /**
     * Cast column schema based on provided column definition and default value. Spiral will force
     * default values (internally) for every NOT NULL column except primary keys.
     *
     * Column definition examples (by default all columns has flag NOT NULL):
     * id           => primary
     * name         => string       [default 255 symbols]
     * email        => string(255), nullable
     * status       => enum(active, pending, disabled)
     * balance      => decimal(10, 2)
     * message      => text, null[able]
     * time_expired => timestamp
     *
     * @param AbstractColumn $column
     * @param string         $definition
     * @param mixed          $default Declared default value or null.
     * @return mixed
     * @throws ORMException
     */
    protected function castColumn(AbstractColumn $column, $definition, $default = null)
    {
        if (!is_null($default))
        {
            $column->defaultValue($default);
        }

        $validType = preg_match(
            '/(?P<type>[a-z]+)(?: *\((?P<options>[^\)]+)\))?(?: *, *(?P<nullable>null(?:able)?))?/i',
            $definition,
            $matches
        );

        //Parsing definition
        if (!$validType)
        {
            throw new ORMException(
                "Unable to parse definition of column {$this->getClass()}.'{$column->getName()}'."
            );
        }

        //We forcing all columns to be NOT NULL by default, DEFAULT value should fix potential problems
        $column->nullable(false);
        if (!empty($matches['nullable']))
        {
            //No need to force NOT NULL as this is default column state
            $column->nullable(true);
        }

        $type = $matches['type'];

        $options = [];
        if (!empty($matches['options']))
        {
            $options = array_map('trim', explode(',', $matches['options']));
        }

        //DBAL will handle the rest of declaration
        call_user_func_array([$column, $type], $options);

        $default = $column->getDefaultValue();

        if ($default instanceof SqlFragmentInterface)
        {
            //We have to rebuild default type in scalar form
            $default = null;
        }

        if (empty($default) && in_array($column->getName(), $this->tableSchema->getPrimaryKeys()))
        {
            return null;
        }

        //We have to cast default value to prevent errors
        if (empty($default) && !$column->isNullable())
        {
            $default = $this->castDefaultValue($column);
            $column->defaultValue($default);
        }

        return $default;
    }

    /**
     * Cast default value based on column type.
     *
     * @param AbstractColumn $column
     * @return bool|float|int|mixed|string
     */
    protected function castDefaultValue(AbstractColumn $column)
    {
        //As no default value provided and column can not be null we can cast value by ourselves
        if ($column->abstractType() == 'timestamp' || $column->abstractType() == 'datetime')
        {
            $driver = $this->tableSchema->getDriver();

            return $driver::DEFAULT_DATETIME;
        }
        else
        {
            switch ($column->phpType())
            {
                case 'int':
                    return 0;
                    break;
                case 'float':
                    return 0.0;
                    break;
                case 'bool':
                    return false;
                    break;
            }
        }

        return '';
    }

    /**
     * Prepare default value to be stored in models schema.
     *
     * @param string $name
     * @param mixed  $defaultValue
     * @return mixed|null
     */
    protected function prepareDefault($name, $defaultValue = null)
    {
        if (array_key_exists($name, $this->getAccessors()))
        {
            $accessor = $this->getAccessors()[$name];
            $option = null;
            if (is_array($accessor))
            {
                list($accessor, $option) = $accessor;
            }

            /**
             * @var ModelAccessorInterface $accessor
             */
            $accessor = new $accessor($defaultValue, null, $option);

            //We have to pass default value thought accessor
            return $accessor->defaultValue($this->tableSchema->getDriver());
        }

        if (array_key_exists($name, $this->getSetters()) && $this->getSetters()[$name])
        {
            $setter = $this->getSetters()[$name];

            //We have to pass default value thought accessor
            return call_user_func($setter, $defaultValue);
        }

        return $defaultValue;
    }

    /**
     * Create index in associated table based on index definition provided in model or model parent.
     * Attention, this method does not support primary indexes (for now). Additionally, some
     * relationships will create indexes automatically while defining foreign key.
     *
     * Examples:
     * protected $indexes = array(
     *      [self::UNIQUE, 'email'],
     *      [self::INDEX, 'status', 'balance'],
     *      [self::INDEX, 'public_id']
     * );
     *
     * @param array $definition
     * @throws ORMException
     */
    protected function castIndex(array $definition)
    {
        $type = null;
        $columns = [];

        foreach ($definition as $chunk)
        {
            if ($chunk == Model::INDEX || $chunk == Model::UNIQUE)
            {
                $type = $chunk;
                continue;
            }

            if (!$this->tableSchema->hasColumn($chunk))
            {
                throw new ORMException("Model {$this->getClass()} has index with undefined column.");
            }

            $columns[] = $chunk;
        }

        if (empty($type))
        {
            throw new ORMException("Model {$this->getClass()} has index with unspecified type.");
        }

        //Defining index
        $this->tableSchema->index($columns)->unique($type == Model::UNIQUE);
    }

    /**
     * Casting model relationships.
     */
    public function castRelations()
    {
        foreach ($this->property('schema', true) as $name => $definition)
        {
            if (is_string($definition))
            {
                //Column definition
                continue;
            }

            $this->addRelation($name, $definition);
        }
    }

    /**
     * Get all declared model relations.
     *
     * @return RelationSchemaInterface[]
     */
    public function getRelations()
    {
        return $this->relations;
    }

    /**
     * Add relation to RecordSchema.
     *
     * @param string $name
     * @param array  $definition
     */
    public function addRelation($name, array $definition)
    {
        if (isset($this->relations[$name]))
        {
            $this->logger()->warning(
                "Unable to create relation '{class}'.'{name}', connection already exists.",
                [
                    'name'  => $name,
                    'class' => $this->getClass()
                ]
            );

            return;
        }

        $relation = $this->builder->relationSchema($this, $name, $definition);

        //Initiating required columns, foreign keys and indexes
        $relation->buildSchema();

        $this->relations[$name] = $relation;
    }
}