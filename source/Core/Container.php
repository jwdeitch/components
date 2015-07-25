<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Core;

use ReflectionParameter;
use Spiral\Core\Container\ContainerException;

class Container extends Component implements \ArrayAccess
{
    /**
     * Code to throw when instance can not be constructed.
     */
    const NON_INSTANTIABLE_ERROR = 7;

    /**
     * Default container used in make spiral components and called when getInstance() or make() methods
     * of components invoked. Technically this is only one real singleton.
     *
     * @var Container
     */
    protected static $instance = null;

    /**
     * IoC bindings. Binding can include interface - class aliases, closures, singleton closures
     * and already constructed components stored as instances. Binding can be added using
     * Container::bind() or Container::bindSingleton() methods, every existed binding can be defined
     * or redefined at any moment of application flow.
     *
     * Instance or class name can be also binded to alias, this technique used for all spiral core
     * components and can simplify development. Spiral additionally provides way to create DI without
     * binding, it can be done by using real class or model name, or via ControllableInjection interface.
     *
     * @invisible
     * @var array
     */
    protected $bindings = [];

    /**
     * Get default container used in make spiral components and called when getInstance() or make()
     * methods of components invoked. Technically this is only one real singleton. SetInstance method
     * is not presented (i feel this way), but possibly can be added in future.
     *
     * @return $this
     */
    public static function getInstance()
    {
        return self::$instance;
    }

    /**
     * Resolve class instance using IoC container. Class can be requested using it's own name, alias
     * binding, singleton binding, closure function, closure function with singleton resolution, or
     * via InjectableInterface interface. To add binding use Container::bind() or Container::bindSingleton()
     * methods.
     *
     * This method widely used inside spiral core to resolve adapters, handlers and databases.
     *
     * @param string              $alias            Class/interface name or binded alias should be
     *                                              resolved to instance.
     * @param array               $parameters       Parameters to be mapped to class constructor or
     *                                              forwarded to closure.
     * @param ReflectionParameter $contextParameter Parameter were used to declare DI.
     * @param bool                $ignoreII         If true, core will ignore InjectableInterface and
     *                                              resolve class as usual.
     * @return mixed|null|object
     * @throws SpiralException
     * @throws ContainerException
     */
    public function get(
        $alias,
        $parameters = [],
        ReflectionParameter $contextParameter = null,
        $ignoreII = false
    )
    {
        if ($alias == __CLASS__)
        {
            return self::$instance;
        }

        if (!isset($this->bindings[$alias]))
        {
            $reflector = new \ReflectionClass($alias);
            if (!$ignoreII && $injectionManager = $reflector->getConstant('INJECTION_MANAGER'))
            {
                //Apparently checking constant is faster than checking interface
                return call_user_func(
                    [$this->get($injectionManager), 'resolveInjection'],
                    $reflector,
                    $contextParameter,
                    $this
                );
            }
            elseif ($reflector->isInstantiable())
            {
                if ($constructor = $reflector->getConstructor())
                {
                    $instance = $reflector->newInstanceArgs(
                        $this->resolveArguments($constructor, $parameters)
                    );
                }
                else
                {
                    $instance = $reflector->newInstance();
                }

                //Component declared SINGLETON constant, binding as constant value and class name.
                if ($singleton = $reflector->getConstant('SINGLETON'))
                {
                    $this->bindings[$reflector->getName()] = $this->bindings[$singleton] = $instance;
                }

                return $instance;
            }

            throw new SpiralException(
                "Class '{$alias}' can not be constructed.",
                self::NON_INSTANTIABLE_ERROR
            );
        }

        if (is_object($binding = $this->bindings[$alias]))
        {
            return $binding;
        }

        if (is_string($binding))
        {
            $instance = $this->get($binding, $parameters, $contextParameter, $ignoreII);
            if ($instance instanceof Component && $instance::SINGLETON)
            {
                //To prevent double binding
                $this->bindings[$binding] = $this->bindings[get_class($instance)] = $instance;
            }

            return $instance;
        }

        if (is_array($binding))
        {
            if (is_string($binding[0]))
            {
                $instance = $this->get($binding[0], $parameters, $contextParameter, $ignoreII);
            }
            else
            {
                $instance = call_user_func_array($binding[0], $parameters);
            }

            if ($binding[1])
            {
                //Singleton
                $this->bindings[$alias] = $instance;
            }

            return $instance;
        }

        return null;
    }

    /**
     * Helper method to resolve constructor or function arguments, build required DI using IoC
     * container and mix with pre-defined set of named parameters.
     *
     * @param \ReflectionFunctionAbstract $reflection    Method or constructor should be filled with DI.
     * @param array                       $parameters    Outside parameters used in priority to DI.
     *                                                   Named list.
     * @param bool                        $userArguments If true no exception will be raised while
     *                                                   some argument (not DI) can not be resolved.
     *                                                   This parameter used to pass error to controller.
     * @return array
     * @throws ContainerException
     */
    public function resolveArguments(
        \ReflectionFunctionAbstract $reflection,
        array $parameters = [],
        $userArguments = false
    )
    {
        try
        {
            $arguments = [];
            foreach ($reflection->getParameters() as $parameter)
            {
                if (array_key_exists($parameter->getName(), $parameters))
                {
                    $parameterValue = $parameters[$parameter->getName()];

                    if (!$userArguments || !$parameter->getClass() || is_object($parameterValue))
                    {
                        //Provided directly
                        $arguments[] = $parameterValue;
                        continue;
                    }
                }

                if ($parameter->getClass())
                {
                    try
                    {
                        $arguments[] = $this->get(
                            $parameter->getClass()->getName(),
                            [],
                            $parameter,
                            false
                        );

                        continue;
                    }
                    catch (SpiralException $exception)
                    {
                        if (
                            !$parameter->isDefaultValueAvailable()
                            || $exception->getCode() != self::NON_INSTANTIABLE_ERROR
                        )
                        {
                            throw $exception;
                        }
                    }
                }

                if ($parameter->isDefaultValueAvailable())
                {
                    $arguments[] = $parameter->getDefaultValue();
                    continue;
                }

                if (!$userArguments)
                {
                    $name = $reflection->getName();
                    if ($reflection instanceof \ReflectionMethod)
                    {
                        $name = $reflection->class . '::' . $name;
                    }

                    throw new SpiralException(
                        "Unable to resolve '{$parameter->getName()}' argument in '{$name}'."
                    );
                }
            }
        }
        catch (\Exception $exception)
        {
            throw new ContainerException($exception, $reflection);
        }

        return $arguments;
    }

    /**
     * IoC binding can create a link between specified alias and method to resolve that alias, resolver
     * can be either class instance (that instance will be resolved as singleton), callback or string
     * alias. String aliases can be used to rewrite core classes with custom realization, or specify
     * what interface child should be used.
     *
     * @param string                 $alias  Alias where singleton will be attached to.
     * @param string|object|callable Closure to resolve class instance, class instance or class name.
     */
    public function bind($alias, $resolver)
    {
        if (is_array($resolver) || $resolver instanceof \Closure)
        {
            $this->bindings[$alias] = [$resolver, false];

            return;
        }

        $this->bindings[$alias] = $resolver;
    }

    /**
     * Bind closure or class name which will be performed only once, after first call class instance
     * will be attached to specified alias and will be returned directly without future invoking.
     *
     * @param string   $alias    Alias where singleton will be attached to.
     * @param callable $resolver Closure to resolve class instance.
     */
    public function bindSingleton($alias, $resolver)
    {
        $this->bindings[$alias] = [$resolver, true];
    }

    /**
     * Check if desired alias or class name binded in Container. You can bind new alias using
     * Container::bind(), Container::bindSingleton().
     *
     * @param string $alias
     * @return bool
     */
    public function hasBinding($alias)
    {
        return isset($this->bindings[$alias]);
    }

    /**
     * Return binding resolver in original form (without processing it to instance).
     *
     * @param string $alias
     * @return mixed
     */
    public function getBinding($alias)
    {
        return isset($this->bindings[$alias]) ? $this->bindings[$alias] : null;
    }

    /**
     * Remove existed binding.
     *
     * @param string $alias
     */
    public function removeBinding($alias)
    {
        unset($this->bindings[$alias]);
    }

    /**
     * Return all available bindings and binded components.
     *
     * @return array
     */
    public function getBindings()
    {
        return $this->bindings;
    }

    /**
     * Alias for get() method.
     *
     * @param string $name
     * @return mixed|null|object
     */
    public function __get($name)
    {
        return $this->get($name);
    }

    /**
     * Alias for bind method.
     *
     * @param string $alias
     * @param mixed  $resolver
     */
    public function __set($alias, $resolver)
    {
        $this->bind($alias, $resolver);
    }

    /**
     * Alias for hasBinding() method.
     *
     * @param mixed $offset
     * @return bool
     */
    public function offsetExists($offset)
    {
        return $this->hasBinding($offset);
    }

    /**
     * Alias for get() method.
     *
     * @param mixed $offset
     * @return mixed|null|object
     */
    public function offsetGet($offset)
    {
        return $this->get($offset);
    }

    /**
     * Alias for bind() method.
     *
     * @param mixed $offset
     * @param mixed $value
     */
    public function offsetSet($offset, $value)
    {
        $this->bind($offset, $value);
    }

    /**
     * Alias for removeBinding() method.
     *
     * @param mixed $offset
     */
    public function offsetUnset($offset)
    {
        $this->removeBinding($offset);
    }
}