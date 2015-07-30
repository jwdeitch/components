<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Http\Routing;

use Psr\Http\Message\ServerRequestInterface;
use Spiral\Core\ContainerInterface;
use Spiral\Http\MiddlewarePipeline;

/**
 * {@inheritdoc}
 *
 * Used to route to specified namespace of controllers. DirectRoute can route only to controllers,
 * which means that pattern should always include both <controller> and <action> segments.
 *
 * Usually DirectRoute used to create "general" routing without definition of route for every
 * controller action and etc. Having DirectRoute attached to Router as default route will allow
 * user to generate urls based on controller action name ($router->createUri("controller::action") or
 * $router->createUri("controller/action")).
 *
 * Examples:
 * new DirectRoute(
 *      "default",
 *      "(<controller>(/<action>(/<id>)))",
 *      "Controllers",
 *      "Controller",
 *      ["controller" => "home"]
 * );
 *
 * You can also create host depended routes.
 * $route = new DirectRoute(
 *      "default",
 *      "domain.com(/<controller>(/<action>(/<id>)))",
 *      "Controllers",
 *      "Controller",
 *      ["controller" => "home"]
 * );
 * $route->withHost();
 */
class DirectRoute extends AbstractRoute
{
    /**
     * Default controllers namespace.
     *
     * @var string
     */
    protected $namespace = '';

    /**
     * Default controller postfix.
     *
     * @var string
     */
    protected $postfix = '';

    /**
     * Controllers aliased by name, namespace and postfix will be ignored in this case.
     *
     * @var array
     */
    protected $controllers = [];

    /**
     * New instance of DirectRoute.
     *
     * @param string $name
     * @param string $pattern
     * @param string $namespace   Default controllers namespace.
     * @param string $postfix     Default controller postfix.
     * @param array  $defaults    Default values (including default controller).
     * @param array  $controllers Controllers aliased by their name, namespace and postfix will be
     *                            ignored in this case.
     */
    public function __construct($name, $pattern, $namespace, $postfix, array $defaults, array $controllers)
    {
        $this->name = $name;
        $this->pattern = $pattern;
        $this->namespace = $namespace;
        $this->postfix = $postfix;
        $this->defaults = $defaults;
        $this->controllers = $controllers;
    }

    /**
     * Create controller aliases, namespace and postfix will be ignored in this case.
     * Example: $route->controllers(["auth" => "Module\Authorization\AuthController"]);
     *
     * @param array $controllers
     * @return $this
     */
    public function controllers(array $controllers)
    {
        $this->controllers += $controllers;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function perform(ServerRequestInterface $request, ContainerInterface $container)
    {
        $pipeline = new MiddlewarePipeline($container, $this->middlewares);

        return $pipeline->target($this->createEndpoint($container))->run($request);
    }

    /**
     * Create callable route endpoint.
     *
     * @param ContainerInterface $container
     * @return callable
     */
    protected function createEndpoint(ContainerInterface $container)
    {
        $route = $this;

        return function (ServerRequestInterface $request) use ($container, $route)
        {
            $controller = $route->matches['controller'];
            if (isset($route->controllers[$controller]))
            {
                //Aliased
                $controller = $route->controllers[$controller];
            }
            else
            {
                $controller = $route->namespace . '\\' . ucfirst($controller) . $route->postfix;
            }

            return $route->callAction($container, $controller, $route->matches['action'], $route->matches);
        };
    }
}