<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\ODM\Schemas;

use Spiral\Models\Schemas\EntitySchema;
use Spiral\ODM\Accessors\Compositor;
use Spiral\ODM\Document;
use Spiral\ODM\ODM;
use Spiral\ODM\ODMAccessor;
use Spiral\ODM\ODMException;
use Spiral\ODM\SchemaBuilder;

class DocumentSchema extends EntitySchema
{
    /**
     * Base model class.
     */
    const BASE_CLASS = Document::class;

    /**
     * Parent ODM schema builder holds all other documents.
     *
     * @invisible
     * @var SchemaBuilder
     */
    protected $builder = null;

    /**
     * Document model class name.
     *
     * @var string
     */
    protected $class = '';

    /**
     * New DocumentSchema instance, document schema responsible for fetching schema, defaults
     * and filters from Document models.
     *
     * @param SchemaBuilder $builder Parent ODM schema (all other documents).
     * @param string        $class   Class name.
     */
    public function __construct(SchemaBuilder $builder, $class)
    {
        $this->builder = $builder;
        $this->class = $class;
        $this->reflection = new \ReflectionClass($class);
    }

    /**
     * Reading default model property value, will read "protected" and "private" properties.
     *
     * @param string $property Property name.
     * @param bool   $merge    If true value will be merged with all parent declarations.
     * @return mixed
     */
    protected function property($property, $merge = false)
    {
        if (isset($this->propertiesCache[$property]))
        {
            return $this->propertiesCache[$property];
        }

        $defaults = $this->reflection->getDefaultProperties();
        if (isset($defaults[$property]))
        {
            $value = $defaults[$property];
        }
        else
        {
            return null;
        }

        if ($merge && ($this->reflection->getParentClass()->getName() != static::BASE_CLASS))
        {
            $parentClass = $this->reflection->getParentClass()->getName();

            if (is_array($value))
            {
                $value = array_merge(
                    $this->builder->documentSchema($parentClass)->property($property, true),
                    $value
                );
            }
        }

        return $this->propertiesCache[$property] = call_user_func(
            [$this->getClass(), 'describeProperty'],
            $this,
            $property,
            $value
        );
    }

    /**
     * Parent document class, null if model extended directly from Document class.
     *
     * @return null|string
     */
    public function getParent()
    {
        $parentClass = $this->reflection->getParentClass()->getName();

        return $parentClass != static::BASE_CLASS ? $parentClass : null;
    }

    /**
     * Get collection name associated with document model.
     *
     * @return mixed
     */
    public function getCollection()
    {
        return $this->property('collection');
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
     * Get document declared schema (merged with parent model(s) values).
     *
     * @return array
     */
    public function getSchema()
    {
        //Reading schema as property to inherit all values
        return $this->property('schema', true);
    }

    /**
     * Fields associated with their type. Will include compositions.
     *
     * @return array
     */
    public function getFields()
    {
        //We should select only embedded fields, no aggregations
        $schema = $this->getSchema();

        $fields = [];
        foreach ($schema as $field => $type)
        {
            if (
                is_array($type)
                &&
                (
                    array_key_exists(Document::MANY, $type)
                    || array_key_exists(Document::ONE, $type)
                )
            )
            {
                //Aggregation
                continue;
            }

            $fields[$field] = $type;
        }

        return $fields;
    }

    /**
     * Find all field mutators.
     *
     * @return mixed
     */
    public function getMutators()
    {
        $mutators = parent::getMutators();

        //Default values.
        foreach ($this->getFields() as $field => $type)
        {
            $resolved = [];

            if (
                is_array($type)
                && is_scalar($type[0])
                && $filter = $this->builder->getMutators($field . '::' . $type[0])
            )
            {
                $resolved += $filter;
            }
            elseif (is_array($type) && $filter = $this->builder->getMutators('array'))
            {
                $resolved += $filter;
            }
            elseif (!is_array($type) && $filter = $this->builder->getMutators($type))
            {
                $resolved += $filter;
            }

            if (isset($resolved['accessor']))
            {
                //Ensuring type for accessor
                $resolved['accessor'] = [
                    $resolved['accessor'],
                    is_array($type) ? $type[0] : $type
                ];
            }

            foreach ($resolved as $mutator => $filter)
            {
                if (!array_key_exists($field, $mutators[$mutator]))
                {
                    $mutators[$mutator][$field] = $this->builder->processAlias($filter);
                }
            }
        }

        //Mounting composition accessors
        foreach ($this->getCompositions() as $field => $composition)
        {
            //Composition::ONE has to be resolved little bit different way due model inheritance
            $mutators['accessor'][$field] = [
                $composition['type'] == ODM::CMP_MANY ? Compositor::class : ODM::CMP_ONE,
                $composition['classDefinition']
            ];
        }

        return $mutators;
    }

    /**
     * Get document default values (merged with parent model(s) values). Default values will be passed
     * thought model filters, this will help us to ensure that field will always have desired type.
     *
     * @return array
     */
    public function getDefaults()
    {
        $defaults = $this->property('defaults', true);

        foreach ($this->getCompositions() as $field => $composition)
        {
            if ($composition['type'] == ODM::CMP_ONE)
            {
                $defaults[$field] = $this->builder->documentSchema($composition['class'])->getDefaults();
            }
        }

        $setters = $this->getSetters();
        $accessors = $this->getAccessors();
        foreach ($this->getFields() as $field => $type)
        {
            $default = is_array($type) ? [] : null;

            if (array_key_exists($field, $defaults))
            {
                $default = $defaults[$field];
            }

            if (isset($setters[$field]))
            {
                $filter = $setters[$field];

                //Applying filter to default value
                try
                {
                    $default = call_user_func($filter, $default);
                }
                catch (\ErrorException $exception)
                {
                    $default = null;
                }
            }

            if (isset($accessors[$field]))
            {
                $accessor = $accessors[$field];

                $options = null;
                if (is_array($accessor))
                {
                    list($accessor, $options) = $accessor;
                }

                if ($accessor != ODM::CMP_ONE)
                {
                    //Not an accessor but composited class
                    $accessor = new $accessor($default, null, $options);

                    if ($accessor instanceof ODMAccessor)
                    {
                        $default = $accessor->defaultValue();
                    }
                }
            }

            $defaults[$field] = $default;
        }

        return $defaults;
    }

    /**
     * Get all document compositions.
     *
     * @return array
     */
    public function getCompositions()
    {
        $fields = $this->getFields();

        $compositions = [];
        foreach ($fields as $field => $type)
        {
            if (is_string($type) && $foreignDocument = $this->builder->documentSchema($type))
            {
                $compositions[$field] = [
                    'type'            => ODM::CMP_ONE,
                    'class'           => $type,
                    'classDefinition' => $foreignDocument->classDefinition()
                ];
                continue;
            }

            //Class name should be stored in first array argument
            if (!is_array($type))
            {
                try
                {
                    if (class_exists($type))
                    {
                        $reflection = new \ReflectionClass($type);
                        if ($reflection->implementsInterface(SchemaBuilder::COMPOSITABLE))
                        {
                            $compositions[$field] = [
                                'type'            => ODM::CMP_ONE,
                                'class'           => $type,
                                'classDefinition' => $type
                            ];
                        }
                    }
                }
                catch (\Exception $exception)
                {
                    //Ignoring
                }

                continue;
            }

            $class = $type[0];
            if (is_string($class) && $foreignDocument = $this->builder->documentSchema($class))
            {
                //Rename type to represent real model name
                $compositions[$field] = [
                    'type'            => ODM::CMP_MANY,
                    'class'           => $class,
                    'classDefinition' => $foreignDocument->classDefinition()
                ];
            }
        }

        return $compositions;
    }

    /**
     * Get field references to external documents (aggregations).
     *
     * @return array
     * @throws ODMException
     */
    public function getAggregations()
    {
        $schema = $this->getSchema();

        $aggregations = [];
        foreach ($schema as $field => $options)
        {
            if (
                !is_array($options)
                || (
                    !array_key_exists(Document::MANY, $options)
                    && !array_key_exists(Document::ONE, $options)
                )
            )
            {
                //Not aggregation
                continue;
            }

            //Class to be aggregated
            $class = isset($options[Document::MANY])
                ? $options[Document::MANY]
                : $options[Document::ONE];

            if (!$externalDocument = $this->builder->documentSchema($class))
            {
                throw new ODMException(
                    "Unable to build aggregation {$this->class}.{$field}, "
                    . "no such document '{$class}'."
                );
            }

            if (!$externalDocument->getCollection())
            {
                throw new ODMException(
                    "Unable to build aggregation {$this->class}.{$field}, "
                    . "document '{$class}' does not have any collection."
                );
            }

            $aggregations[$field] = [
                'type'       => isset($options[Document::ONE]) ? Document::ONE : Document::MANY,
                'class'      => $class,
                'collection' => $externalDocument->getCollection(),
                'database'   => $externalDocument->getDatabase(),
                'query'      => array_pop($options)
            ];
        }

        return $aggregations;
    }

    /**
     * Get all declared document indexes.
     *
     * @return array
     */
    public function getIndexes()
    {
        if (!$this->getCollection())
        {
            return [];
        }

        return $this->property('indexes', true);
    }

    /**
     * Get all possible children (sub models) for this document.
     *
     * Example:
     * class A
     * class B extends A
     * class D extends A
     * class E extends D
     *
     * result: B,D,E
     *
     * @return array
     */
    public function getChildren()
    {
        $result = [];
        foreach ($this->builder->getDocumentSchemas() as $schema)
        {
            if ($schema->reflection->isSubclassOf($this->class))
            {
                $result[] = $schema->reflection->getName();
            }
        }

        return $result;
    }

    /**
     * Class name of first document used to create current model. Basically this is first class in
     * extending chain.
     *
     * @param bool $hasCollection Only document with defined collection.
     * @return string
     */
    public function primaryClass($hasCollection = false)
    {
        $reflection = $this->reflection;

        while ($reflection->getParentClass()->getName() != self::BASE_CLASS)
        {
            if (
                $hasCollection
                && !$this->builder->documentSchema(
                    $reflection->getParentClass()->getName()
                )->getCollection()
            )
            {
                break;
            }

            $reflection = $reflection->getParentClass();
        }

        return $reflection->getName();
    }

    /**
     * Document schema of first document used to create current model. Basically this is first class
     * in extending chain.
     *
     * @param bool $hasCollection Only document with defined collection.
     * @return DocumentSchema
     */
    public function primaryDocument($hasCollection = false)
    {
        return $this->builder->documentSchema($this->primaryClass($hasCollection));
    }

    /**
     * How to define valid class declaration based on set of fields fetched from collection, default
     * way is "DEFINITION_FIELDS", this method will define set of unique fields existed in every class.
     * Second option is to define method to resolve class declaration "DEFINITION_LOGICAL".
     *
     * @return mixed
     * @throws ODMException
     */
    public function classDefinition()
    {
        $classes = [];
        foreach ($this->builder->getDocumentSchemas() as $documentSchema)
        {
            if (
                $documentSchema->reflection->isSubclassOf($this->class)
                && !$documentSchema->reflection->isAbstract()
            )
            {
                $classes[] = $documentSchema->class;
            }
        }

        $classes[] = $this->class;

        if (count($classes) == 1)
        {
            //No sub classes
            return $this->class;
        }

        if ($this->reflection->getConstant('DEFINITION') == Document::DEFINITION_LOGICAL)
        {
            return [
                'type'    => Document::DEFINITION_LOGICAL,
                'options' => [$this->primaryClass(), 'defineClass']
            ];
        }
        else
        {
            $defineClass = [
                'type'    => Document::DEFINITION_FIELDS,
                'options' => []
            ];

            /**
             * We should order classes by inheritance levels. Primary model should go last.
             */
            uasort($classes, function ($classA, $classB)
            {
                //TODO: Sorting is not stable sometimes, check in cases where many children found
                //TODO: bug found at 27/04/2015
                return (new \ReflectionClass($classA))->isSubclassOf($classB) ? 1 : -1;
            });

            //Populating model fields
            $classes = array_flip($classes);

            //Array of fields can be found in any model
            $commonFields = [];

            foreach ($classes as $class => &$fields)
            {
                $fields = $this->builder->documentSchema($class)->getFields();

                if (empty($fields))
                {
                    return null;
                }

                if (empty($commonFields))
                {
                    $commonFields = $fields;
                }
                else
                {
                    foreach ($fields as $field => $type)
                    {
                        if (isset($commonFields[$field]))
                        {
                            unset($fields[$field]);
                        }
                        else
                        {
                            //Remove aey for all inherited models
                            $commonFields[$field] = true;
                        }
                    }
                }

                if (!$fields)
                {
                    throw new ODMException(
                        "Unable to use class detection (property based) for document '{$class}', "
                        . "no unique fields found."
                    );
                }

                reset($fields);
                $defineClass['options'][$class] = key($fields);
                unset($fields);
            }
        }

        //Back order
        $defineClass['options'] = array_reverse($defineClass['options']);

        return $defineClass;
    }
}