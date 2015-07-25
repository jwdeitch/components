<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Components\DBAL;

use Spiral\Core\Traits;

class Parameter implements ParameterInterface
{
    /**
     * Value parameter representing to query builders. Can be an array.
     *
     * @var mixed
     */
    protected $value = null;

    /**
     * New instance on dbal query Parameter. Parameter will be automatically constructed to represent
     * complex values, such as arrays.
     *
     * @param mixed $value Binded value.
     */
    public function __construct($value)
    {
        $this->value = $value;
    }

    /**
     * Get parameter value, this method will be called by driver at moment of sending parameters to
     * PDO. Method is required as some parameters contain array value which should be presented as
     * multiple query bindings.
     *
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * Update parameter internal value, this method can be used for late binding.
     *
     * @param mixed $value
     */
    public function setValue($value)
    {
        $this->value = $value;
    }

    /**
     * Get or render SQL statement.
     *
     * @param QueryCompiler $compiler
     * @return string
     */
    public function sqlStatement(QueryCompiler $compiler = null)
    {
        if (is_array($this->value))
        {
            return '(' . trim(str_repeat('?, ', count($this->value)), ', ') . ')';
        }

        return '?';
    }

    /**
     * __toString
     *
     * @return string
     */
    public function __toString()
    {
        return $this->sqlStatement();
    }

    /**
     * Simplified way to dump information.
     *
     * @return object
     */
    public function __debugInfo()
    {
        return (object)[
            'statement' => $this->sqlStatement(),
            'value'     => $this->value
        ];
    }
}