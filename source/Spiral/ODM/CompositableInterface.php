<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\ODM;

use Spiral\Validation\ValidatesInterface;

/**
 * Declares that object can be embedded into Document and control it's updates, public fields and validations.
 */
interface CompositableInterface extends EmbeddableInterface, ValidatesInterface
{
    /**
     * Instance must re-validate data.
     *
     * @return $this
     */
    public function invalidate();

    /**
     * Every composited object must know how to give it's public data (safe to send to client) to parent.
     *
     * @return array
     */
    public function publicFields();
}