<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 * @copyright ©2009-2015
 */
namespace Spiral\Components\Tokenizer;

use Spiral\Components\Tokenizer\Reflection\FunctionUsage;
use Spiral\Components\Tokenizer\Reflection\FunctionUsage\Argument;
use Spiral\Tokenizer\Tokenizer;

class ReflectionFile
{
    /**
     * Token array constants.
     */
    const TOKEN_TYPE = Tokenizer::TYPE;
    const TOKEN_CODE = Tokenizer::CODE;
    const TOKEN_LINE = Tokenizer::LINE;
    const TOKEN_ID   = 3;

    /**
     * Set of tokens required to detect classes, traits, interfaces and function declarations. We
     * don't need any other token for that.
     *
     * @var array
     */
    static protected $useTokens = [
        '{',
        '}',
        ';',
        T_PAAMAYIM_NEKUDOTAYIM,
        T_NAMESPACE,
        T_STRING,
        T_CLASS,
        T_INTERFACE,
        T_TRAIT,
        T_FUNCTION,
        T_NS_SEPARATOR,
        T_INCLUDE,
        T_INCLUDE_ONCE,
        T_REQUIRE,
        T_REQUIRE_ONCE,
        T_USE,
        T_AS
    ];

    /**
     * Reflection filename.
     *
     * @var null
     */
    protected $filename = null;

    /**
     * Parsed tokens array.
     *
     * @invisible
     * @var array
     */
    protected $tokens = [];

    /**
     * Total tokens count.
     *
     * @var int
     */
    protected $countTokens = 0;

    /**
     * Namespaces used in file and their token positions.
     *
     * @invisible
     * @var array
     */
    protected $namespaces = [];

    /**
     * Declarations of classes, interfaces and traits.
     *
     * @var array
     */
    protected $declarations = [];

    /**
     * Declarations of new functions.
     *
     * @var array
     */
    protected $functions = [];

    /**
     * Indicator that file has external includes.
     *
     * @var bool
     */
    protected $includes = false;

    /**
     * Tokenizer component.
     *
     * @invisible
     * @var Tokenizer
     */
    protected $tokenizer = null;

    /**
     * Found functions usages.
     *
     * @var FunctionUsage[]
     */
    protected $functionUsages = [];

    /**
     * File file reflection instance, allows to fetch information about classes, interfaces or traits
     * declared in given file, used functions and other low level information. Reflector is very slow,
     * so it's recommended to use only with cache.
     *
     * @param string    $filename     Filename to be parsed.
     * @param Tokenizer $tokenizer    TokenManager instance.
     * @param array     $cache        Cached list of found classes, interfaces and etc, will be
     *                                pre-loaded to memory to speed up processing.
     */
    public function __construct($filename, Tokenizer $tokenizer = null, array $cache = [])
    {
        $this->filename = $filename;
        $this->tokenizer = $tokenizer;

        if (!empty($cache))
        {
            $this->importSchema($cache);

            return;
        }

        //TODO: REFACTOR!

        $tokens = $this->tokens = $tokenizer->fetchTokens($filename);

        //We only need few tokens to detect what we need
        foreach ($this->tokens as $TID => $token)
        {
            if (!in_array($token[self::TOKEN_TYPE], self::$useTokens))
            {
                unset($this->tokens[$TID]);
                continue;
            }

            $this->tokens[$TID][self::TOKEN_ID] = $TID;
        }

        $this->tokens = array_values($this->tokens);
        $this->countTokens = count($this->tokens);
        $this->locateDeclarations();

        //No need to record empty namespace
        if (isset($this->namespaces['']))
        {
            $this->namespaces['\\'] = $this->namespaces[''];
            unset($this->namespaces['']);
        }

        //Restoring original value
        $this->tokens = $tokens;
    }

    public function getFilename()
    {
        return $this->filename;
    }

    /**
     * Export found declaration as array for caching purposes.
     *
     * @return array
     */
    public function exportSchema()
    {
        return [
            'plainIncludes' => $this->includes,
            'declarations'  => $this->declarations,
            'functions'     => $this->functions,
            'namespaces'    => $this->namespaces
        ];
    }

    /**
     * Import cached schema of found declarations.
     *
     * @param array $schema
     * @return $this
     */
    public function importSchema(array $schema)
    {
        $this->includes = $schema['plainIncludes'];
        $this->declarations = $schema['declarations'];
        $this->functions = $schema['functions'];
        $this->namespaces = $schema['namespaces'];

        return $this;
    }

    /**
     * To detect all file declarations, including classes, interfaces, traits and methods. There is
     * no need to remember lines and other information as it can be requested using Function or Class
     * reflections.
     */
    protected function locateDeclarations()
    {
        $includeTokens = [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE];

        foreach ($this->tokens as $TID => $token)
        {
            $token[self::TOKEN_TYPE] == T_FUNCTION && $this->handleFunction($TID);
            $token[self::TOKEN_TYPE] == T_NAMESPACE && $this->handleNamespace($TID);
            $token[self::TOKEN_TYPE] == T_USE && $this->handleUse($TID);

            if (in_array($token[self::TOKEN_TYPE], [T_CLASS, T_TRAIT, T_INTERFACE]))
            {
                if (
                    $this->tokens[$TID][self::TOKEN_TYPE] == T_CLASS
                    && isset($this->tokens[$TID - 1])
                    && $this->tokens[$TID - 1][self::TOKEN_TYPE] == T_PAAMAYIM_NEKUDOTAYIM
                )
                {
                    //PHP5.5 ClassName::class constant
                    continue;
                }

                $this->handleDeclaration($TID, $token[self::TOKEN_TYPE]);
            }

            if (!$this->includes && in_array($token[self::TOKEN_TYPE], $includeTokens))
            {
                //File has includes, this is not good.
                $this->handleInclude($TID);
            }
        }
    }

    /**
     * Handle namespace declaration.
     *
     * @param int $firstTID Namespace token position.
     * @return array
     */
    protected function handleNamespace($firstTID)
    {
        $namespace = '';
        $TID = $firstTID + 1;

        do
        {
            $token = $this->tokens[$TID++];
            if ($token[self::TOKEN_CODE] == '{')
            {
                break;
            }

            $namespace .= $token[self::TOKEN_CODE];
        }
        while (
            isset($this->tokens[$TID])
            && $this->tokens[$TID][self::TOKEN_CODE] != '{'
            && $this->tokens[$TID][self::TOKEN_CODE] != ';'
        );

        $uses = [];
        if (isset($this->namespaces[$namespace]))
        {
            $uses = $this->namespaces[$namespace];
        }

        if ($this->tokens[$TID][self::TOKEN_CODE] == ';')
        {
            return $this->namespaces[$namespace] = [
                'firstTID' => $this->tokens[$firstTID][self::TOKEN_ID],
                'lastTID'  => $this->tokens[count($this->tokens) - 1][self::TOKEN_ID],
                'uses'     => $uses
            ];
        }

        //Declared using { and }
        return $this->namespaces[$namespace] = [
            'firstTID' => $this->tokens[$firstTID][self::TOKEN_ID],
            'lastTID'  => $this->findLastTID($firstTID),
            'uses'     => $uses
        ];
    }

    /**
     * Helper methods to get namespace active at specified token position. Will return empty string
     * (global namespace) if no namespace were found.
     *
     * @param int $TID Token id to check for any active namespaces.
     * @return string
     */
    protected function activeNamespace($TID)
    {
        $TID = isset($this->tokens[$TID][self::TOKEN_ID]) ? $this->tokens[$TID][self::TOKEN_ID] : $TID;
        foreach ($this->namespaces as $namespace => $position)
        {
            if ($TID >= $position['firstTID'] && $TID <= $position['lastTID'])
            {
                return $namespace ? $namespace . '\\' : '';
            }
        }

        //Seems like no namespace declaration
        $this->namespaces[''] = [
            'firstTID' => 0,
            'lastTID'  => count($this->tokens),
            'uses'     => []
        ];

        return '';
    }

    /**
     * Handle declaration of class, trait of interface. Declaration will be stored under it's token
     * type in declarations array.
     *
     * @param int $firstTID  Declaration start token.
     * @param int $tokenType Declaring token type (T_CLASS, T_TRAIT, T_INTERFACE).
     */
    protected function handleDeclaration($firstTID, $tokenType)
    {
        $name = $this->tokens[$firstTID + 1][self::TOKEN_CODE];
        $this->declarations[token_name($tokenType)][$this->activeNamespace($firstTID) . $name] = [
            'firstTID' => $this->tokens[$firstTID][self::TOKEN_ID],
            'lastTID'  => $this->findLastTID($firstTID)
        ];
    }

    /**
     * Handle use (importing class from another namespace).
     *
     * @param int $firstTID Declaration start token.
     */
    protected function handleUse($firstTID)
    {
        $namespace = rtrim($this->activeNamespace($firstTID), '\\');

        $class = '';
        $localAlias = null;
        for ($TID = $firstTID + 1; $this->tokens[$TID][self::TOKEN_CODE] != ';'; $TID++)
        {
            if ($this->tokens[$TID][self::TOKEN_TYPE] == T_AS)
            {
                $localAlias = '';
                continue;
            }

            if ($localAlias === null)
            {
                $class .= $this->tokens[$TID][self::TOKEN_CODE];
            }
            else
            {
                $localAlias .= $this->tokens[$TID][self::TOKEN_CODE];
            }
        }

        if (empty($localAlias))
        {
            $names = explode('\\', $class);
            $localAlias = end($names);
        }

        $this->namespaces[$namespace]['uses'][$localAlias] = $class;
    }

    /**
     * Handle function declaration (function creation), function name will be added to function array
     * under it's global name including parent namespace. Class methods will not be treated as functions.
     *
     * @param int $firstTID
     */
    protected function handleFunction($firstTID)
    {
        //Checking if we are in class
        foreach ($this->declarations as $declarations)
        {
            foreach ($declarations as $location)
            {
                if (
                    $this->tokens[$firstTID][self::TOKEN_ID] >= $location['firstTID']
                    && $this->tokens[$firstTID][self::TOKEN_ID] <= $location['lastTID']
                )
                {
                    return;
                }
            }
        }

        $name = $this->tokens[$firstTID + 1][self::TOKEN_CODE];
        $this->functions[$this->activeNamespace($firstTID) . $name] = [
            'firstTID' => $this->tokens[$firstTID][self::TOKEN_ID],
            'lastTID'  => $this->findLastTID($firstTID)
        ];
    }

    /**
     * Handle include token.
     *
     * @param $TID
     */
    public function handleInclude($TID)
    {
        $this->includes = true;
    }

    /**
     * List of declared function names.
     *
     * @return array
     */
    public function getFunctions()
    {
        return array_keys($this->functions);
    }

    /**
     * List of declared class names.
     *
     * @return array
     */
    public function getClasses()
    {
        if (!isset($this->declarations['T_CLASS']))
        {
            return [];
        }

        return array_keys($this->declarations['T_CLASS']);
    }

    /**
     * List of declared class names.
     *
     * @return array
     */
    public function getTraits()
    {
        if (!isset($this->declarations['T_TRAIT']))
        {
            return [];
        }

        return array_keys($this->declarations['T_TRAIT']);
    }

    /**
     * List of declared class names.
     *
     * @return array
     */
    public function getInterfaces()
    {
        if (!isset($this->declarations['T_INTERFACE']))
        {
            return [];
        }

        return array_keys($this->declarations['T_INTERFACE']);
    }

    /**
     * Check if file has. Includes can not be resolved automatically and can cause unpredictable problems,
     * by default spiral classes indexer will ignore files with such includes.
     *
     * @return bool
     */
    public function hasIncludes()
    {
        return $this->includes;
    }

    /**
     * Helper method to locate last brace token and return it's ID(last TID).
     *
     * @param int $firstTID Token ID to start lookup from.
     * @return mixed
     */
    protected function findLastTID($firstTID)
    {
        $level = null;
        for ($TID = $firstTID; $TID < $this->countTokens; $TID++)
        {
            $token = $this->tokens[$TID];
            if ($token[self::TOKEN_CODE] == '{')
            {
                $level++;
                continue;
            }

            if ($token[self::TOKEN_CODE] == '}')
            {
                $level--;
            }

            if ($level !== null && !$level)
            {
                break;
            }
        }

        return isset($this->tokens[$TID][self::TOKEN_ID]) ? $this->tokens[$TID][self::TOKEN_ID] : $TID;
    }

    /**
     * Will located and return all functions usages found in source. Function name, location and
     * arguments will be retrieved. This method can be used to detect i18n functions and methods.
     * Due this code was written long time ago, it's not included to same tokenization process as for
     * declarations, but may be included in future.
     *
     * Attention:
     * No methods called via self, static or -> will not be recorded. Functions from another namespace
     * currently not supported (will be in future), plus there is known issue about $class::method() calls.
     *
     * @return FunctionUsage[]|array
     */
    public function functionUsages()
    {
        if (!$this->functionUsages)
        {
            $this->locateUsages($this->tokens ?: $this->tokenizer->fetchTokens($this->filename));
        }

        return $this->functionUsages;
    }

    /**
     * Searching for function usages.
     *
     * @param array $tokens        List of parsed tokens from token_get_all() function.
     * @param int   $functionLevel If function were used inside another function this variable will
     *                             be increased.
     */
    protected function locateUsages(array $tokens, $functionLevel = 0)
    {
        //Multiple "(" and ")" statements nested.
        $parenthesisLevel = 0;

        //Skip all tokens until next function
        $skipTokens = false;

        //Were function was found
        $functionTID = $functionLine = 0;

        //Parsed arguments and their first token id
        $arguments = [];
        $argumentsTID = false;

        //Tokens used to re-enable token detection
        $stopTokens = [T_STRING, T_WHITESPACE, T_DOUBLE_COLON, T_NS_SEPARATOR];

        foreach ($tokens as $TID => $token)
        {
            $tokenType = $token[self::TOKEN_TYPE];

            //We are not indexing function declarations or functions called from $objects.
            if ($tokenType == T_FUNCTION || $tokenType == T_OBJECT_OPERATOR || $tokenType == T_NEW)
            {
                //Not a usage, function declaration, or object method
                if (!$argumentsTID)
                {
                    $skipTokens = true;
                    continue;
                }
            }
            elseif ($skipTokens)
            {
                if (!in_array($tokenType, $stopTokens))
                {
                    //Returning to search
                    $skipTokens = false;
                }
                continue;
            }

            //We are inside function, and there is "(", indexing arguments.
            if ($functionTID && $tokenType == '(')
            {
                if (!$argumentsTID)
                {
                    $argumentsTID = $TID;
                }

                $parenthesisLevel++;
                if ($parenthesisLevel != 1)
                {
                    //Not arguments beginning, but arguments part
                    $arguments[$TID] = $token;
                }

                continue;
            }

            //We are inside function arguments and ")" met.
            if ($functionTID && $tokenType == ')')
            {
                $parenthesisLevel--;
                if ($parenthesisLevel == -1)
                {
                    $functionTID = false;
                    $parenthesisLevel = 0;

                    continue;
                }

                //Function fully indexed, we can process it now.
                if (!$parenthesisLevel)
                {
                    $source = '';
                    for ($tokenID = $functionTID; $tokenID <= $TID; $tokenID++)
                    {
                        //Collecting function usage source
                        $source .= $tokens[$tokenID][self::TOKEN_CODE];
                    }

                    //Will be fixed in future
                    $class = $this->resolveClass($tokens, $functionTID, $argumentsTID);

                    $functionUsage = null;
                    if ($class != 'self' && $class != 'static')
                    {
                        $functionUsage = FunctionUsage::make([
                            'function' => $this->resolveName($tokens, $functionTID, $argumentsTID),
                            'class'    => $class,
                            'source'   => $source
                        ]);

                        $functionUsage->setPosition($functionLine, $functionTID, $TID, $functionLevel);
                        $functionUsage->setArguments($this->processArguments($arguments));
                    }

                    //Nested functions can be function in usage arguments.
                    $this->locateUsages($arguments, $functionLevel + 1);
                    $functionUsage && ($this->functionUsages[] = $functionUsage);

                    //Closing search
                    $arguments = [];
                    $argumentsTID = $functionTID = false;
                }
                else
                {
                    //Not arguments beginning, but arguments part
                    $arguments[$TID] = $token;
                }

                continue;
            }

            //Still inside arguments.
            if ($functionTID && $parenthesisLevel)
            {
                $arguments[$TID] = $token;
                continue;
            }

            //Nothing valuable to remember, will be parsed later.
            if ($functionTID && in_array($tokenType, $stopTokens))
            {
                continue;
            }

            //Seems like we found function usage.
            if ($tokenType == T_STRING || $tokenType == T_STATIC || $tokenType == T_NS_SEPARATOR)
            {
                $functionTID = $TID;
                $functionLine = $token[self::TOKEN_LINE];

                $parenthesisLevel = 0;
                $argumentsTID = false;
                continue;
            }

            //Returning to search
            $functionTID = false;
            $arguments = [];
        }
    }

    /**
     * Resolve function name. Will not include class name (see resolveClass).
     *
     * @param array $tokens       Array of tokens from parsed source.
     * @param int   $openTID      Function open token ID.
     * @param int   $argumentsTID Function first argument token ID.
     * @return string
     */
    protected function resolveName(array $tokens, $openTID, $argumentsTID)
    {
        $function = [];
        for (; $openTID < $argumentsTID; $openTID++)
        {
            $token = $tokens[$openTID];
            if ($token[self::TOKEN_TYPE] == T_STRING || $token[self::TOKEN_TYPE] == T_STATIC)
            {
                $function[] = $token[self::TOKEN_CODE];
            }
        }

        if (count($function) > 1)
        {
            return end($function);
        }

        return $function[0];
    }

    /**
     * Resolve function name. Will not include class name (see resolveClass).
     *
     * @param array $tokens       Array of tokens from parsed source.
     * @param int   $openTID      Function open token ID.
     * @param int   $argumentsTID Function first argument token ID.
     * @return string
     */
    protected function resolveClass(array $tokens, $openTID, $argumentsTID)
    {
        $function = [];
        for (; $openTID < $argumentsTID; $openTID++)
        {
            $token = $tokens[$openTID];
            if ($token[self::TOKEN_TYPE] == T_STRING || $token[self::TOKEN_TYPE] == T_STATIC)
            {
                $function[] = $token[self::TOKEN_CODE];
            }
        }

        if (count($function) == 1)
        {
            return null;
        }

        unset($function[count($function) - 1]);

        //Resolving class
        $class = join('\\', $function);

        if (strtolower($class) == 'self' || strtolower($class) == 'static')
        {
            foreach ($this->declarations as $declarations)
            {
                foreach ($declarations as $name => $location)
                {
                    if ($openTID >= $location['firstTID'] && $openTID <= $location['lastTID'])
                    {
                        return $name;
                    }
                }
            }
        }
        else
        {
            $namespace = rtrim($this->activeNamespace($openTID), '\\');

            if (isset($this->namespaces[$namespace ?: '\\']['uses'][$class]))
            {
                return $this->namespaces[$namespace ?: '\\']['uses'][$class];
            }
        }

        return $class;
    }

    /**
     * Parse list of function arguments to resolve their types and values. No whitespaces will included.
     *
     * @param array $tokens
     * @return Argument[]
     */
    protected function processArguments(array $tokens)
    {
        $argument = $parenthesis = 0;

        $arguments = [];
        foreach ($tokens as $token)
        {
            if ($token[self::TOKEN_TYPE] == T_WHITESPACE)
            {
                continue;
            }

            if (!$argument)
            {
                $argument = ['type' => Argument::EXPRESSION, 'value' => '', 'tokens' => []];
            }

            if ($token[self::TOKEN_TYPE] == '(')
            {
                $parenthesis++;
                $argument['value'] .= $token[self::TOKEN_CODE];
                continue;
            }

            if ($token[self::TOKEN_TYPE] == ')')
            {
                $parenthesis--;
                $argument['value'] .= $token[self::TOKEN_CODE];
                continue;
            }

            if ($parenthesis)
            {
                $argument['value'] .= $token[self::TOKEN_CODE];
                continue;
            }

            if ($token[self::TOKEN_TYPE] == ',')
            {
                //Creating new argument.
                $arguments[] = $this->createArgument($argument);
                $argument = null;
                continue;
            }

            $argument['tokens'][] = $token;
            $argument['value'] .= $token[self::TOKEN_CODE];
        }

        //Last argument
        if (($argument = $this->createArgument($argument)) && $argument->getType())
        {
            $arguments[] = $argument;
        }

        return $arguments;
    }

    /**
     * Resolve argument type and value from set of tokens.
     *
     * @param array $argument
     * @return Argument
     */
    protected function createArgument($argument)
    {
        if (!is_array($argument))
        {
            return false;
        }

        if (count($argument['tokens']) == 1)
        {
            $tokenType = $argument['tokens'][0][0];
            if ($tokenType == T_VARIABLE)
            {
                $argument['type'] = Argument::VARIABLE;
            }

            if ($tokenType == T_LNUMBER || $tokenType == T_DNUMBER)
            {
                $argument['type'] = Argument::CONSTANT;
            }

            if ($tokenType == T_CONSTANT_ENCAPSED_STRING)
            {
                $argument['type'] = Argument::STRING;
            }
        }

        return Argument::make($argument);
    }
}