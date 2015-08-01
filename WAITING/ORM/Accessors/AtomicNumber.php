<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\ORM\Accessors;

use Spiral\Database\Driver;
use Spiral\Database\SqlExpression;
use Spiral\ORM\Model;
use Spiral\ORM\ModelAccessorInterface;

class AtomicNumber implements ModelAccessorInterface
{
    /**
     * Parent active record.
     *
     * @var Model
     */
    protected $parent = null;

    /**
     * Original value.
     *
     * @var float|int
     */
    protected $original = null;

    /**
     * Numeric value.
     *
     * @var float|int
     */
    protected $value = null;

    /**
     * Current value change.
     *
     * @var float|int
     */
    protected $delta = 0;

    /**
     * Accessors can be used to mock different model values using "representative" class, like
     * DateTime for timestamps.
     *
     * @param mixed  $data    Data to mock.
     * @param object $parent
     * @param mixed  $options Implementation specific options.
     */
    public function __construct($data = null, $parent = null, $options = null)
    {
        $this->original = $this->value = $data;
        $this->parent = $parent;
    }

    /**
     * Embedding to another parent.
     *
     * @param Model $parent
     * @return mixed
     */
    public function embed($parent)
    {
        $accessor = clone $this;
        $accessor->parent = $parent;

        return $accessor;
    }

    /**
     * Update accessor mocked data.
     *
     * @param mixed $data
     */
    public function setData($data)
    {
        $this->original = $this->value = $data;
        $this->delta = 0;
    }

    /**
     * Serialize accessor mocked value. This is legacy name and used like that to be compatible with
     * ORM and ODM engines.
     *
     * @return mixed
     */
    public function serializeData()
    {
        return $this->value;
    }

    /**
     * Check if object has any update.
     *
     * @return bool
     */
    public function hasUpdates()
    {
        return $this->value !== $this->original;
    }

    /**
     * Mark object as successfully updated and flush all existed atomic operations and updates.
     */
    public function flushUpdates()
    {
        $this->original = $this->value;
        $this->delta = 0;
    }

    /**
     * Get new field value to be send to database.
     *
     * @param string $field Name of field where model/accessor stored into.
     * @return mixed
     */
    public function compileUpdates($field = '')
    {
        if ($this->delta === 0)
        {
            return $this->value;
        }

        $sign = $this->delta > 0 ? '+' : '-';

        return new SqlExpression("{$field} {$sign} " . abs($this->delta));
    }

    /**
     * Accessor default value specific to driver.
     *
     * @param Driver $driver
     * @return mixed
     */
    public function defaultValue(Driver $driver)
    {
        return $this->serializeData();
    }

    /**
     * (PHP 5 > 5.4.0)
     * Specify data which should be serialized to JSON.
     *
     * @link http://php.net/manual/en/jsonserializable.jsonserialize.php
     * @return mixed
     */
    public function jsonSerialize()
    {
        return $this->serializeData();
    }

    /**
     * Increment numeric value (alias for inc).
     *
     * @param float|int $delta
     * @return $this
     */
    public function inc($delta = 1)
    {
        $this->value += $delta;
        $this->delta += $delta;

        return $this;
    }

    /**
     * Increment numeric value (alias for inc).
     *
     * @param float|int $delta
     * @return $this
     */
    public function add($delta = 1)
    {
        $this->value += $delta;
        $this->delta += $delta;

        return $this;
    }

    /**
     * Decrement numeric value.
     *
     * @param float|int $delta
     * @return $this
     */
    public function dec($delta = 1)
    {
        $this->value -= $delta;
        $this->delta -= $delta;

        return $this;
    }

    /**
     * Converting to string.
     *
     * @return string
     */
    public function __toString()
    {
        return (string)$this->value;
    }

    /**
     * Simplified way to dump information.
     *
     * @return object
     */
    public function __debugInfo()
    {
        return (object)[
            'value' => $this->value,
            'delta' => $this->delta
        ];
    }
}