<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\ODM;

use Spiral\Core\Container\InjectorInterface;
use Spiral\Core\ContainerInterface;
use Spiral\Core\ConfiguratorInterface;
use Spiral\Core\HippocampusInterface;
use Spiral\Core\Traits\ConfigurableTrait;
use Spiral\Debug\Traits\BenchmarkTrait;
use Spiral\Events\Traits\EventsTrait;
use Spiral\Core\Singleton;

class ODM extends Singleton implements InjectorInterface
{
    /**
     * Will provide us helper method getInstance().
     */
    use ConfigurableTrait, EventsTrait, BenchmarkTrait;

    /**
     * Declares to IoC that component instance should be treated as singleton.
     */
    const SINGLETON = self::class;

    /**
     * Core component.
     *
     * @var HippocampusInterface
     */
    protected $runtime = null;

    /**
     * Container instance.
     *
     * @invisible
     * @var ContainerInterface
     */
    protected $container = null;

    /**
     * Loaded documents schema. Schema contains association between models and collections, children
     * chain, compiled default values and other presets can't be fetched in real time.
     *
     * @var array|null
     */
    protected $schema = null;

    /**
     * Mongo databases instances.
     *
     * @var MongoDatabase[]
     */
    protected $databases = [];

    /**
     * ODM component instance.
     *
     * @param ConfiguratorInterface $configurator
     * @param HippocampusInterface  $runtime
     * @param ContainerInterface    $container
     */
    public function __construct(
        ConfiguratorInterface $configurator,
        HippocampusInterface $runtime,
        ContainerInterface $container
    )
    {
        $this->config = $configurator->getConfig($this);

        $this->runtime = $runtime;
        $this->container = $container;
    }

    /**
     * Get instance of MongoDatabase to handle connection, collections fetching and other operations.
     * Spiral MongoDatabase is layer at top of MongoClient ans MongoDB, it has all MongoDB features
     * plus ability to be injected.
     *
     * @param string $database Database name (internal).
     * @param array  $config   Connection options, only required for databases not listed in ODM config.
     * @return MongoDatabase
     * @throws ODMException
     */
    public function db($database = 'default', array $config = [])
    {
        if (isset($this->config['aliases'][$database]))
        {
            $database = $this->config['aliases'][$database];
        }

        if (isset($this->databases[$database]))
        {
            return $this->databases[$database];
        }

        if (empty($config))
        {
            if (!isset($this->config['databases'][$database]))
            {
                throw new ODMException(
                    "Unable to initiate mongo database, no presets for '{$database}' found."
                );
            }

            $config = $this->config['databases'][$database];
        }

        $this->benchmark('database', $database);
        $this->databases[$database] = $this->container->get(MongoDatabase::class, [
            'name'   => $database,
            'config' => $config,
            'odm'    => $this
        ]);
        $this->benchmark('database', $database);

        return $this->databases[$database];
    }

    /**
     * Injector will receive requested class or interface reflection and reflection linked
     * to parameter in constructor or method.
     *
     * This method can return pre-defined instance or create new one based on requested class. Parameter
     * reflection can be used for dynamic class constructing, for example it can define database name
     * or config section to be used to construct requested instance.
     *
     * @param \ReflectionClass     $class
     * @param \ReflectionParameter $parameter
     * @return mixed
     */
    public function createInjection(\ReflectionClass $class, \ReflectionParameter $parameter)
    {
        return $this->db($parameter->getName());
    }

    /**
     * Get schema for specified document class or collection.
     *
     * @param string $item Document class or collection name (including database).
     * @return mixed
     */
    public function getSchema($item)
    {
        if ($this->schema === null)
        {
            $this->schema = $this->runtime->loadData('odmSchema');
        }

        if (!isset($this->schema[$item]))
        {
            $this->updateSchema();
        }

        return $this->schema[$item];
    }

    /**
     * Get ODM schema reader. Schema will detect all existed documents, collections, relationships
     * between them and will generate virtual documentation.
     *
     * @return SchemaBuilder
     */
    public function schemaBuilder()
    {
        return $this->container->get(SchemaBuilder::class, ['config' => $this->config]);
    }

    /**
     * Refresh ODM schema state, will reindex all found document models. This is slow method using
     * Tokenizer, refreshSchema() should not be called by user request.
     *
     * @return SchemaBuilder
     */
    public function updateSchema()
    {
        $builder = $this->schemaBuilder();

        //Create all required indexes
        $builder->createIndexes($this);

        $this->schema = $this->fire('schema', $builder->normalizeSchema());

        //We have to flush schema cache after schema update, just in case
        Document::clearSchemaCache();

        //Saving
        $this->runtime->saveData('odmSchema', $this->schema);

        return $builder;
    }

    /**
     * Create valid MongoId object based on string or id provided from client side, this methods can
     * be used as model filter as it will pass MongoId objects without any change.
     *
     * @param mixed $mongoID String or MongoId object.
     * @return \MongoId|null
     */
    public static function mongoID($mongoID)
    {
        if (empty($mongoID))
        {
            return null;
        }

        if (!is_object($mongoID))
        {
            //Old versions of mongo api does not throws exception on invalid mongo id (1.2.1)
            if (!is_string($mongoID) || !preg_match('/[0-9a-f]{24}/', $mongoID))
            {
                return null;
            }

            try
            {
                $mongoID = new \MongoId($mongoID);
            }
            catch (\Exception $exception)
            {
                return null;
            }
        }

        return $mongoID;
    }

    /**
     * Method will return class name selected based on class definition rules, rules defined in
     * Document class and can be LOGICAL or FIELDS based.
     *
     * @see Document::DEFINITION
     * @param mixed $fields     Document fields fetched from database.
     * @param mixed $definition Definition, can be string (one class) or array with options.
     * @return string
     */
    public static function defineClass($fields, $definition)
    {
        if (is_string($definition))
        {
            return $definition;
        }

        if ($definition[self::DEFINITION] == Document::DEFINITION_LOGICAL)
        {
            //Function based
            $definition = call_user_func($definition[self::DEFINITION_OPTIONS], $fields);
        }
        else
        {
            //Property based
            foreach ($definition[self::DEFINITION_OPTIONS] as $class => $field)
            {
                $definition = $class;
                if (array_key_exists($field, $fields))
                {
                    break;
                }
            }
        }

        return $definition;
    }

    /**
     * This is set of constants used in normalized ODM schema, you can use them to read already created
     * schema but they are useless besides normal development process.
     *
     * Class definition options.
     */
    const DEFINITION         = 0;
    const DEFINITION_OPTIONS = 1;

    /**
     * Normalized collection constants.
     */
    const C_NAME       = 0;
    const C_DB         = 1;
    const C_DEFINITION = 2;

    /**
     * Normalized document constants.
     */
    const D_COLLECTION   = 0;
    const D_DB           = 1;
    const D_DEFAULTS     = 2;
    const D_HIDDEN       = 3;
    const D_SECURED      = 4;
    const D_FILLABLE     = 5;
    const D_MUTATORS     = 6;
    const D_VALIDATES    = 7;
    const D_AGGREGATIONS = 8;
    const D_COMPOSITIONS = 9;

    /**
     * Normalized aggregation.
     */
    const AGR_TYPE  = 0;
    const AGR_QUERY = 1;

    /**
     * Matched to D_COLLECTION and D_DB to use in Document::odmCollection() method.
     */
    const AGR_COLLECTION = 0;
    const AGR_DB         = 1;

    /**
     * Normalized composition.
     */
    const CMP_TYPE       = 0;
    const CMP_DEFINITION = 1;
    const CMP_ONE        = 0x111;
    const CMP_MANY       = 0x222;
}