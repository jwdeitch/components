<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Reactor\ClassElements;

use Spiral\Reactor\ClassElements\MethodElements\ParameterElement;
use Spiral\Reactor\AbstractElement;

class MethodElement extends AbstractElement
{
    /**
     * Method access level (private, protected and public).
     *
     * @var string
     */
    protected $access = self::ACCESS_PUBLIC;

    /**
     * Indicates the method which can be accessed statically.
     *
     * @var bool
     */
    protected $static = false;

    /**
     * List of required and optional method parameters.
     *
     * @var ParameterElement[]
     */
    protected $parameters = [];

    /**
     * Source code lines (optional).
     *
     * @var array
     */
    protected $source = [];

    /**
     * Method access level (private, protected and public).
     *
     * @return string
     */
    public function getAccess()
    {
        return $this->access;
    }

    /**
     * Set access level.
     *
     * @param string $access Public by default.
     * @return $this
     */
    public function setAccess($access = self::ACCESS_PUBLIC)
    {
        $this->access = $access;

        return $this;
    }

    /**
     * Indicates that method can be accessed statically.
     *
     * @return bool
     */
    public function isStatic()
    {
        return $this->static;
    }

    /**
     * Mark method as static/non static.
     *
     * @param bool $static True if property is static.
     * @return $this
     */
    public function setStatic($static)
    {
        $this->static = (bool)$static;

        return $this;
    }

    /**
     * Get all the methods parameters declarations.
     *
     * @return ParameterElement[]
     */
    public function getParameters()
    {
        return $this->parameters;
    }

    /**
     * Check if method has parameter by it's name.
     *
     * @param string $name Parameter name.
     * @return bool
     */
    public function hasParameter($name)
    {
        return array_key_exists($name, $this->parameters);
    }

    /**
     * Remove parameter from methods declaration by it's name.
     *
     * @param string $name
     */
    public function removeParameter($name)
    {
        unset($this->parameters[$name]);
    }

    /**
     * Get/create parameter. Parameter will automatically be created during the methods first call.
     *
     * @param string $name Parameter name.
     * @param string $type DocComment to set or replace.
     * @return ParameterElement
     */
    public function parameter($name, $type = '')
    {
        if (!isset($this->parameters[$name]))
        {
            $this->parameters[$name] = new ParameterElement($name);
        }

        if ($type && !in_array($type, $this->docComment))
        {
            $this->docComment[] = '@param ' . $type . ' $' . $name;
        }

        return $this->parameters[$name];
    }

    /**
     * Get method source code lines.
     *
     * @return array
     */
    public function getSource()
    {
        return $this->source;
    }

    /**
     * Mount source code into method. Indents will be modified to follow method and class tabulation.
     * If the source is provided as array of lines - no indents will be modified.
     *
     * @param string|array $source
     * @param bool         $append
     * @return $this
     */
    public function setSource($source, $append = false)
    {
        if (!$append)
        {
            $this->source = [];
        }

        if (is_array($source))
        {
            $this->source = array_merge($this->source, $source);
        }
        else
        {
            $source = preg_replace('/[\n\r]+/', "\n", $source);
            $sourceLines = explode("\n", $source);
            $indentLevel = 0;

            foreach ($sourceLines as $line)
            {
                //Cutting start spaces
                $line = trim($line);

                if (strpos($line, '}') !== false)
                {
                    $indentLevel--;
                }

                $this->source[] = $this->indent($line, $indentLevel);
                if (strpos($line, '{') !== false)
                {
                    $indentLevel++;
                }
            }
        }

        return $this;
    }

    /**
     * Replace strings in all doc comment lines or other names. This is particularly useful when you
     * want to build a virtual documentation class based on another declaration. Parameter types
     * will be replaced as well.
     *
     * @param string|array $search  String to find.
     * @param string|array $replace String to replace for.
     * @return $this
     */
    public function replaceComments($search, $replace)
    {
        parent::replaceComments($search, $replace);

        foreach ($this->parameters as $parameter)
        {
            $parameter->setType(str_replace($search, $replace, $parameter->getType()));
        }

        return $this;
    }

    /**
     * Render element declaration. The method should be declared in RElement childs classes and then
     * perform the operation for rendering a specific type of content. Method will be rendered with
     * it's code (optional), static keyword, access level and it's parameters.
     *
     * @param int $indentLevel Tabulation level.
     * @param int $position    Element position.
     * @return string
     */
    public function render($indentLevel = 0, $position = 0)
    {
        $result = [
            !$position ? ltrim($this->renderComment($indentLevel)) : $this->renderComment($indentLevel)
        ];

        //Parameters
        $parameters = [];
        foreach ($this->parameters as $parameter)
        {
            $parameters[] = $parameter->render();
        }

        $declaration = $this->access . ' ' . ($this->static ? 'static ' : '');
        $declaration .= 'function ' . $this->name . '(' . join(', ', $parameters) . ')';

        $result[] = $declaration;
        $result[] = '{';

        $beginning = true;
        foreach ($this->source as $line)
        {
            if (trim($line))
            {
                $beginning = false;
            }

            if ($beginning && !trim($line))
            {
                //Common problem with console creators, they will add blank line at top for code
                continue;
            }

            $result[] = $this->indent($line, $indentLevel);
        }

        $result[] = '}';

        return $this->join($result, $indentLevel);
    }

    /**
     * Clone schema from existing method. The method code will not be copied and has to be mounted
     * manually.
     *
     * @param \ReflectionMethod $method
     */
    public function cloneSchema(\ReflectionMethod $method)
    {
        $this->setComment($method->getDocComment());
        $this->static = $method->isStatic();

        if ($method->isPrivate())
        {
            $this->setAccess(self::ACCESS_PRIVATE);
        }
        elseif ($method->isProtected())
        {
            $this->setAccess(self::ACCESS_PROTECTED);
        }

        foreach ($method->getParameters() as $parameterReflection)
        {
            $parameter = $this->parameter($parameterReflection->getName());
            if ($parameterReflection->isOptional())
            {
                $parameter->setOptional(true, $parameterReflection->getDefaultValue());
            }

            if ($parameterReflection->isArray())
            {
                $parameter->setType('array');
            }

            if ($parameterReflection->getClass())
            {
                $parameter->setType($parameterReflection->getClass()->getName());
            }

            $parameter->setPBR($parameterReflection->isPassedByReference());
        }
    }
}