<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Core\Container;

interface InjectorInterface
{
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
    public function createInjection(\ReflectionClass $class, \ReflectionParameter $parameter);
}