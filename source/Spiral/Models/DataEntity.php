<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */
namespace Spiral\Models;

use Doctrine\Common\Inflector\Inflector;
use Spiral\Core\Component;
use Spiral\Core\Exceptions\MissingContainerException;
use Spiral\Models\Exceptions\AccessorExceptionInterface;
use Spiral\Models\Exceptions\EntityException;
use Spiral\Models\Reflections\ReflectionEntity;
use Spiral\Validation\Exceptions\ValidationException;
use Spiral\Validation\Traits\ValidatorTrait;

/**
 * DataEntity in spiral used to represent basic data set with validation rules, filters and
 * accessors. Most of spiral models (ORM and ODM, HttpFilters) will extend data entity.  In addition
 * it creates magic set of getters and setters for every field name (see validator trait) in model.
 */
abstract class DataEntity extends Component implements
    EntityInterface,
    \JsonSerializable,
    \IteratorAggregate,
    \ArrayAccess
{
    /**
     * Every entity can be validated, in addition validation trait will load Translator and Event
     * traits.
     */
    use ValidatorTrait;

    /**
     * Field format declares how entity must process magic setters and getters. Available values:
     * camelCase, tableize.
     */
    const FIELD_FORMAT = 'camelCase';

    /**
     * Every entity might have set of traits which can be initiated manually or at moment of
     * construction model instance. Array will store already initiated model names.
     *
     * @var array
     */
    private static $initiated = [];

    /**
     * Indicates that model data have been validated since last change.
     *
     * @var bool
     */
    private $validated = true;

    /**
     * List of fields must be hidden from publicFields() method.
     *
     * @see publicFields()
     * @var array
     */
    protected $hidden = [];

    /**
     * Set of fields allowed to be filled using setFields() method.
     *
     * @see setFields()
     * @var array
     */
    protected $fillable = [];

    /**
     * List of fields not allowed to be filled by setFields() method. By default no fields can be
     * set. Replace with and empty array to allow all fields.
     *
     * @see setFields()
     * @var array|string
     */
    protected $secured = '*';

    /**
     * @see setField()
     * @var array
     */
    protected $setters = [];

    /**
     * @see getField()
     * @var array
     */
    protected $getters = [];

    /**
     * Accessor used to mock field data and filter every request thought itself.
     *
     * @see getField()
     * @see setField()
     * @var array
     */
    protected $accessors = [];

    /**
     * Routes user function in format of (get|set)FieldName into (get|set)Field(fieldName, value).
     *
     * @see getFeld()
     * @see setField()
     * @param string $method
     * @param array  $arguments
     * @return $this|mixed|null|AccessorInterface
     * @throws EntityException
     */
    public function __call($method, array $arguments)
    {
        if (method_exists($this, $method)) {
            throw new EntityException(
                "Method name '{$method}' is ambiguous and can not be used as magic setter."
            );
        }

        if (strlen($method) <= 3) {
            //Get/set needs exactly 0-1 argument
            throw new EntityException("Undefined method {$method}.");
        }

        $field = substr($method, 3);

        switch (static::FIELD_FORMAT) {
            case 'camelCase':
                $field = Inflector::camelize($field);
                break;
            case 'tableize':
                $field = Inflector::tableize($field);
                break;
            default:
                throw new EntityException(
                    "Undefined field format '" . static::FIELD_FORMAT . "'."
                );
        }

        switch (substr($method, 0, 3)) {
            case 'get':
                return $this->getField($field);
            case 'set':
                if (count($arguments) === 1) {
                    $this->setField($field, $arguments[0]);

                    //setFieldA($a)->setFieldB($b)
                    return $this;
                }
        }

        throw new EntityException("Undefined method {$method}.");
    }

    /**
     * {@inheritdoc}
     *
     * @see   $fillable
     * @see   $secured
     * @see   isFillable()
     * @param array|\Traversable $fields
     * @param bool               $all Fill all fields including non fillable.
     * @return $this
     * @throws AccessorExceptionInterface
     * @event setFields($fields)
     */
    public function setFields($fields = [], $all = false)
    {
        if (!is_array($fields) && !$fields instanceof \Traversable) {
            return $this;
        }

        foreach ($this->fire('setFields', $fields) as $name => $value) {
            if ($all || $this->isFillable($name)) {
                $this->setField($name, $value, true);
            }
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * Every getter and accessor will be applied/constructed if filter argument set to true.
     *
     * @param bool $filter
     * @throws AccessorExceptionInterface
     */
    public function getFields($filter = true)
    {
        $result = [];
        foreach ($this->fields as $name => $field) {
            $result[$name] = $this->getField($name, null, $filter);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function hasField($name)
    {
        return array_key_exists($name, $this->fields);
    }

    /**
     * {@inheritdoc}
     *
     * @param bool $filter If false, associated field setter or accessor will be ignored.
     * @throws AccessorExceptionInterface
     */
    public function setField($name, $value, $filter = true)
    {
        if ($value instanceof AccessorInterface) {
            $this->fields[$name] = $value->embed($this);

            return;
        }

        $this->validated = false;

        if (!$filter) {
            $this->fields[$name] = $value;

            return;
        }

        if (!empty($accessor = $this->getMutator($name, 'accessor'))) {
            $field = $this->fields[$name];
            if (empty($field) || !($field instanceof AccessorInterface)) {
                $this->fields[$name] = $field = $this->createAccessor($accessor, $field);
            }

            $field->setValue($value);

            return;
        }

        if (!empty($setter = $this->getMutator($name, 'setter'))) {
            try {
                $this->fields[$name] = call_user_func($setter, $value);
            } catch (\ErrorException $exception) {
                $this->fields[$name] = call_user_func($setter, null);
            }
        } else {
            $this->fields[$name] = $value;
        }
    }

    /**
     * {@inheritdoc}
     *
     * @param bool $filter If false, associated field getter will be ignored.
     * @throws AccessorExceptionInterface
     */
    public function getField($name, $default = null, $filter = true)
    {
        $value = $this->hasField($name) ? $this->fields[$name] : $default;

        if ($value instanceof AccessorInterface) {
            return $value;
        }

        if (!empty($accessor = $this->getMutator($name, 'accessor'))) {
            return $this->fields[$name] = $this->createAccessor($accessor, $value);
        }

        if ($filter && !empty($getter = $this->getMutator($name, 'getter'))) {
            try {
                return call_user_func($getter, $value);
            } catch (\ErrorException $exception) {
                //Trying to filter null value, every filter must support it
                return call_user_func($getter, null);
            }
        }

        return $value;
    }

    /**
     * @param mixed $offset
     * @return bool
     */
    public function __isset($offset)
    {
        return $this->hasField($offset);
    }

    /**
     * @param mixed $offset
     * @return mixed
     * @throws AccessorExceptionInterface
     */
    public function __get($offset)
    {
        return $this->getField($offset);
    }

    /**
     * @param mixed $offset
     * @param mixed $value
     * @throws AccessorExceptionInterface
     */
    public function __set($offset, $value)
    {
        $this->setField($offset, $value);
    }

    /**
     * @param mixed $offset
     */
    public function __unset($offset)
    {
        $this->validated = false;
        unset($this->fields[$offset]);
    }

    /**
     * {@inheritdoc}
     */
    public function offsetExists($offset)
    {
        return $this->__isset($offset);
    }

    /**
     * {@inheritdoc}
     *
     * @throws AccessorExceptionInterface
     */
    public function offsetGet($offset)
    {
        return $this->getField($offset);
    }

    /**
     * {@inheritdoc}
     *
     * @throws AccessorExceptionInterface
     */
    public function offsetSet($offset, $value)
    {
        $this->setField($offset, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function offsetUnset($offset)
    {
        $this->__unset($offset);
    }

    /**
     * {@inheritdoc}
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->getFields());
    }

    /**
     * Get model fields but exclude hidden one.
     *
     * @see   $hidden
     * @see   getFields()
     * @return array
     * @throws AccessorExceptionInterface
     * @event publicFields($publicFields)
     */
    public function publicFields()
    {
        $fields = $this->getFields();
        foreach ($this->hidden as $secured) {
            unset($fields[$secured]);
        }

        return $this->fire('publicFields', $fields);
    }

    /**
     * Serialize entity data into plain array.
     *
     * @return array
     * @throws AccessorExceptionInterface
     */
    public function serializeData()
    {
        $result = $this->fields;
        foreach ($result as $field => $value) {
            if ($value instanceof AccessorInterface) {
                $result[$field] = $value->serializeData();
            }
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     *
     * @event jsonSerialize($publicFields)
     */
    public function jsonSerialize()
    {
        return $this->fire('jsonSerialize', $this->publicFields());
    }

    /**
     * Entity must re-validate data.
     *
     * @param bool $soft Do not invalidate entity accessors.
     * @return $this
     */
    public function invalidate($soft = false)
    {
        $this->validated = false;

        if ($soft) {
            return $this;
        }

        //Invalidating all compositions
        foreach ($this->fields as $field => $value) {
            //Let's force composition construction
            $accessor = $this->getField($field);
            if ($accessor instanceof self) {
                $accessor->invalidate($soft);
            }
        }

        return $this;
    }

    /**
     * @return Object
     */
    public function __debugInfo()
    {
        return (object)[
            'fields' => $this->getFields(),
            'errors' => $this->getErrors()
        ];
    }

    /**
     * Validate model fields.
     *
     * @param bool $reset
     * @return bool
     * @throws ValidationException
     * @throws MissingContainerException
     * @event validation()
     * @event validated($errors)
     */
    protected function validate($reset = false)
    {
        if (empty($this->validates)) {
            $this->validated = true;
        } elseif (!$this->validated || $reset) {
            $this->fire('validation');

            $this->errors = $this->validator()->getErrors();

            //We just validated our model
            $this->validated = true;

            //Cleaning memory
            $this->validator->setData([]);

            $this->errors = (array)$this->fire('validated', $this->errors);
        }

        return empty($this->errors);
    }

    /**
     * Check if field can be set using setFields() method.
     *
     * @see   setField()
     * @see   $fillable
     * @see   $secured
     * @param string $field
     * @return bool
     */
    protected function isFillable($field)
    {
        if (!empty($this->fillable)) {
            return in_array($field, $this->fillable);
        }

        if ($this->secured === '*') {
            return false;
        }

        return !in_array($field, $this->secured);
    }

    /**
     * Check and return name of mutator (getter, setter, accessor) associated with specific field.
     *
     * @param string $field
     * @param string $mutator Mutator type (setter, getter, accessor).
     * @return mixed|null
     * @throws EntityException
     */
    protected function getMutator($field, $mutator)
    {
        //We do support 3 mutators: getter, setter and accessor, all of them can be
        //referenced to valid field name by adding "s" at the end
        $mutator = $mutator . 's';

        if (isset($this->{$mutator}[$field])) {
            return $this->{$mutator}[$field];
        }

        return null;
    }

    /**
     * Create instance of field accessor.
     *
     * @param string $accessor
     * @param mixed  $value
     * @return AccessorInterface
     * @throws AccessorExceptionInterface
     */
    protected function createAccessor($accessor, $value)
    {
        return new $accessor($value, $this);
    }

    /**
     * Destruct data entity.
     */
    public function __destruct()
    {
        $this->fields = [];
        $this->validator = null;
    }

    /**
     * Method used while entity static analysis to describe model related property using even
     * dispatcher and associated model traits.
     *
     * @param ReflectionEntity $schema
     * @param string           $property
     * @param mixed            $value
     * @return mixed Returns filtered value.
     * @event describe($property, $value, EntitySchema $schema): $value
     */
    public static function describeProperty(ReflectionEntity $schema, $property, $value)
    {
        static::initialize(true);

        //Clarifying property value using traits or other listeners
        return static::events()->fire(
            'describe',
            compact('property', 'value', 'schema')
        )['value'];
    }

    /**
     * Clear initiated objects list.
     */
    public static function resetInitiated()
    {
        self::$initiated = [];
    }

    /**
     * Initiate associated model traits. System will look for static method with "init" prefix.
     *
     * @param bool $analysis Must be set to true while static analysis.
     */
    protected static function initialize($analysis = false)
    {
        if (isset(self::$initiated[$class = static::class]) && !$analysis) {
            return;
        }

        foreach (get_class_methods($class) as $method) {
            if (substr($method, 0, 4) === 'init' && $method != 'initialize') {
                forward_static_call(['static', $method], $analysis);
            }
        }

        self::$initiated[$class] = true;
    }
}