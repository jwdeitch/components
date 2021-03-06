<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */
namespace Spiral\Debug;

use Exception;

/**
 * Wrapper used to describe and show exception information in user friendly way.
 */
interface SnapshotInterface
{
    /**
     * @param Exception $exception
     */
    public function __construct(Exception $exception);

    /**
     * Short exception name.
     *
     * @return string
     */
    public function getName();

    /**
     * @return Exception
     */
    public function getException();

    /**
     * @return string
     */
    public function getClass();

    /**
     * @return string
     */
    public function getFile();

    /**
     * @return int
     */
    public function getLine();

    /**
     * @return array
     */
    public function getTrace();

    /**
     * Formatted exception message, should include exception class name, original error message and
     * location with fine and line.
     *
     * @return string
     */
    public function getMessage();

    /**
     * Report or store snapshot in known location. Used to store exception information for future
     * analysis.
     */
    public function report();

    /**
     * Get shortened exception description in array form.
     *
     * @return array
     */
    public function describe();

    /**
     * Render snapshot information into string or html.
     *
     * @return string
     */
    public function render();

    /**
     * @return string
     */
    public function __toString();
}