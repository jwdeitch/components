<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Database\Injections;

use Spiral\Database\Entities\QueryCompiler;
use Spiral\Database\ParameterInterface;

/**
 * Default implementation of ParameterInterface, provides ability to mock value or array of values
 * and automatically create valid query placeholder at moment of query compilation (? vs (?, ?, ?)).
 */
class Parameter implements ParameterInterface
{
    /**
     * Mocked value or array of values.
     *
     * @var mixed|array
     */
    private $value = null;

    /**
     * @param mixed $value
     */
    public function __construct($value)
    {
        $this->value = $value;
    }

    /**
     * Change mocked parameter value.
     *
     * @param mixed $value
     */
    public function setValue($value)
    {
        $this->value = $value;
    }

    /**
     * {@inheritdoc}
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * {@inheritdoc}
     */
    public function sqlStatement(QueryCompiler $compiler = null)
    {
        if (is_array($this->value))
        {
            //Array were mocked
            return '(' . trim(str_repeat('?, ', count($this->value)), ', ') . ')';
        }

        return '?';
    }

    /**
     * {@inheritdoc}
     */
    public function __toString()
    {
        return $this->sqlStatement();
    }

    /**
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