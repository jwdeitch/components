<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Models\Inspector;

use Spiral\Core\Component;
use Spiral\Models\Schemas\EntitySchema;

class EntityInspection extends Component
{
    /**
     * Model schema.
     *
     * @var EntitySchema
     */
    protected $schema = null;

    /**
     * Field inspections.
     *
     * @var FieldInspection[]
     */
    protected $fields = [];

    /**
     * New model inspection instance.
     *
     * @param EntitySchema $schema
     */
    public function __construct(EntitySchema $schema)
    {
        $this->schema = $schema;
    }

    /**
     * Get associated model schema.
     *
     * @return EntitySchema
     */
    public function getSchema()
    {
        return $this->schema;
    }

    /**
     * Analyze model fields and etc.
     *
     * @param array $blacklist List of blacklisted keywords indicates that field has to be hidden
     *                         from publicFields() result.
     */
    public function inspect(array $blacklist)
    {
        $this->fields = [];

        foreach ($this->schema->getFields() as $field => $type)
        {
            $this->fields[$field] = $this->inspectField($field, $blacklist);
        }
    }

    /**
     * Get field inspection.
     *
     * @param string $field
     * @param array  $blacklist List of blacklisted keywords indicates that field has to be hidden
     *                          from publicFields() result.
     * @return FieldInspection
     */
    protected function inspectField($field, array $blacklist)
    {
        $filters = $this->schema->getSetters() + $this->schema->getAccessors();
        $fillable = true;

        if (in_array($field, $this->schema->getSecured()))
        {
            $fillable = false;
        }

        if ($this->schema->getFillable() != [])
        {
            $fillable = in_array($field, $this->schema->getFillable());
        }

        $blacklisted = false;
        foreach ($blacklist as $keyword)
        {
            if (stripos($field, $keyword) !== false)
            {
                $blacklisted = true;
                break;
            }
        }

        return new FieldInspection(
            $field,
            $this->schema->getFields()[$field],
            $fillable,
            in_array($field, $this->schema->getHidden()),
            isset($filters[$field]),
            array_key_exists($field, $this->schema->getValidates()),
            $blacklisted
        );
    }

    /**
     * Get model fields inspections.
     *
     * @return FieldInspection[]
     */
    public function getFields()
    {
        return $this->fields;
    }

    /**
     * Count fields.
     *
     * @return int
     */
    public function countFields()
    {
        return count($this->fields);
    }

    /**
     * Count of fields passed required level.
     *
     * @param int $level
     * @return int
     */
    public function countPassed($level = 4)
    {
        $count = 0;
        foreach ($this->fields as $field)
        {
            if ($field->safetyLevel() >= $level)
            {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get model safety level (based on minimal value).
     *
     * @return int|mixed
     */
    public function safetyLevel()
    {
        $safetyLevel = 5;
        foreach ($this->fields as $field)
        {
            $safetyLevel = min($field->safetyLevel(), $safetyLevel);
        }

        return $safetyLevel;
    }

    /**
     * Get detailed explanations of detected problems.
     *
     * @return array
     */
    public function getWarnings()
    {
        $result = [];

        foreach ($this->fields as $field)
        {
            if ($warnings = $field->getWarnings())
            {
                $result[$field->getName()] = $warnings;
            }
        }

        return $result;
    }
}