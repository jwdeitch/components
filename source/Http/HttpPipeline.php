<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Spiral\Core\Component;
use Spiral\Core\Container;
use Spiral\Core\ContainerInterface;
use Spiral\Http\Responses\JsonResponse;

class HttpPipeline extends Component
{
    /**
     * Request will be binded under this alias in associated container.
     */
    const REQUEST_INTERFACE = 'Psr\Http\Message\ServerRequestInterface';

    /**
     * Container.
     *
     * @invisible
     * @var ContainerInterface
     */
    protected $container = null;

    /**
     * Set of middleware layers built to handle incoming Request and return Response. Middleware
     * can be represented as class, string (DI) or array (callable method).
     *
     * @var array|MiddlewareInterface[]
     */
    protected $middlewares = [];

    /**
     * Final endpoint has to be called, this is "the deepest" part of pipeline. It's not necessary
     * that this endpoint will be called at all, as one of middleware layers can stop processing.
     *
     * @var callable
     */
    protected $target = null;

    /**
     * Middleware Pipeline used by HttpDispatchers to pass request thought middleware(s) and receive
     * filtered result. Pipeline can be used outside dispatcher in routes, modules and controllers.
     *
     * @param ContainerInterface    $container Container is required to create request scope.
     * @param MiddlewareInterface[] $middleware
     */
    public function __construct(ContainerInterface $container, array $middleware = [])
    {
        $this->container = $container;
        $this->middlewares = $middleware;
    }

    /**
     * Add new middleware to end of chain. Middleware can be represented as class, string (DI) or
     * array (callable method). Use can use closures to specify middleware. Every middleware will
     * receive 3 parameters, Request, next closure and context.
     *
     * @param mixed $middleware
     * @return $this
     */
    public function add($middleware)
    {
        $this->middlewares[] = $middleware;

        return $this;
    }

    /**
     * Every pipeline should have specified target to generate "deepest" response instance or other
     * response data (depends on context). Target should always be specified.
     *
     * @param callable $target
     * @return $this
     */
    public function target($target)
    {
        $this->target = $target;

        return $this;
    }

    /**
     * Run pipeline chain with specified input request and context. Response type depends on target
     * method and middleware logic.
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function run(ServerRequestInterface $request)
    {
        return $this->next(0, $request);
    }

    /**
     * Internal method used to jump between middleware layers.
     *
     * @param int                    $position
     * @param ServerRequestInterface $outerRequest
     * @return mixed
     */
    protected function next($position, ServerRequestInterface $outerRequest)
    {
        $next = function ($request = null) use ($position, $outerRequest)
        {
            return $this->next(++$position, $request ?: $outerRequest);
        };

        if (!isset($this->middlewares[$position]))
        {
            return $this->createResponse($outerRequest);
        }

        /**
         * @var callable $middleware
         */
        $middleware = $this->middlewares[$position];
        $middleware = is_string($middleware)
            ? $this->container->get($middleware)
            : $middleware;

        return $middleware($outerRequest, $next);
    }

    /**
     * Execute pipeline target and get reponse.
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    protected function createResponse(ServerRequestInterface $request)
    {
        //Let's create request scope
        $outerRequest = $this->container->replace(self::REQUEST_INTERFACE, $request);

        ob_start();
        if ($this->target instanceof \Closure)
        {
            $reflection = new \ReflectionFunction($this->target);
            $response = $reflection->invokeArgs(
                $this->container->resolveArguments($reflection, ['request' => $request])
            );
        }
        else
        {
            $response = call_user_func($this->target, $request);
        }
        $plainOutput = ob_get_clean();

        $this->container->restore($outerRequest);

        return $this->wrapResponse($response, $plainOutput);
    }

    /**
     * Helper method used to wrap raw response from middlewares and controllers to correct Response
     * class. Method support string and JsonSerializable (including arrays) inputs. Default status
     * will be set as 200. If you want to specify default set of headers for raw responses check
     * http->config->headers section.
     *
     * You can force status for JSON responses by providing response as array with "status" key equals
     * to desired HTTP code.
     *
     * @param mixed  $response
     * @param string $plainOutput
     * @return ResponseInterface
     */
    protected function wrapResponse($response, $plainOutput = '')
    {
        if ($response instanceof ResponseInterface)
        {
            if (!empty($plainOutput))
            {
                $response->getBody()->write($plainOutput);
            }

            return $response;
        }

        if (is_array($response) || $response instanceof \JsonSerializable)
        {
            if (is_array($response) && !empty($plainOutput))
            {
                $response['plainOutput'] = $plainOutput;
            }

            $code = 200;
            if (is_array($response) && isset($response['status']))
            {
                $code = $response['status'];
            }

            return new JsonResponse($response, $code);
        }

        $psrResponse = new Response();
        $psrResponse->getBody()->write($response . $plainOutput);

        return $psrResponse;
    }
}