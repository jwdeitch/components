<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Http\Router;

use Psr\Http\Message\ServerRequestInterface;
use Spiral\Core\ContainerInterface;
use Spiral\Http\ClientException;
use Spiral\Http\ControllerInterface;
use Spiral\Http\MiddlewareInterface;

abstract class AbstractRoute implements RouteInterface
{
    /**
     * Default segment pattern, this patter can be applied to controller names, actions and etc.
     */
    const DEFAULT_SEGMENT = '[^\/]+';

    /**
     * Default separator to split controller and action name in route target.
     */
    const CONTROLLER_SEPARATOR = '::';

    /**
     * Declared route name.
     *
     * @var string
     */
    protected $name = '';

    /**
     * Middlewares associated with route. You can always get access to parent route using route attribute
     * of server request.
     *
     * @var array
     */
    protected $middlewares = [];

    /**
     * Route pattern includes simplified regular expressing later compiled to real regexp. Pattern
     * with be applied to URI path with excluded active path value (to make routes work when application
     * located in folder and etc).
     *
     * @var string
     */
    protected $pattern = '';

    /**
     * List of methods route should react to, by default all methods are passed.
     *
     * @var array
     */
    protected $methods = [];

    /**
     * Default set of values to fill route matches and target pattern (if specified as pattern).
     *
     * @var array
     */
    protected $defaults = [];

    /**
     * If true route will be matched with URI host in addition to path. BasePath will be ignored.
     *
     * @var bool
     */
    protected $withHost = false;

    /**
     * Compiled route options, pattern and etc. Internal data.
     *
     * @invisible
     * @var array
     */
    protected $compiled = [];

    /**
     * Result of regular expression. Matched can be used to fill target controller pattern or send
     * to controller method as arguments.
     *
     * @var array
     */
    protected $matches = [];

    /**
     * Set route name. This action should be performed BEFORE parent router will be created, in other
     * scenario route will be available under old name.
     *
     * Yes, there is not setName method (this is short alias).
     *
     * @param string $name
     * @return $this
     */
    public function name($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get route name. Name is requires to correctly identify route inside router stack (to generate
     * url for example).
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Get route pattern.
     *
     * @return string
     */
    public function getPattern()
    {
        return $this->pattern;
    }

    /**
     * If true (default) route will be matched against path + URI host.
     *
     * @param bool $withHost
     * @return $this
     */
    public function withHost($withHost = true)
    {
        $this->withHost = $withHost;

        return $this;
    }

    /**
     * List of methods route should react to, by default all methods are passed.
     *
     * Example:
     * $route->only('GET');
     * $route->only(['POST', 'PUT']);
     *
     * @param array|string $method
     * @return $this
     */
    public function only($method)
    {
        $this->methods = is_array($method) ? $method : func_get_args();

        return $this;
    }

    /**
     * Set default values (will be merged with current default) to be used in generated target.
     *
     * @param array $default
     * @return $this
     */
    public function defaults(array $default)
    {
        $this->defaults = $default + $this->defaults;

        return $this;
    }

    /**
     * Associated inner middleware with route. Route can use middlewares previously registered in
     * Route by it's aliases.
     *
     * Example:
     *
     * $router->registerMiddleware('cache', new CacheMiddleware(100));
     * $route->with('cache');
     *
     * @param string|MiddlewareInterface|\Closure $middleware Inner middleware alias, instance or
     *                                                        closure.
     * @return $this
     */
    public function with($middleware)
    {
        $this->middlewares[] = $middleware;

        return $this;
    }

    /**
     * Helper method used to compile simplified route pattern to valid regular expression.
     *
     * We can cache results of this method in future.
     */
    protected function compile()
    {
        $replaces = ['/' => '\\/', '[' => '(?:', ']' => ')?', '.' => '\.'];

        $options = [];
        if (preg_match_all('/<(\w+):?(.*?)?>/', $this->pattern, $matches))
        {
            $variables = array_combine($matches[1], $matches[2]);
            foreach ($variables as $name => $segment)
            {
                $segment = $segment ?: self::DEFAULT_SEGMENT;
                $replaces["<$name>"] = "(?P<$name>$segment)";
                $options[] = $name;
            }
        }

        $template = preg_replace('/<(\w+):?.*?>/', '<\1>', $this->pattern);
        $this->compiled = [
            'pattern'  => '/^' . strtr($template, $replaces) . '$/u',
            'template' => stripslashes(str_replace('?', '', $template)),
            'options'  => array_fill_keys($options, null)
        ];
    }

    /**
     * Check if route matched with provided request. Will check url pattern and pre-conditions.
     *
     * @param ServerRequestInterface $request
     * @param string                 $basePath
     * @return bool
     */
    public function match(ServerRequestInterface $request, $basePath = '/')
    {
        if (!empty($this->methods) && !in_array($request->getMethod(), $this->methods))
        {
            return false;
        }

        if (empty($this->compiled))
        {
            $this->compile();
        }

        $path = $request->getUri()->getPath();
        if (empty($path) || $path[0] !== '/')
        {
            $path = '/' . $path;
        }

        if ($this->withHost)
        {
            $uri = $request->getUri()->getHost() . $path;
        }
        else
        {
            $uri = substr($path, strlen($basePath));
        }

        if (preg_match($this->compiled['pattern'], rtrim($uri, '/'), $this->matches))
        {
            //To get only named matches
            $this->matches = array_intersect_key($this->matches, $this->compiled['options']);
            $this->matches = array_merge(
                $this->compiled['options'],
                $this->defaults,
                $this->matches
            );

            return true;
        }

        return false;
    }

    /**
     * Matches are populated after route matched request. Matched will include variable URL parts
     * merged with default values.
     *
     * @return array
     */
    public function getMatches()
    {
        return $this->matches;
    }

    /**
     * Create URL using route parameters (will be merged with default values), route pattern and base
     * path.
     *
     * @param array  $parameters
     * @param string $basePath
     * @return string
     */
    public function createURL(array $parameters = [], $basePath = '/')
    {
        if (empty($this->compiled))
        {
            $this->compile();
        }

        $parameters = $parameters + $this->defaults + $this->compiled['options'];

        //Rendering URL
        $url = \Spiral\interpolate(
            $this->compiled['template'],
            array_map(['Spiral\Helpers\UrlHelper', 'slug'], $parameters),
            '<',
            '>'
        );

        $query = '';

        //Getting additional parameters
        $queryParameters = array_diff_key($parameters, $this->compiled['options']);
        if (!empty($queryParameters))
        {
            $query = '?' . http_build_query($queryParameters);
        }

        //Kicking empty blocks
        $url = $basePath . strtr($url, ['[]' => '', '[/]' => '', '[' => '', ']' => '', '//' => '/']);

        return $url . $query;
    }

    /**
     * Resolve controller using Controller and call it's method with specified parameters.
     *
     * @param ContainerInterface $container
     * @param string             $controller
     * @param string             $action
     * @param array              $parameters
     * @return mixed
     * @throws ClientException
     */
    protected function callAction(ContainerInterface $container, $controller, $action, array $parameters = [])
    {
        if (!class_exists($controller))
        {
            throw new ClientException(ClientException::NOT_FOUND);
        }

        //Initiating controller with all required dependencies
        $controller = $container->get($controller);
        if (!$controller instanceof ControllerInterface)
        {
            throw new ClientException(404, "Not a valid controller.");
        }

        return $controller->callAction($container, $action, $parameters);
    }
}