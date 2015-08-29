<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Http;

/**
 * {@inheritdoc}
 */
class Uri extends \Zend\Diactoros\Uri implements \JsonSerializable
{
    /**
     * {@inheritdoc}
     */
    function jsonSerialize()
    {
        return $this->__toString();
    }

    /**
     * @return object
     */
    function __debugInfo()
    {
        return (object)[
            'uri' => (string)$this
        ];
    }
}