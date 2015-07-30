<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Core;
use Spiral\Core\Exceptions\ControllerException;

/**
 * Class being treated as controller.
 */
interface ControllerInterface
{
    /**
     * Execute specific controller action (method).
     *
     * @param ContainerInterface $container
     * @param string             $action     Method name.
     * @param array              $parameters Method parameters.
     * @return mixed
     * @throws ControllerException
     * @throws \Exception
     */
    public function callAction(ContainerInterface $container, $action = '', array $parameters = []);
}