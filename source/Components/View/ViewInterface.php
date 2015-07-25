<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Components\View;

interface ViewInterface
{
    /**
     * View instance binded to specified view file (file has to be already pre-processed).
     *
     * @param ViewManager $viewManager ViewManager component.
     * @param string      $filename    Compiled view file.
     * @param array       $data        Runtime data passed by controller or model, should be injected
     *                                 into view.
     * @param string      $namespace   View namespace.
     * @param string      $view        View name.
     */
    public function __construct(ViewManager $viewManager, $filename, array $data = [], $namespace, $view);

    /**
     * Perform view rendering using compiled view file and runtime data to be injected.
     *
     * @return string
     */
    public function render();

    /**
     * Alias for render method.
     *
     * @return string
     */
    public function __toString();
}