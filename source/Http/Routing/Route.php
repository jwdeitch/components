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
use Spiral\Http\HttpPipeline;

class Route extends AbstractRoute
{
    /**
     * Use this string as your target action to resolve action from routed URL.
     *
     * Example:
     * new Route('name', 'userPanel/<action>', 'Controllers\UserPanel::<action>');
     *
     * Attention, you can't route controllers this way, use DirectRoute for such purposes.
     */
    const DYNAMIC_ACTION = '<action>';

    /**
     * Declared route target, can be middleware (instance or class name), controller/action combination
     * specified using full class name and :: separator or closure.
     *
     * @var null
     */
    protected $target = null;

    /**
     * New instance of spiral Route. Route can support callable targets, controller/action association
     * including actions resolved from route itself and etc.
     *
     * Example (given in a context of application bootstrap method):
     *
     * Static routes.
     *      $this->http->route('profile-<id>', 'Controllers\UserController::showProfile');
     *      $this->http->route('profile-<id>', 'Controllers\UserController::showProfile');
     *
     * Dynamic actions:
     *      $this->http->route('account/<action>', 'Controllers\AccountController::<action>');
     *
     * Optional segments:
     *      $this->http->route('profile[/<id>]', 'Controllers\UserController::showProfile');
     *
     * This route will react on URL's like /profile/ and /profile/someSegment/
     *
     * To determinate your own pattern for segment use construction <segmentName:pattern>
     *      $this->http->route('profile[/<id:\d+>]', 'Controllers\UserController::showProfile');
     *
     * Will react only on /profile/ and /profile/1384978/
     *
     * You can use custom pattern for controller and action segments.
     * $this->http->route('users[/<action:edit|save|open>]', 'Controllers\UserController::<action>');
     *
     * Routes can be applied to URI host.
     * $this->http->route(
     *      '<username>.domain.com[/<action>[/<id>]]',
     *      'Controllers\UserController::<action>'
     * )->useHost();
     *
     * Routes can be used non only with controllers (no idea why you may need it):
     * $this->http->route('users', function ()
     * {
     *      return "This is users route.";
     * });
     *
     * Or be associated with middleware:
     * $this->http->route('/something[/<value>]', new MyMiddleware());
     *
     * @param string          $name    Route name.
     * @param string          $pattern Route pattern.
     * @param string|callable $target  Route target.
     * @param array           $defaults
     */
    public function __construct($name, $pattern, $target, array $defaults = [])
    {
        $this->name = $name;
        $this->pattern = $pattern;
        $this->target = $target;
        $this->defaults = $defaults;
    }

    /**
     * Perform route on given Request and return response.
     *
     * @param ServerRequestInterface $request
     * @param ContainerInterface     $container Container is required to get valid middleware instance
     *                                          and execute controllers in some cases.
     * @return mixed
     */
    public function perform(ServerRequestInterface $request, ContainerInterface $container)
    {
        $pipeline = new HttpPipeline($container, $this->middlewares);

        return $pipeline->target($this->createEndpoint($container))->run($request);
    }

    /**
     * Get callable route target.
     *
     * @param ContainerInterface $container
     * @return callable
     */
    protected function createEndpoint(ContainerInterface $container)
    {
        if (is_object($this->target) || is_array($this->target))
        {
            return $this->target;
        }

        if (is_string($this->target) && strpos($this->target, self::CONTROLLER_SEPARATOR) === false)
        {
            //Middleware
            return $container->get($this->target);
        }

        return function (ServerRequestInterface $request) use ($container)
        {
            list($controller, $action) = explode(self::CONTROLLER_SEPARATOR, $this->target);

            if ($action == self::DYNAMIC_ACTION)
            {
                $action = $this->matches['action'];
            }

            //Calling controller (using core resolved via container)
            return $this->callAction($container, $controller, $action, $this->matches);
        };
    }
}