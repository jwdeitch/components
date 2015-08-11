<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright �2009-2015
 */
namespace Spiral\ORM\Entities;

use Spiral\Core\Component;
use Spiral\Core\Traits\ConfigurableTrait;
use Spiral\Database\Entities\Schemas\AbstractTable;
use Spiral\ORM\Entities\Schemas\ModelSchema;
use Spiral\ORM\Exceptions\ModelSchemaException;
use Spiral\ORM\Exceptions\RelationSchemaException;
use Spiral\ORM\Exceptions\SchemaException;
use Spiral\ORM\Model;
use Spiral\ORM\ORM;
use Spiral\ORM\RelationSchemaInterface;
use Spiral\Tokenizer\TokenizerInterface;

/**
 * Schema builder responsible for static analysis of existed ORM Models, their schemas, validations,
 * related tables, requested indexes and etc.
 */
class SchemaBuilder extends Component
{
    /**
     * Schema builder configuration includes mutators list and etc.
     */
    use ConfigurableTrait;

    /**
     * @var ModelSchema[]
     */
    private $models = [];

    /**
     * @var AbstractTable[]
     */
    private $tables = [];

    /**
     * @var ORM
     */
    protected $orm = null;

    /**
     * @param ORM                $orm
     * @param array              $config
     * @param TokenizerInterface $tokenizer
     */
    public function __construct(ORM $orm, array $config, TokenizerInterface $tokenizer)
    {
        $this->config = $config;
        $this->orm = $orm;

        $this->locateModels($tokenizer);
    }

    /**
     * @return ORM
     */
    public function getORM()
    {
        return $this->orm;
    }

    /**
     * Check if Model class known to schema builder.
     *
     * @param string $class
     * @return bool
     */
    public function hasModel($class)
    {
        return isset($this->models[$class]);
    }

    /**
     * Instance of ModelSchema associated with given class name.
     *
     * @param string $class
     * @return ModelSchema
     * @throws SchemaException
     * @throws ModelSchemaException
     */
    public function model($class)
    {
        if ($class == Model::class) {
            //No need to remember schema for abstract Document
            return new ModelSchema($this, Model::class);
        }

        if (!isset($this->models[$class])) {
            throw new SchemaException("Unknown model class '{$class}'.");
        }

        return $this->models[$class];
    }

    /**
     * @return ModelSchema[]
     */
    public function getModels()
    {
        return $this->models;
    }

    /**
     * Check if given table was declared by one of model or relation.
     *
     * @param string $database Table database.
     * @param string $table    Table name without prefix.
     * @return bool
     */
    public function hasTable($database, $table)
    {
        return isset($this->tables[$database . '/' . $table]);
    }

    /**
     * Request table schema. Every non empty table schema will be synchronized with it's databases
     * when executeSchema() method will be called.
     *
     * Attention, every declared table will be synced with database if their initiator allows such
     * operation.
     *
     * @param string $database Table database.
     * @param string $table    Table name without prefix.
     * @return AbstractTable
     */
    public function declareTable($database, $table)
    {
        $database = $this->resolveDatabase($database);
        if (isset($this->tables[$database . '/' . $table])) {
            return $this->tables[$database . '/' . $table];
        }

        $schema = $this->orm->dbalDatabase($database)->table($table)->schema();

        return $this->tables[$database . '/' . $table] = $schema;
    }

    /**
     * Get list of every declared table schema.
     *
     * @param bool $cascade Sort tables in order of their dependencies.
     * @return AbstractTable[]
     */
    public function getTables($cascade = true)
    {
        if (!$cascade) {
            return $this->tables;
        }

        $tables = $this->tables;
        uasort($tables, function (AbstractTable $tableA, AbstractTable $tableB) {
            if (in_array($tableA->getName(), $tableB->getDependencies())) {
                return true;
            }

            return count($tableB->getDependencies()) > count($tableA->getDependencies());
        });

        return array_reverse($tables);
    }

    /**
     * SchemaBuilder will request every located ModelSchema to declare it's relations. In addition
     * this methods will create inversed set of relations.
     *
     * @throws SchemaException
     * @throws RelationSchemaException
     * @throws ModelSchemaException
     */
    public function castRelations()
    {
        $inversedRelations = [];
        foreach ($this->models as $model) {
            if ($model->isAbstract()) {
                //Abstract models can not declare relations or tables
                continue;
            }

            $model->castRelations();

            foreach ($model->getRelations() as $relation) {
                if ($relation->isInversable()) {
                    //Relation can be automatically inversed
                    $inversedRelations[] = $relation;
                }
            }
        }

        /**
         * We have to perform inversion after every generic relation was defined. Sometimes models
         * can define inversed relation by themselves.
         *
         * @var RelationSchemaInterface $relation
         */
        foreach ($inversedRelations as $relation) {
            $relation->inverseRelation();
        }
    }

    /**
     * Perform schema reflection to database(s). All declared tables will created or altered. Only
     * tables linked to non abstract models and model with active schema parameter will be executed.
     *
     * SchemaBuilder will not allow (SchemaException) to create or alter tables columns declared
     * by abstract or models with ACTIVE_SCHEMA constant set to false. ActiveSchema still can
     * declare foreign keys and indexes (most of relations automatically request index or foreign
     * key), but they are going to be ignored.
     *
     * Due principals of database schemas and ORM component logic no data or columns will ever be
     * removed from database. In addition column renaming will cause creation of another column.
     *
     * Use database migrations to solve more complex database questions. Or disable ACTIVE_SCHEMA and
     * live like normal people.
     *
     * @throws SchemaException
     * @throws \Spiral\Database\Exceptions\SchemaException
     * @throws \Spiral\Database\Exceptions\QueryException
     * @throws \Spiral\Database\Exceptions\DriverException
     */
    public function synchronizeSchema()
    {
        //We need list of declared tables in order of
        foreach ($this->getTables(true) as $name => $table) {
            //            //Abstract tables know nothing about their parent database... for some reason.
            //            $database = explode('/', $table)[0];
            //
            //            //Let's try to locate model related to this table declaration
            //            $model = $this->findAssociatedModel($table);
            //
            //            //If declared table has no associated model, we are expecting that this is pivot table
            //            //let's find table parents and ask them about what to do
            //            if (empty($model) && !$t) {
            //            }
            //
            //            if ($model->isAbstract()) {
            //                //We are not going to create anything requested by abstract models, ignoring
            //                continue;
            //            }
            //
            //            if (!$model->isActive()) {
            //            }

            //            foreach ($this->getTables(true) as $table) {
            //                foreach ($this->models as $model) {
            //
            //
            //
            //
            //                    if ($model->tableSchema() != $table) {
            //                        continue;
            //                    }
            //
            //                    if ($model->isAbstract()) {
            //                        //Model is abstract, meaning we are not going to perform any table related
            //                        //operation
            //                        continue 2;
            //                    }
            //
            //                    if ($model->isActiveSchema()) {
            //                        //Model has active schema, we are good
            //                        break;
            //                    }
            //
            //                    //We have to thrown an exception if model with ACTIVE_SCHEMA = false requested
            //                    //any column change (for example via external relation)
            //                    if (!empty($columns = $table->alteredColumns())) {
            //                        $names = [];
            //                        foreach ($columns as $column) {
            //                            $names[] = $column->getName(true);
            //                        }
            //
            //                        $names = join(', ', $names);
            //
            //                        throw new ORMException(
            //                            "Unable to alter '{$table->getName()}' columns ({$names}), "
            //                            . "associated model stated ACTIVE_SCHEMA = false."
            //                        );
            //                    }
            //
            //                    continue 2;
            //                }
            //
            //                $table->save();
            //            }
        }
    }

    /**
     * Resolve real database name using it's alias.
     *
     * @see DatabaseProvider
     * @param string|null $alias
     * @return string
     */
    public function resolveDatabase($alias)
    {
        return $this->orm->dbalDatabase($alias)->getName();
    }

    /**
     * Get all mutators associated with field type.
     *
     * @param string $type Field type.
     * @return array
     */
    public function getMutators($type)
    {
        return isset($this->config['mutators'][$type]) ? $this->config['mutators'][$type] : [];
    }

    /**
     * Get mutator alias if presented. Aliases used to simplify schema (accessors) definition.
     *
     * @param string $alias
     * @return string|array
     */
    public function mutatorAlias($alias)
    {
        if (!is_string($alias) || !isset($this->config['mutatorAliases'][$alias])) {
            return $alias;
        }

        return $this->config['mutatorAliases'][$alias];
    }

    /**
     * Normalize model schema in lighter structure to be saved in ORM component memory.
     *
     * @return array
     * @throws SchemaException
     */
    public function normalizeSchema()
    {
        $result = [];
        foreach ($this->models as $model) {
            if ($model->isAbstract()) {
                continue;
            }

            $schema = [
                ORM::M_ROLE_NAME   => $model->getRole(),
                ORM::M_TABLE       => $model->getTable(),
                ORM::M_DB          => $model->getDatabase(),
                ORM::M_PRIMARY_KEY => $model->getPrimaryKey(),
                ORM::M_COLUMNS     => $model->getDefaults(),
                ORM::M_HIDDEN      => $model->getHidden(),
                ORM::M_SECURED     => $model->getSecured(),
                ORM::M_FILLABLE    => $model->getFillable(),
                ORM::M_MUTATORS    => $model->getMutators(),
                ORM::M_VALIDATES   => $model->getValidates(),
                ORM::M_RELATIONS   => []
            ];

            ksort($schema);
            $result[$model->getName()] = $schema;
        }

        return $result;
    }

    /**
     * Create appropriate instance of RelationSchema based on it's definition provided by ORM Model
     * or manually. Due internal format first definition key will be stated as definition type and
     * key value as model/entity definition relates too.
     *
     * @param ModelSchema $model
     * @param string      $name
     * @param array       $definition
     * @return RelationSchemaInterface
     * @throws SchemaException
     */
    public function relationSchema(ModelSchema $model, $name, array $definition)
    {
        if (empty($definition)) {
            throw new SchemaException("Relation definition can not be empty.");
        }

        reset($definition);
        //Relation type must be provided as first in definition
        $type = key($definition);

        //We are letting ORM to resolve relation schema using container
        $relation = $this->orm->relationSchema($type, $this, $model, $name, $definition);

        if ($relation->hasEquivalent()) {
            //Some relations may declare equivalent relation to be used instead, used for Morphed
            //relations
            return $relation->createEquivalent();
        }

        return $relation;
    }

    /**
     * Locate every available Model class.
     *
     * @param TokenizerInterface $tokenizer
     * @throws SchemaException
     */
    protected function locateModels(TokenizerInterface $tokenizer)
    {
        //Table names associated with models
        $sources = [];
        foreach ($tokenizer->getClasses(Model::class) as $class => $definition) {
            if ($class == Model::class) {
                continue;
            }

            $this->models[$class] = $model = new ModelSchema($this, $class);

            if (!$model->isAbstract()) {
                //See comment near exception
                continue;
            }

            //Model associated tableID (includes resolved database name)
            $sourceID = $model->getSourceID();
            if (isset($sources[$sourceID])) {
                //We are not allowing multiple models talk to same database, unless they one of them
                //is abstract
                throw new SchemaException(
                    "Model '{$model}' associated with "
                    . "same source table '{$sourceID}' as '{$sources[$sourceID]}'."
                );
            }

            $sources[$sourceID] = $model;
        }
    }

    private function isAllowed($database, AbstractTable $table)
    {
    }

    private function isAllqowed($database, AbstractTable $table)
    {
    }

    /**
     * Must find associated model instance or return null if no models counted as associated.
     *
     * @param AbstractTable $table
     * @return ModelSchema|null
     */
    private function findAssociatedModel(AbstractTable $table)
    {
        foreach ($this->getModels() as $model) {
            if ($model->tableSchema() === $table) {
                return $model;
            }
        }

        //No associated models found
        return null;
    }


    private function packRelations(ModelSchema $model)
    {
        //TODO: DO!
        foreach ($model->getRelations() as $name => $relation) {
            $schema[ORM::M_RELATIONS][$name] = $relation->normalizeSchema();
        }
    }
}