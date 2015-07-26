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
     * @param Container              $container Container is required to get valid middleware instance.
     * @param array                  $filters   Name of filters to be applied.
     * @return mixed
     */
    public function perform(
        ServerRequestInterface $request,
        Container $container,
        array $filters = [] //TODO: I DON'T LIKE THIS NAME
    );

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