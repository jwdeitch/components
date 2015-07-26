<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Tokenizer;

interface TokenizerInterface
{
    /**
     * Token array constants.
     */
    const TYPE = 0;
    const CODE = 1;
    const LINE = 2;

    /**
     * Fetch PHP tokens for specified filename. String tokens should be automatically extended with their
     * type and line.
     *
     * @param string $filename
     * @return array
     */
    public function fetchTokens($filename);

    /**
     * Index all available files excluding and generate list of found classes with their names and
     * filenames. Unreachable classes or files with conflicts be skipped.
     *
     * This is SLOW method, should be used only for static analysis.
     *
     * Output format:
     * $result['CLASS_NAME'] = [
     *      'class'    => 'CLASS_NAME',
     *      'filename' => 'FILENAME',
     *      'abstract' => 'ABSTRACT_BOOL'
     * ]
     *
     * @param mixed  $parent    Class or interface should be extended. By default - null (all classes).
     *                          Parent will also be included to classes list as one of results.
     * @param string $namespace Only classes in this namespace will be retrieved, null by default
     *                          (all namespaces).
     * @param string $postfix   Only classes with such postfix will be analyzed, empty by default.
     * @return array
     */
    public function getClasses($parent = null, $namespace = null, $postfix = '');
}