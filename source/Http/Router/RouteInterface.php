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
use Spiral\Core\Container;
use Spiral\Core\ContainerInterface;

interface RouteInterface
{
    /**
     * Get route name. Name is requires to correctly identify route inside router stack (to generate
     * url for example).
     *
     * @return string
     */
    public function getName();

    /**
     * Check if route matched with provided request. Will check url pattern and pre-conditions.
     *
     * @param ServerRequestInterface $request
     * @param string                 $basePath
     * @return bool
     */
    public function match(ServerRequestInterface $request, $basePath = '/');

    /**
     * Perform route on given Request and return response.
     *
     * @param ServerRequestInterface $request
     * @param ContainerInterface     $container Container is required to get valid middleware instance
     *                                          and execute controllers in some cases.
     * @return mixed
     */
    public function perform(ServerRequestInterface $request, ContainerInterface $container);

    /**
     * Create URL using route parameters (will be merged with default values), route pattern and base
     * path.
     *
     * @param array  $parameters
     * @param string $basePath
     * @return string
     */
    public function createURL(array $parameters = [], $basePath = '/');
}