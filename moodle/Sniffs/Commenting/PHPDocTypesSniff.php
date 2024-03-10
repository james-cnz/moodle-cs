<?php

// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Check PHPDoc Types.
 *
 * @copyright  2023-2024 Otago Polytechnic
 * @author     James Calder
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later (or CC BY-SA v4 or later)
 */

namespace MoodleHQ\MoodleCS\moodle\Sniffs\Commenting;

use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Files\File;

/**
 * Check PHPDoc Types.
 */
class PHPDocTypesSniff implements Sniff
{

    /** @var ?File the current file */
    private ?File $file = null;

    /** @var array[] file tokens */
    private array $tokens = [];

    /** @var array<string, object{extends: ?string, implements: string[]}>*/
    private array $artifacts = [];

    /** @var ?TypeParser */
    private ?TypeParser $typeparser = null;

    /** @var int */
    private int $pass = 0;

    /** @var int pointer in the file */
    private int $fileptr = 0;

    /** @var Scope[] stack of scopes */
    private array $scopes = [];

    /** @var ?PHPDoc PHPDoc comment for upcoming declaration */
    private ?PHPDoc $comment = null;

    /** @var array<string, mixed> the current token */
    private array $token = ['code' => null, 'content' => ''];

    /**
     * Register for open tag (only process once per file).
     * @return array
     */
    public function register(): array {
        return [T_OPEN_TAG];
    }

    /**
     * Processes PHP files and perform various checks with file.
     * @param File $phpcsfile The file being scanned.
     * @param int $stackptr The position in the stack.
     * @return void
     */
    public function process(File $phpcsfile, int $stackptr): void {

        if ($phpcsfile == $this->file) {
            return;
        }

        $this->file = $phpcsfile;
        $this->tokens = $phpcsfile->getTokens();
        $this->artifacts = [];

        $this->pass = 1;
        $this->typeparser = null;
        $this->fileptr = $stackptr;
        $this->scopes = [new Scope(null, (object)['type' => 'root'])];
        $this->fetchToken();
        $this->comment = null;
        $this->processPass();

        $this->pass = 2;
        $this->typeparser = new TypeParser($this->artifacts);
        $this->fileptr = $stackptr;
        $this->scopes = [new Scope(null, (object)['type' => 'root'])];
        $this->fetchToken();
        $this->comment = null;
        $this->processPass();

    }

    /**
     * A pass over the file.
     * @return void
     */
    public function processPass(): void {

        while ($this->token['code']) {
            try {

                // Skip irrelevant stuff.
                while (!in_array($this->token['code'], [
                                T_DOC_COMMENT_OPEN_TAG, T_NAMESPACE, T_USE,
                                T_ABSTRACT, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_READONLY, T_FINAL,
                                T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_FUNCTION, T_CLOSURE, T_VAR, T_CONST,
                                T_DECLARE,
                                T_SEMICOLON, null])
                            && (!isset($this->token['scope_opener']) || $this->token['scope_opener'] != $this->fileptr)
                            && (!isset($this->token['scope_closer']) || $this->token['scope_closer'] != $this->fileptr)) {
                    $this->advance(null, false);
                }

                // End of file.
                if (!$this->token['code']) {
                    break;
                }

                // Ignore protected/private function parameters.  // TODO: Ignore more?
                if (!end($this->scopes)->opened && in_array($this->token['code'], [T_PUBLIC, T_PROTECTED, T_PRIVATE])) {
                    $this->advance();
                    continue;
                }

                // Malformed prior declaration. // TODO: Remove?
                if (!end($this->scopes)->opened
                        && (!$this->token['code']
                            || !(isset($this->token['scope_opener']) && $this->token['scope_opener'] == $this->fileptr
                                || $this->token['code'] == T_SEMICOLON))) {
                    array_pop($this->scopes);
                    throw new \Exception();
                }

                // Comments.
                if ($this->token['code'] == T_DOC_COMMENT_OPEN_TAG) {
                    $this->processComment();
                    if (!in_array($this->token['code'], [
                                T_ABSTRACT, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_READONLY, T_FINAL,
                                T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_FUNCTION, T_CLOSURE, T_VAR, T_CONST,
                                T_DECLARE, // T_VARIABLE, // TODO: Remove that last one?
                                ])) {
                        $this->comment = null;
                        continue;
                    }
                } else {
                    $this->comment = null;
                }

                // Namespace.
                if ($this->token['code'] == T_NAMESPACE) {
                    $this->processNamespace();
                    continue;
                }

                // Use.
                if ($this->token['code'] == T_USE) {
                    if (end($this->scopes)->type != 'classish') {
                        $this->processUse();
                    } else {
                        $this->advance(T_USE, false);
                    }
                    continue;
                }

                // Scopes.
                if (isset($this->token['scope_opener']) && $this->token['scope_opener'] == $this->fileptr) {
                    if ($this->token['scope_closer'] == end($this->scopes)->closer) {
                        if (count($this->scopes) > 1) {
                            array_pop($this->scopes);
                        } else {
                            $this->advance(null, false);  // TODO: Push new?
                            throw new \Exception();
                        }
                    }
                    if (!end($this->scopes)->opened) {
                        end($this->scopes)->opened = true;
                    } else {
                        $oldscope = end($this->scopes);
                        array_push($this->scopes,
                            $newscope = new Scope($oldscope,
                                (object)['type' => 'other', 'closer' => $this->tokens[$this->fileptr]['scope_closer']]));
                    }
                    $this->advance(null, false);
                    continue;
                }
                if (isset($this->token['scope_closer']) && $this->token['scope_closer'] == $this->fileptr) {
                    if (count($this->scopes) > 1) {
                        array_pop($this->scopes);
                    } else {
                        $this->advance(null, false);
                        throw new \Exception();
                    }
                    $this->advance(null, false);
                    continue;
                }

                // Empty declarations and other semicolons.
                if ($this->token['code'] == T_SEMICOLON) {
                    if (!end($this->scopes)->opened) {
                        array_pop($this->scopes);
                    }
                    $this->advance(T_SEMICOLON, false);
                    continue;
                }

                // Declare.
                if ($this->token['code'] == T_DECLARE) {
                    $this->processDeclare();
                    continue;
                }

                // Declarations.
                while (in_array($this->token['code'],
                        [T_ABSTRACT, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_READONLY, T_FINAL])) {
                    $this->advance();
                }
                if (in_array($this->token['code'], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])) {
                    // Classish thing.
                    $this->processClassish();
                    continue;
                } elseif ($this->token['code'] == T_FUNCTION || $this->token['code'] == T_CLOSURE) {
                    // Function.
                    $this->processFunction();
                    continue;
                } else {
                    // Possible variable.
                    $this->processVariable();
                    continue;
                }

            } catch (\Exception $e) {
                // TODO: Remove.
                echo ($this->token['content']);
                $this->file->addError('Parse error',
                    $this->fileptr < count($this->tokens) ? $this->fileptr : $this->fileptr - 1, 'debug');
            }
        }

        if (!$this->token['code'] && count($this->scopes) != 1) {
            // TODO: Remove.
            $this->file->addError('Parse error',
                $this->fileptr < count($this->tokens) ? $this->fileptr : $this->fileptr - 1, 'debug');
        }

    }

    /**
     * Fetch the current tokens.
     * @return void
     */
    private function fetchToken(): void {
        $this->token = ($this->fileptr < count($this->tokens)) ? $this->tokens[$this->fileptr] : ['code' => null, 'content' => ''];
    }

    /**
     * Advance the token pointer.
     * @param mixed $expectedcode
     * @param bool $skipphpdoc
     * @return void
     */
    private function advance($expectedcode = null, $skipphpdoc = true): void {
        if ($expectedcode && $this->token['code'] != $expectedcode || $this->token['code'] == null) {
            throw new \Exception();
        }
        $nextptr = $this->fileptr + 1;
        while ($nextptr < count($this->tokens)
                && (in_array($this->tokens[$nextptr]['code'], [T_WHITESPACE, T_COMMENT])
                    || $skipphpdoc && in_array($this->tokens[$nextptr]['code'],
                        [T_DOC_COMMENT_OPEN_TAG, T_DOC_COMMENT_CLOSE_TAG, T_DOC_COMMENT_STAR,
                            T_DOC_COMMENT_TAG, T_DOC_COMMENT_STRING, T_DOC_COMMENT_WHITESPACE]))) {
            // TODO: Check unexpected PHPDoc comment.
            $nextptr++;
        }
        $this->fileptr = $nextptr;
        $this->fetchToken();
    }

    /**
     * Process a PHPDoc comment.
     * @return void
     */
    private function processComment(): void {
        $this->comment = new PHPDoc();
        while ($this->token['code'] != T_DOC_COMMENT_CLOSE_TAG) {
            $tagtype = null;
            $tagcontent = "";
            while (in_array($this->token['code'], [T_DOC_COMMENT_OPEN_TAG, T_DOC_COMMENT_STAR, T_DOC_COMMENT_WHITESPACE])) {
                $this->advance(null, false);
            }
            if ($this->token['code'] == T_DOC_COMMENT_TAG) {
                $tagtype = $this->token['content'];
                $this->advance(T_DOC_COMMENT_TAG, false);
            }
            while ($this->token['code'] != T_DOC_COMMENT_CLOSE_TAG
                    && !in_array(substr($this->token['content'], -1), ["\n", "\r"])) {
                $tagcontent .= $this->token['content'];
                $this->advance(null, false);
            }
            if (!isset($this->comment->tags[$tagtype])) {
                $this->comment->tags[$tagtype] = [];
            }
            $this->comment->tags[$tagtype][] = trim($tagcontent);
        }
        $this->advance(T_DOC_COMMENT_CLOSE_TAG, false);
    }

    /**
     * Process a namespace declaration.
     * @return void
     */
    private function processNamespace(): void {
        $this->advance(T_NAMESPACE);
        $namespace = '';
        while ($this->token && in_array($this->token['code'],
                    [T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE, T_NS_SEPARATOR, T_STRING])) {
            $namespace .= $this->token['content'];
            $this->advance();
        }
        while ($namespace != '' && $namespace[strlen($namespace) - 1] == '\\') {
            $namespace = substr($namespace, 0, strlen($namespace) - 1);
        }
        if ($namespace == '' || $namespace[0] != '\\') {
            $namespace = '\\' . $namespace;
        }
        if ($this->pass == 2) {
            $this->file->addWarning('Found namespace %s', $this->fileptr, 'debug', [$namespace]);
        }
        if ($this->token['code'] == T_SEMICOLON) {
            end($this->scopes)->namespace = $namespace;
            $this->advance(T_SEMICOLON, false);
        } else {
            $oldscope = end($this->scopes);
            array_push($this->scopes, $newscope = new Scope($oldscope, (object)['type' => 'namespace']));
        }
    }

    /**
     * Process a use declaration.
     * @return void
     */
    private function processUse(): void {
        $this->advance(T_USE);
        $more = false;
        do {
            $namespace = '';
            $type = 'class';
            if ($this->token['code'] == T_FUNCTION) {
                $type = 'function';
                $this->advance(T_FUNCTION);
            } elseif ($this->token['code'] == T_CONST) {
                $type = 'const';
                $this->advance(T_CONST);
            }
            while ($this->token && in_array($this->token['code'],
                        [T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE, T_NS_SEPARATOR, T_STRING])) {
                $namespace .= $this->token['content'];
                $this->advance();
            }
            if ($namespace == '' || $namespace[0] != '\\') {
                $namespace = '\\' . $namespace;
            }
            if ($this->token['code'] == T_OPEN_USE_GROUP || $this->token['code'] == T_OPEN_CURLY_BRACKET) {
                $namespacestart = $namespace;
                $typestart = $type;
                $this->advance();
                do {
                    $namespaceend = '';
                    $type = $typestart;
                    if ($this->token['code'] == T_FUNCTION) {
                        $type = 'function';
                        $this->advance(T_FUNCTION);
                    } elseif ($this->token['code'] == T_CONST) {
                        $type = 'const';
                        $this->advance(T_CONST);
                    }
                    while (
                                $this->token && in_array($this->token['code'],
                                    [T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE, T_NS_SEPARATOR, T_STRING])
                            ) {
                        $namespaceend .= $this->token['content'];
                        $this->advance();
                    }
                    $namespace = $namespacestart . $namespaceend;
                    $alias = substr($namespace, strrpos($namespace, '\\') + 1);
                    if ($this->token && $this->token['code'] == T_AS) {
                        $this->advance(T_AS);
                        if ($this->token && $this->token['code'] == T_STRING) {
                            $alias = $this->token['content'];
                            $this->advance(T_STRING);
                        }
                    }
                    if ($this->pass == 2 && $type == 'class') {
                        end($this->scopes)->uses[$alias] = $namespace;
                        $this->file->addWarning('Found use %s', $this->fileptr, 'debug', [$alias]);
                    }
                    $more = ($this->token && $this->token['code'] == T_COMMA);
                    if ($more) {
                        $this->advance(T_COMMA);
                    }
                } while ($more);
                if ($this->token['code'] != T_CLOSE_USE_GROUP && $this->token['code'] != T_CLOSE_CURLY_BRACKET) {
                    throw new \Exception();
                }
                $this->advance();
            } else {
                $alias = substr($namespace, strrpos($namespace, '\\') + 1);
                if ($this->token && $this->token['code'] == T_AS) {
                    $this->advance(T_AS);
                    if ($this->token && $this->token['code'] == T_STRING) {
                        $alias = $this->token['content'];
                        $this->advance(T_STRING);
                    }
                }
                if ($this->pass == 2&& $type == 'class') {
                    end($this->scopes)->uses[$alias] = $namespace;
                    $this->file->addWarning('Found use %s', $this->fileptr, 'debug', [$alias]);
                }
            }
            $more = ($this->token && $this->token['code'] == T_COMMA);
            if ($more) {
                $this->advance(T_COMMA);
            }
        } while ($more);
        $this->advance(T_SEMICOLON, false);
    }

    /**
     * Process a classish thing.
     * @return void
     */
    private function processClassish(): void {
        $name = $this->file->getDeclarationName($this->fileptr);
        $name = $name ? end($this->scopes)->namespace . "\\" . $name : null;
        $parent = $this->file->findExtendedClassName($this->fileptr);
        if ($parent && $parent[0] != "\\") {
            $parent = end($this->scopes)->namespace . "\\" . $parent;
        }
        $interfaces = $this->file->findImplementedInterfaceNames($this->fileptr);
        $interfaces = [];
        foreach ($interfaces as $index => $interface) {
            if ($interface && $interface[0] != "\\") {
                $interface[$index] = end($this->scopes)->namespace . "\\" . $interface;
            }
        }
        // Check not anonymous.
        $this->file->addWarning('Found classish %s', $this->fileptr, 'debug', [$name]);
        $oldscope = end($this->scopes);
        array_push($this->scopes, $newscope = new Scope($oldscope,
            (object)['type' => 'classish', 'classname' => $name, 'parentname' => $parent]));
        if ($this->pass == 1) {
            $this->artifacts[$name] = (object)['extends' => $parent, 'implements' => $interfaces];
        } elseif ($this->pass == 2) {
            if ($name && $this->comment && isset($this->comment->tags['@template'])) {
                foreach ($this->comment->tags['@template'] as $template) {
                    $this->file->addWarning('Found template %s', $this->fileptr, 'debug', [$template]);
                    // TODO: Store.
                }
            }
        }
        $this->advance();
        // Extends and implements.
        while (
                    in_array($this->token['code'],
                        [T_STRING, T_EXTENDS, T_IMPLEMENTS, T_COMMA,
                        T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE, T_NS_SEPARATOR])
                ) {
            $this->advance();
        }
        // Body start.
        if (!$this->token || !($this->token['code'] == T_SEMICOLON
                    || (isset($this->token['scope_opener']) && $this->token['scope_opener'] == $this->fileptr))) {
            throw new \Exception();
        }
    }

    /**
     * Process a function.
     * @return void
     */
    private function processFunction(): void {
        // Check not anonymous.
        $name = $this->file->getDeclarationName($this->fileptr);
        $parameters = $this->file->getMethodParameters($this->fileptr);
        $properties = $this->file->getMethodProperties($this->fileptr);
        $oldscope = end($this->scopes);
        array_push($this->scopes, $newscope = new Scope($oldscope, (object)['type' => 'function']));

        if ($this->pass == 2) {
            $this->file->addWarning('Found function %s params %s return %s', $this->fileptr, 'debug',
                [$name, count($parameters), $properties['return_type']]);
            if ($this->comment && isset($parameters)) {
                if (!isset($this->comment->tags['@param'])) {
                    $this->comment->tags['@param'] = [];
                }
                if (count($this->comment->tags['@param']) != count($parameters)) {
                    $this->file->addWarning('PHPDoc number of function parameters doesn\'t match actual number',
                        $this->fileptr, 'phpdoc_fun_param_count_wrong');
                }
                for ($varnum = 0; $varnum < count($this->comment->tags['@param']); $varnum++) {
                    if ($varnum < count($parameters)) {
                        $this->file->addWarning('PHP param %s vs PHPDoc param %s',
                            $this->fileptr, 'debug', [$parameters[$varnum]['content'], $this->comment->tags['@param'][$varnum]]);
                    }
                }
            }
            if ($this->comment && isset($properties)) {
                if (!isset($this->comment->tags['@return'])) {
                    $this->comment->tags['@return'] = [];
                }
                if (count($this->comment->tags['@return']) != 1) {  // TODO: What about __construct ?
                    $this->file->addWarning('PHPDoc missing or multiple function return types',
                        $this->fileptr, 'phpdoc_fun_ret_count_wrong');
                }
                if ($properties['return_type']) {
                    for ($retnum = 0; $retnum < count($this->comment->tags['@return']); $retnum++) {
                        $this->file->addWarning('PHP ret %s vs PHPDoc ret %s',
                            $this->fileptr, 'debug', [$properties['return_type'], $this->comment->tags['@return'][$retnum]]);
                    }
                }
            }
        }

        $this->advance();
        if ($this->token['code'] == T_BITWISE_AND) {
            $this->advance(T_BITWISE_AND);
        }
        // Function name.
        if ($this->token['code'] == T_STRING) {
            $this->advance(T_STRING);
        }
        // Parameters, use, and return.
        if (!$this->token || $this->token['code'] != T_OPEN_PARENTHESIS) {
            throw new \Exception();
        }
        // TODO: Give up on this.
        while (in_array($this->token['code'],
                [// Brackets and return seperator.
                T_OPEN_PARENTHESIS, T_CLOSE_PARENTHESIS, T_COLON,
                // Visibility.
                T_PUBLIC, T_PROTECTED, T_PRIVATE,
                // Type.
                T_TYPE_UNION, T_TYPE_INTERSECTION, T_NULLABLE, T_OPEN_PARENTHESIS, T_CLOSE_PARENTHESIS,
                T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE, T_NS_SEPARATOR, T_STRING,
                T_NULL, T_ARRAY, T_OBJECT, T_SELF, T_PARENT, T_FALSE, T_TRUE, T_CALLABLE, T_STATIC,
                // Variable name.
                T_ELLIPSIS, T_VARIABLE, T_BITWISE_AND,
                // Default value.
                T_EQUAL, T_OPEN_SHORT_ARRAY, T_CLOSE_SHORT_ARRAY, T_ARRAY, T_OPEN_PARENTHESIS, T_CLOSE_PARENTHESIS,
                T_COMMA, T_DOUBLE_ARROW,
                T_NULL, T_MINUS, T_LNUMBER, T_DNUMBER, T_CONSTANT_ENCAPSED_STRING, T_TRUE, T_FALSE, T_STRING,
                T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE, T_NS_SEPARATOR, T_STRING, T_SELF, T_DOUBLE_COLON,
                T_DIR, T_CLASS_C,
                T_MULTIPLY, T_DIVIDE, T_STRING_CONCAT, T_LESS_THAN, T_INLINE_THEN, T_INLINE_ELSE, T_BOOLEAN_AND, T_POW,
                T_OPEN_SQUARE_BRACKET, T_CLOSE_SQUARE_BRACKET,
                T_START_HEREDOC, T_HEREDOC, T_END_HEREDOC, T_START_NOWDOC, T_NOWDOC, T_END_NOWDOC,
                // Use.
                T_USE, T_OPEN_PARENTHESIS, T_CLOSE_PARENTHESIS, T_COMMA, T_BITWISE_AND, T_VARIABLE])) {
            $this->advance();
        }
        // Body start.
        if (!($this->token['code'] == T_SEMICOLON
                    || (isset($this->token['scope_opener']) && $this->token['scope_opener'] == $this->fileptr))) {
            throw new \Exception();
        }
    }

    /**
     * Process a possible variable.
     * @return void
     */
    private function processVariable(): void {

        // Parse var/const token.
        $definitelyvar = false;
        $const = ($this->token['code'] == T_CONST);
        if ($const) {
            $definitelyvar = true;
            $this->advance(T_CONST);
        } elseif ($this->token['code'] == T_VAR) {
            $definitelyvar = true;
            $this->advance(T_VAR);
        }

        // Parse type.  TODO: Check if there is type info.
        if (!$const) {
            while (in_array($this->token['code'],
                    [T_TYPE_UNION, T_TYPE_INTERSECTION, T_NULLABLE, T_OPEN_PARENTHESIS, T_CLOSE_PARENTHESIS,
                    T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE, T_NS_SEPARATOR, T_STRING,
                    T_NULL, T_ARRAY, T_OBJECT, T_SELF, T_PARENT, T_FALSE, T_TRUE, T_CALLABLE, T_STATIC, ])) {
                $this->advance();
            }
        }

        // Check is probably variable.
        if (!$definitelyvar && !in_array($this->token['code'], [T_STRING, T_VARIABLE])) {
            return;
        }

        $more = false;
        do {  // TODO: Give up on multiple?
            // Check name.
            if ($definitelyvar && $this->token['code'] != ($const ? T_STRING : T_VARIABLE)) {
                throw new \Exception();
            }

            // Important stuff.
            $name = $this->token['content'];
            $properties = $this->file->getMemberProperties($this->fileptr);

            if ($this->pass == 2) {
                $this->file->addWarning('Found variable %s', $this->fileptr, 'debug', [$name]);
                if (isset($properties) && $this->comment) {
                    if (!isset($this->comment->tags['@var'])) {
                        $this->comment->tags['@var'] = [];
                    }
                    if (count($this->comment->tags['@var']) != 1) {
                        $this->file->addWarning('PHPDoc missing or multiple var types', $this->fileptr, 'phpdoc_var_count_wrong');
                    }
                    if ($properties['type']) {
                        for ($varnum = 0; $varnum < count($this->comment->tags['@var']); $varnum++) {
                            $this->file->addWarning('PHP var %s vs PHPDoc var %s',
                                $this->fileptr, 'debug', [$properties['type'], $this->comment->tags['@var'][$varnum]]);
                        }
                    }
                }
            }

            $this->advance();

            // Check is actually variable.
            if (!$definitelyvar && !in_array($this->token['code'], [T_EQUAL, T_COMMA, T_SEMICOLON])) {
                return;
            }

            // TODO: Require PHPDoc only in classish scope.

            return;  // Give up.  TODO: Tidy.

            // Parse default value.  // TODO: Balance brackets, so we don't consume trailing comma? // TODO: Give up.
            if ($this->token['code'] == T_EQUAL) {
                $this->advance(T_EQUAL);
                while (in_array($this->token['code'],
                        [T_OPEN_SHORT_ARRAY, T_CLOSE_SHORT_ARRAY, T_ARRAY, T_OPEN_PARENTHESIS, T_CLOSE_PARENTHESIS,
                        T_COMMA, T_DOUBLE_ARROW,
                        T_NULL, T_MINUS, T_LNUMBER, T_DNUMBER, T_CONSTANT_ENCAPSED_STRING, T_TRUE, T_FALSE, T_STRING,
                        T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE, T_NS_SEPARATOR, T_STRING, T_SELF, T_DOUBLE_COLON,
                        T_DIR, T_CLASS_C,
                        T_MULTIPLY, T_DIVIDE, T_STRING_CONCAT, T_LESS_THAN, T_INLINE_THEN, T_INLINE_ELSE, T_BOOLEAN_AND, T_POW,
                        T_OPEN_SQUARE_BRACKET, T_CLOSE_SQUARE_BRACKET,
                        T_START_HEREDOC, T_HEREDOC, T_END_HEREDOC, T_START_NOWDOC, T_NOWDOC, T_END_NOWDOC])) {
                    $this->advance();
                }
            }

            $more = ($this->token['code'] == T_COMMA || $this->token['code'] == T_VARIABLE);
            if ($more && $this->token['code'] == T_COMMA) {
                $this->advance(T_COMMA);
            }

        } while ($more);

        $this->advance(T_SEMICOLON, false);

    }

    /**
     * Process a declare.
     * @return void
     */
    private function processDeclare(): void {

        $this->advance(T_DECLARE);
        $this->advance(T_OPEN_PARENTHESIS);
        $this->advance(T_STRING);
        $this->advance(T_EQUAL);

        // Value.  // TODO: Give up on this.
        while (in_array($this->token['code'],
                [T_OPEN_SHORT_ARRAY, T_CLOSE_SHORT_ARRAY, T_ARRAY, T_OPEN_PARENTHESIS, T_CLOSE_PARENTHESIS,
                T_COMMA, T_DOUBLE_ARROW,
                T_NULL, T_MINUS, T_LNUMBER, T_DNUMBER, T_CONSTANT_ENCAPSED_STRING, T_TRUE, T_FALSE, T_STRING,
                T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE, T_NS_SEPARATOR, T_STRING, T_DOUBLE_COLON,
                T_MULTIPLY, T_DIVIDE, T_STRING_CONCAT, T_LESS_THAN, T_INLINE_THEN, T_INLINE_ELSE, T_BOOLEAN_AND, T_POW,
                T_OPEN_SQUARE_BRACKET, T_CLOSE_SQUARE_BRACKET,
                T_START_HEREDOC, T_HEREDOC, T_END_HEREDOC,  T_START_NOWDOC, T_NOWDOC, T_END_NOWDOC])) {
            $this->advance();
        }

        $this->advance(T_SEMICOLON, false);

    }

}

/**
 * Information about program scopes.
 */
class Scope
{

    /** @var ?string the type of scope */
    public ?string $type = null;

    /** @var string current namespace */
    public string $namespace = '\\';

    /** @var array<string, string> use definitions by name */
    public array $uses = [];

    /** @var array<string, string> template definitions by name */
    public array $templates = [];

    /** @var ?string */
    public ?string $classname = null;

    /** @var ?string */
    public ?string $parentname = null;

    /** @var bool has the scope been properly opened yet? */
    public bool $opened = true;

    /** @var ?int the scope closer */
    public ?int $closer = null;

    /**
     * Construct scope information
     * @param ?Scope $oldscope the enclosing scope
     * @param ?object{type: ?string, closer: int} $overrides
     */
    public function __construct(?Scope $oldscope = null, ?object $overrides = null) {
        if ($oldscope) {
            $this->namespace = $oldscope->namespace;
            $this->uses = $oldscope->uses;
            $this->templates = $oldscope->templates;
            $this->classname = $oldscope->classname;
            $this->parentname = $oldscope->parentname;
        }
        if (!$overrides) {
            $overrides = (object)[];
        }
        $this->type = $overrides->type ?? 'other';
        $this->opened = ($this->type == 'root' || $this->type == 'other');
        $this->closer = $overrides->closer ?? null;
        if ($this->type == 'classish') {
            $this->classname = $overrides->classname ?? null;
            $this->parentname = $overrides->parentname ?? null;
        }
    }

}

/**
 * Information about PHPDoc comments
 */
class PHPDoc
{

    /** @var array<string, string[]> */
    public array $tags = [];

}

/**
 * Type parser
 *
 * Checks that PHPDoc types are well formed, and returns a simplified version if so, or null otherwise.
 * Global constants and the Collection|Type[] construct, aren't supported.
 *
 * @package     local_moodlecheck
 * @copyright   2023 Otago Polytechnic
 * @author      James Calder
 */
class TypeParser
{

    /** @var array<non-empty-string, non-empty-string[]> predefined and SPL classes */
    protected array $library = [
        // Predefined general.
        "\\ArrayAccess" => [],
        "\\BackedEnum" => ["\\UnitEnum"],
        "\\Closure" => ["callable"],
        "\\Directory" => [],
        "\\Fiber" => [],
        "\\php_user_filter" => [],
        "\\SensitiveParameterValue" => [],
        "\\Serializable" => [],
        "\\stdClass" => [],
        "\\Stringable" => [],
        "\\UnitEnum" => [],
        "\\WeakReference" => [],
        // Predefined iterables.
        "\\Generator" => ["\\Iterator"],
        "\\InternalIterator" => ["\\Iterator"],
        "\\Iterator" => ["\\Traversable"],
        "\\IteratorAggregate" => ["\\Traversable"],
        "\\Traversable" => ["iterable"],
        "\\WeakMap" => ["\\ArrayAccess", "\\Countable", "\\Iteratoraggregate"],
        // Predefined throwables.
        "\\ArithmeticError" => ["\\Error"],
        "\\AssertionError" => ["\\Error"],
        "\\CompileError" => ["\\Error"],
        "\\DivisionByZeroError" => ["\\ArithmeticError"],
        "\\Error" => ["\\Throwable"],
        "\\ErrorException" => ["\\Exception"],
        "\\Exception" => ["\\Throwable"],
        "\\ParseError" => ["\\CompileError"],
        "\\Throwable" => ["\\Stringable"],
        "\\TypeError" => ["\\Error"],
        // SPL Data structures.
        "\\SplDoublyLinkedList" => ["\\Iterator", "\\Countable", "\\ArrayAccess", "\\Serializable"],
        "\\SplStack" => ["\\SplDoublyLinkedList"],
        "\\SplQueue" => ["\\SplDoublyLinkedList"],
        "\\SplHeap" => ["\\Iterator", "\\Countable"],
        "\\SplMaxHeap" => ["\\SplHeap"],
        "\\SplMinHeap" => ["\\SplHeap"],
        "\\SplPriorityQueue" => ["\\Iterator", "\\Countable"],
        "\\SplFixedArray" => ["\\IteratorAggregate", "\\ArrayAccess", "\\Countable", "\\JsonSerializable"],
        "\\Splobjectstorage" => ["\\Countable", "\\Iterator", "\\Serializable", "\\Arrayaccess"],
        // SPL iterators.
        "\\AppendIterator" => ["\\IteratorIterator"],
        "\\ArrayIterator" => ["\\SeekableIterator", "\\ArrayAccess", "\\Serializable", "\\Countable"],
        "\\CachingIterator" => ["\\IteratorIterator", "\\ArrayAccess", "\\Countable", "\\Stringable"],
        "\\CallbackFilterIterator" => ["\\FilterIterator"],
        "\\DirectoryIterator" => ["\\SplFileInfo", "\\SeekableIterator"],
        "\\EmptyIterator" => ["\\Iterator"],
        "\\FilesystemIterator" => ["\\DirectoryIterator"],
        "\\FilterIterator" => ["\\IteratorIterator"],
        "\\GlobalIterator" => ["\\FilesystemIterator", "\\Countable"],
        "\\InfiniteIterator" => ["\\IteratorIterator"],
        "\\IteratorIterator" => ["\\OuterIterator"],
        "\\LimitIterator" => ["\\IteratorIterator"],
        "\\MultipleIterator" => ["\\Iterator"],
        "\\NoRewindIterator" => ["\\IteratorIterator"],
        "\\ParentIterator" => ["\\RecursiveFilterIterator"],
        "\\RecursiveArrayIterator" => ["\\ArrayIterator", "\\RecursiveIterator"],
        "\\RecursiveCachingIterator" => ["\\CachingIterator", "\\RecursiveIterator"],
        "\\RecursiveCallbackFilterIterator" => ["\\CallbackFilterIterator", "\\RecursiveIterator"],
        "\\RecursiveDirectoryIterator" => ["\\FilesystemIterator", "\\RecursiveIterator"],
        "\\RecursiveFilterIterator" => ["\\FilterIterator", "\\RecursiveIterator"],
        "\\RecursiveIteratorIterator" => ["\\OuterIterator"],
        "\\RecursiveRegexIterator" => ["\\RegexIterator", "\\RecursiveIterator"],
        "\\RecursiveTreeIterator" => ["\\RecursiveIteratorIterator"],
        "\\RegexIterator" => ["\\FilterIterator"],
        // SPL interfaces.
        "\\Countable" => [],
        "\\OuterIterator" => ["\\Iterator"],
        "\\RecursiveIterator" => ["\\Iterator"],
        "\\SeekableIterator" => ["\\Iterator"],
        // SPL exceptions.
        "\\BadFunctionCallException" => ["\\LogicException"],
        "\\BadMethodCallException" => ["\\BadFunctionCallException"],
        "\\DomainException" => ["\\LogicException"],
        "\\InvalidArgumentException" => ["\\LogicException"],
        "\\LengthException" => ["\\LogicException"],
        "\\LogicException" => ["\\Exception"],
        "\\OutOfBoundsException" => ["\\RuntimeException"],
        "\\OutOfRangeException" => ["\\LogicException"],
        "\\OverflowException" => ["\\RuntimeException"],
        "\\RangeException" => ["\\RuntimeException"],
        "\\RuntimeException" => ["\\Exception"],
        "\\UnderflowException" => ["\\RuntimeException"],
        "\\UnexpectedValueException" => ["\\RuntimeException"],
        // SPL file handling.
        "\\SplFileInfo" => ["\\Stringable"],
        "\\SplFileObject" => ["\\SplFileInfo", "\\RecursiveIterator", "\\SeekableIterator"],
        "\\SplTempFileObject" => ["\\SplFileObject"],
        // SPL misc.
        "\\ArrayObject" => ["\\IteratorAggregate", "\\ArrayAccess", "\\Serializable", "\\Countable"],
        "\\SplObserver" => [],
        "\\SplSubject" => [],
    ];

    /** @var string $namespace */
    protected string $namespace = "\\";

    /** @var array<non-empty-string, non-empty-string> use aliases, aliases are keys, class names are values */
    protected array $usealiases;

    /** @var array<non-empty-string, object{extends: ?non-empty-string, implements: non-empty-string[]}> inheritance heirarchy */
    protected array $artifacts;

    /** @var string the text to be parsed */
    protected string $text = '';

    /** @var string the text to be parsed, with case retained */
    protected string $textwithcase = '';

    /** @var bool when we encounter an unknown type, should we go wide or narrow */
    protected bool $gowide = false;

    /** @var  array<string, string> type templates */
    protected array $templates = [];

    /** @var object{startpos: non-negative-int, endpos: non-negative-int, text: ?non-empty-string}[] next tokens */
    protected array $nexts = [];

    /** @var ?non-empty-string the next token */
    protected string $next = null;

    /**
     * Constructor
     * @param ?array $artifacts
     */
    public function __construct(?array $artifacts = null) {
        $this->artifacts = $artifacts ?? [];
    }

    /**
     * Parse a type and possibly variable name
     * @param ?Scope $scope
     * @param string $text the text to parse
     * @param 0|1|2|3 $getwhat what to get 0=type only 1=also var 2=also modifiers (& ...) 3=also default
     * @param bool $gowide if we can't determine the type, should we assume wide (for native type) or narrow (for PHPDoc)?
     * @return object{type: ?non-empty-string, var: ?non-empty-string, rem: string, nullable: bool}
     *          the simplified type, variable, remaining text, and whether the type is explicitly nullable
     */
    public function parseTypeAndVar(?Scope $scope,
            string $text, int $getwhat, bool $gowide): object {

        // Initialise variables.
        if ($scope) {
            $this->namespace = $scope->namespace;
            $this->templates = $scope->templates;
            $this->usealiases = $scope->uses;
        } else {
            $this->namespace = "\\";
            $this->templates = [];
            $this->usealiases = [];
        }
        $this->text = $text;
        $this->textwithcase = $text;
        $this->gowide = $gowide;
        $this->nexts = [];
        $this->next = $this->next();

        // Try to parse type.
        $savednexts = $this->nexts;
        try {
            $type = $this->parseAnyType();
            $explicitnullable = strpos("|{$type}|", "|null|") !== false; // For code smell check.
            if (!($this->next == null || $getwhat >= 1
                    || ctype_space(substr($this->text, $this->nexts[0]->startpos - 1, 1))
                    || in_array($this->next, [',', ';', ':', '.']))) {
                // Code smell check.
                throw new \Exception("Warning parsing type, no space after type.");
            }
        } catch (\Exception $e) {
            $this->nexts = $savednexts;
            $this->next = $this->next();
            $type = null;
            $explicitnullable = false;
        }

        // Try to parse variable.
        if ($getwhat >= 1) {
            $savednexts = $this->nexts;
            try {
                $variable = '';
                if ($getwhat >= 2) {
                    if ($this->next == '&') {
                        // Not adding this for code smell check,
                        // because the checker previously disallowed pass by reference & in PHPDocs,
                        // so adding this would be a nusiance for people who changed their PHPDocs
                        // to conform to the previous rules.
                        $this->parseToken('&');
                    }
                    if ($this->next == '...') {
                        // Add to variable name for code smell check.
                        $variable .= $this->parseToken('...');
                    }
                }
                if (!($this->next != null && $this->next[0] == '$')) {
                    throw new \Exception("Error parsing type, expected variable, saw \"{$this->next}\".");
                }
                $variable .= $this->next(0, true);
                assert($variable != '');
                $this->parseToken();
                if (!($this->next == null || $getwhat >= 3 && $this->next == '='
                        || ctype_space(substr($this->text, $this->nexts[0]->startpos - 1, 1))
                        || in_array($this->next, [',', ';', ':', '.']))) {
                    // Code smell check.
                    throw new \Exception("Warning parsing type, no space after variable name.");
                }
                if ($getwhat >= 3) {
                    if ($this->next == '=' && $this->next(1) == 'null' && $type != null) {
                        $type = $type . '|null';
                    }
                }
            } catch (\Exception $e) {
                $this->nexts = $savednexts;
                $this->next = $this->next();
                $variable = null;
            }
        } else {
            $variable = null;
        }

        return (object)['type' => $type, 'var' => $variable,
                        'rem' => trim(substr($text, $this->nexts[0]->startpos)), 'nullable' => $explicitnullable];
    }

    /**
     * Substitute owner and parent names
     * @param non-empty-string $type the simplified type
     * @param ?non-empty-string $ownername
     * @param ?non-empty-string $parentname
     * @return non-empty-string
     */
    public static function substituteNames(string $type, ?string $ownername, ?string $parentname): string {
        if ($ownername) {
            $type = preg_replace('/\bself\b/', $ownername, $type);
            assert($type != null);
            $type = preg_replace('/\bstatic\b/', "static({$ownername})", $type);
            assert($type != null);
        }
        if ($parentname) {
            $type = preg_replace('/\bparent\b/', $parentname, $type);
            assert($type != null);
        }
        return $type;
    }

    /**
     * Compare types
     * @param ?non-empty-string $widetype the type that should be wider, e.g. PHP type
     * @param ?non-empty-string $narrowtype the type that should be narrower, e.g. PHPDoc type
     * @return bool whether $narrowtype has the same or narrower scope as $widetype
     */
    public function compareTypes(?string $widetype, ?string $narrowtype): bool {
        if ($narrowtype == null) {
            return false;
        } elseif ($widetype == null || $widetype == 'mixed' || $narrowtype == 'never') {
            return true;
        }

        $wideintersections = explode('|', $widetype);
        $narrowintersections = explode('|', $narrowtype);

        // We have to match all narrow intersections.
        $haveallintersections = true;
        foreach ($narrowintersections as $narrowintersection) {
            $narrowsingles = explode('&', $narrowintersection);

            // If the wide types are super types, that should match.
            $narrowadditions = [];
            foreach ($narrowsingles as $narrowsingle) {
                assert($narrowsingle != '');
                $supertypes = $this->superTypes($narrowsingle);
                $narrowadditions = array_merge($narrowadditions, $supertypes);
            }
            $narrowsingles = array_merge($narrowsingles, $narrowadditions);
            sort($narrowsingles);
            $narrowsingles = array_unique($narrowsingles);

            // We need to look in each wide intersection.
            $havethisintersection = false;
            foreach ($wideintersections as $wideintersection) {
                $widesingles = explode('&', $wideintersection);

                // And find all parts of one of them.
                $haveallsingles = true;
                foreach ($widesingles as $widesingle) {

                    if (!in_array($widesingle, $narrowsingles)) {
                        $haveallsingles = false;
                        break;
                    }

                }
                if ($haveallsingles) {
                    $havethisintersection = true;
                    break;
                }
            }
            if (!$havethisintersection) {
                $haveallintersections = false;
                break;
            }
        }
        return $haveallintersections;
    }

    /**
     * Get super types
     * @param non-empty-string $basetype
     * @return non-empty-string[] super types
     */
    protected function superTypes(string $basetype): array {
        if (in_array($basetype, ['int', 'string'])) {
            $supertypes = ['array-key', 'scaler'];
        } elseif ($basetype == 'callable-string') {
            $supertypes = ['callable', 'string', 'array-key', 'scalar'];
        } elseif (in_array($basetype, ['array-key', 'float', 'bool'])) {
            $supertypes = ['scalar'];
        } elseif ($basetype == 'array') {
            $supertypes = ['iterable'];
        } elseif ($basetype == 'static') {
            $supertypes = ['self', 'parent', 'object'];
        } elseif ($basetype == 'self') {
            $supertypes = ['parent', 'object'];
        } elseif ($basetype == 'parent') {
            $supertypes = ['object'];
        } elseif (strpos($basetype, 'static(') === 0 || $basetype[0] == "\\") {
            if (strpos($basetype, 'static(') === 0) {
                $supertypes = ['static', 'self', 'parent', 'object'];
                $supertypequeue = [substr($basetype, 7, -1)];
                $ignore = false;
            } else {
                $supertypes = ['object'];
                $supertypequeue = [$basetype];
                $ignore = true;
            }
            while ($supertype = array_shift($supertypequeue)) {
                if (in_array($supertype, $supertypes)) {
                    $ignore = false;
                    continue;
                }
                if (!$ignore) {
                    $supertypes[] = $supertype;
                }
                if ($librarysupers = $this->library[$supertype] ?? null) {
                    $supertypequeue = array_merge($supertypequeue, $librarysupers);
                } elseif ($supertypeobj = $this->artifacts[$supertype] ?? null) {
                    if ($supertypeobj->extends) {
                        $supertypequeue[] = $supertypeobj->extends;
                    }
                    if (count($supertypeobj->implements) > 0) {
                        foreach ($supertypeobj->implements as $implements) {
                            $supertypequeue[] = $implements;
                        }
                    }
                } elseif (!$ignore) {
                    $supertypes = array_merge($supertypes, $this->superTypes($supertype));
                }
                $ignore = false;
            }
            $supertypes = array_unique($supertypes);
        } else {
            $supertypes = [];
        }
        return $supertypes;
    }

    /**
     * Prefetch next token
     * @param non-negative-int $lookahead
     * @param bool $getcase
     * @return ?non-empty-string
     */
    protected function next(int $lookahead = 0, bool $getcase = false): ?string {

        // Fetch any more tokens we need.
        while (count($this->nexts) < $lookahead + 1) {

            $startpos = $this->nexts ? end($this->nexts)->endpos : 0;
            $stringunterminated = false;

            // Ignore whitespace.
            while ($startpos < strlen($this->text) && ctype_space($this->text[$startpos])) {
                $startpos++;
            }

            $firstchar = ($startpos < strlen($this->text)) ? $this->text[$startpos] : null;

            // Deal with different types of tokens.
            if ($firstchar == null) {
                // No more tokens.
                $endpos = $startpos;
            } elseif (ctype_alpha($firstchar) || $firstchar == '_' || $firstchar == '$' || $firstchar == '\\') {
                // Identifier token.
                $endpos = $startpos;
                do {
                    $endpos = $endpos + 1;
                    $nextchar = ($endpos < strlen($this->text)) ? $this->text[$endpos] : null;
                } while ($nextchar != null && (ctype_alnum($nextchar) || $nextchar == '_'
                                            || $firstchar != '$' && ($nextchar == '-' || $nextchar == '\\')));
            } elseif (ctype_digit($firstchar)
                        || $firstchar == '-' && strlen($this->text) >= $startpos + 2 && ctype_digit($this->text[$startpos + 1])) {
                // Number token.
                $nextchar = $firstchar;
                $havepoint = false;
                $endpos = $startpos;
                do {
                    $havepoint = $havepoint || $nextchar == '.';
                    $endpos = $endpos + 1;
                    $nextchar = ($endpos < strlen($this->text)) ? $this->text[$endpos] : null;
                } while ($nextchar != null && (ctype_digit($nextchar) || $nextchar == '.' && !$havepoint || $nextchar == '_'));
            } elseif ($firstchar == '"' || $firstchar == '\'') {
                // String token.
                $endpos = $startpos + 1;
                $nextchar = ($endpos < strlen($this->text)) ? $this->text[$endpos] : null;
                while ($nextchar != $firstchar && $nextchar != null) { // There may be unterminated strings.
                    if ($nextchar == '\\' && strlen($this->text) >= $endpos + 2) {
                        $endpos = $endpos + 2;
                    } else {
                        $endpos++;
                    }
                    $nextchar = ($endpos < strlen($this->text)) ? $this->text[$endpos] : null;
                }
                if ($nextchar != null) {
                    $endpos++;
                } else {
                    $stringunterminated = true;
                }
            } elseif (strlen($this->text) >= $startpos + 3 && substr($this->text, $startpos, 3) == '...') {
                // Splat.
                $endpos = $startpos + 3;
            } elseif (strlen($this->text) >= $startpos + 2 && substr($this->text, $startpos, 2) == '::') {
                // Scope resolution operator.
                $endpos = $startpos + 2;
            } else {
                // Other symbol token.
                $endpos = $startpos + 1;
            }

            // Store token.
            $next = substr($this->text, $startpos, $endpos - $startpos);
            assert($next !== false);
            if ($stringunterminated) {
                // If we have an unterminated string, we've reached the end of usable tokens.
                $next = '';
            }
            $this->nexts[] = (object)['startpos' => $startpos, 'endpos' => $endpos,
                'text' => ($next !== '') ? $next : null, ];
        }

        // Return the needed token.
        return $this->nexts[$lookahead]->text;
    }

    /**
     * Fetch the next token
     * @param ?non-empty-string $expect the expected text
     * @return non-empty-string
     */
    protected function parseToken(?string $expect = null): string {

        $next = $this->next;

        // Check we have the expected token.
        if ($expect != null && strtolower($next) != strtolower($expect)) {
            throw new \Exception("Error parsing type, expected \"{$expect}\", saw \"{$next}\".");
        } elseif ($next == null) {
            throw new \Exception("Error parsing type, unexpected end.");
        }

        // Prefetch next token.
        $this->next(1);

        // Return consumed token.
        array_shift($this->nexts);
        $this->next = $this->next();
        return $next;
    }

    /**
     * Parse a list of types seperated by | and/or &, single nullable type, or conditional return type
     * @param bool $inbrackets are we immediately inside brackets?
     * @return non-empty-string the simplified type
     */
    protected function parseAnyType(bool $inbrackets = false): string {

        if ($inbrackets && $this->next !== null && $this->next[0] == '$' && $this->next(1) == 'is') {
            // Conditional return type.
            $this->parseToken();
            $this->parseToken('is');
            $this->parseAnyType();
            $this->parseToken('?');
            $firsttype = $this->parseAnyType();
            $this->parseToken(':');
            $secondtype = $this->parseAnyType();
            $uniontypes = array_merge(explode('|', $firsttype), explode('|', $secondtype));
        } elseif ($this->next == '?') {
            // Single nullable type.
            $this->parseToken('?');
            $uniontypes = explode('|', $this->parseSingleType());
            $uniontypes[] = 'null';
        } else {
            // Union list.
            $uniontypes = [];
            do {
                // Intersection list.
                $unioninstead = null;
                $intersectiontypes = [];
                do {
                    $singletype = $this->parseSingleType();
                    if (strpos($singletype, '|') !== false) {
                        $intersectiontypes[] = $this->gowide ? 'mixed' : 'never';
                        $unioninstead = $singletype;
                    } else {
                        $intersectiontypes = array_merge($intersectiontypes, explode('&', $singletype));
                    }
                    // We have to figure out whether a & is for intersection or pass by reference.
                    $nextnext = $this->next(1);
                    $havemoreintersections = $this->next == '&'
                        && !(in_array($nextnext, ['...', '=', ',', ')', null])
                            || $nextnext != null && $nextnext[0] == '$');
                    if ($havemoreintersections) {
                        $this->parseToken('&');
                    }
                } while ($havemoreintersections);
                if (count($intersectiontypes) > 1 && $unioninstead !== null) {
                    throw new \Exception("Error parsing type, non-DNF.");
                } elseif (count($intersectiontypes) <= 1 && $unioninstead !== null) {
                    $uniontypes = array_merge($uniontypes, explode('|', $unioninstead));
                } else {
                    // Tidy and store intersection list.
                    if (count($intersectiontypes) > 1) {
                        foreach ($intersectiontypes as $intersectiontype) {
                            assert($intersectiontype != '');
                            $supertypes = $this->superTypes($intersectiontype);
                            if (!(in_array($intersectiontype, ['object', 'iterable', 'callable'])
                                    || in_array('object', $supertypes))) {
                                throw new \Exception("Error parsing type, intersection can only be used with objects.");
                            }
                            foreach ($supertypes as $supertype) {
                                $superpos = array_search($supertype, $intersectiontypes);
                                if ($superpos !== false) {
                                    unset($intersectiontypes[$superpos]);
                                }
                            }
                        }
                        sort($intersectiontypes);
                        $intersectiontypes = array_unique($intersectiontypes);
                        $neverpos = array_search('never', $intersectiontypes);
                        if ($neverpos !== false) {
                            $intersectiontypes = ['never'];
                        }
                        $mixedpos = array_search('mixed', $intersectiontypes);
                        if ($mixedpos !== false && count($intersectiontypes) > 1) {
                            unset($intersectiontypes[$mixedpos]);
                        }
                    }
                    array_push($uniontypes, implode('&', $intersectiontypes));
                }
                // Check for more union items.
                $havemoreunions = $this->next == '|';
                if ($havemoreunions) {
                    $this->parseToken('|');
                }
            } while ($havemoreunions);
        }

        // Tidy and return union list.
        if (count($uniontypes) > 1) {
            if (in_array('int', $uniontypes) && in_array('string', $uniontypes)) {
                $uniontypes[] = 'array-key';
            }
            if (in_array('bool', $uniontypes) && in_array('float', $uniontypes) && in_array('array-key', $uniontypes)) {
                $uniontypes[] = 'scalar';
            }
            if (in_array("\\Traversable", $uniontypes) && in_array('array', $uniontypes)) {
                $uniontypes[] = 'iterable';
            }
            sort($uniontypes);
            $uniontypes = array_unique($uniontypes);
            $mixedpos = array_search('mixed', $uniontypes);
            if ($mixedpos !== false) {
                $uniontypes = ['mixed'];
            }
            $neverpos = array_search('never', $uniontypes);
            if ($neverpos !== false && count($uniontypes) > 1) {
                unset($uniontypes[$neverpos]);
            }
            foreach ($uniontypes as $uniontype) {
                assert($uniontype != '');
                foreach ($uniontypes as $key => $uniontype2) {
                    assert($uniontype2 != '');
                    if ($uniontype2 != $uniontype && $this->compareTypes($uniontype, $uniontype2)) {
                        unset($uniontypes[$key]);
                    }
                }
            }
        }
        $type = implode('|', $uniontypes);
        assert($type != '');
        return $type;

    }

    /**
     * Parse a single type, possibly array type
     * @return non-empty-string the simplified type
     */
    protected function parseSingleType(): string {
        if ($this->next == '(') {
            $this->parseToken('(');
            $type = $this->parseAnyType(true);
            $this->parseToken(')');
        } else {
            $type = $this->parseBasicType();
        }
        while ($this->next == '[' && $this->next(1) == ']') {
            // Array suffix.
            $this->parseToken('[');
            $this->parseToken(']');
            $type = 'array';
        }
        return $type;
    }

    /**
     * Parse a basic type
     * @return non-empty-string the simplified type
     */
    protected function parseBasicType(): string {
        // TODO: Substitute class and parent in here?

        $next = $this->next;
        if ($next == null) {
            throw new \Exception("Error parsing type, expected type, saw end.");
        }
        $nextchar = $next[0];

        if (in_array(strtolower($next), ['bool', 'boolean', 'true', 'false'])) {
            // Bool.
            $this->parseToken();
            $type = 'bool';
        } elseif (
                    in_array(strtolower($next), ['int', 'integer', 'positive-int', 'negative-int',
                                                'non-positive-int', 'non-negative-int',
                                                'int-mask', 'int-mask-of', ])
                    || (ctype_digit($nextchar) || $nextchar == '-') && strpos($next, '.') === false
                ) {
            // Int.
            $inttype = strtolower($this->parseToken());
            if ($inttype == 'int' && $this->next == '<') {
                // Integer range.
                $this->parseToken('<');
                $next = $this->next;
                if (
                            $next == null
                                || !(strtolower($next) == 'min'
                                    || (ctype_digit($next[0]) || $next[0] == '-') && strpos($next, '.') === false)
                        ) {
                    throw new \Exception("Error parsing type, expected int min, saw \"{$next}\".");
                }
                $this->parseToken();
                $this->parseToken(',');
                $next = $this->next;
                if (
                            $next == null
                                || !(strtolower($next) == 'max'
                                    || (ctype_digit($next[0]) || $next[0] == '-') && strpos($next, '.') === false)
                        ) {
                    throw new \Exception("Error parsing type, expected int max, saw \"{$next}\".");
                }
                $this->parseToken();
                $this->parseToken('>');
            } elseif ($inttype == 'int-mask') {
                // Integer mask.
                $this->parseToken('<');
                do {
                    $mask = $this->parseBasicType();
                    if (!$this->compareTypes('int', $mask)) {
                        throw new \Exception("Error parsing type, invalid int mask.");
                    }
                    $haveseperator = $this->next == ',';
                    if ($haveseperator) {
                        $this->parseToken(',');
                    }
                } while ($haveseperator);
                $this->parseToken('>');
            } elseif ($inttype == 'int-mask-of') {
                // Integer mask of.
                $this->parseToken('<');
                $mask = $this->parseBasicType();
                if (!$this->compareTypes('int', $mask)) {
                    throw new \Exception("Error parsing type, invalid int mask.");
                }
                $this->parseToken('>');
            }
            $type = 'int';
        } elseif (
                    in_array(strtolower($next), ['float', 'double'])
                    || (ctype_digit($nextchar) || $nextchar == '-') && strpos($next, '.') !== false
                ) {
            // Float.
            $this->parseToken();
            $type = 'float';
        } elseif (
                    in_array(strtolower($next), ['string', 'class-string', 'numeric-string', 'literal-string',
                                                'non-empty-string', 'non-falsy-string', 'truthy-string', ])
                    || $nextchar == '"' || $nextchar == '\''
                ) {
            // String.
            $strtype = strtolower($this->parseToken());
            if ($strtype == 'class-string' && $this->next == '<') {
                $this->parseToken('<');
                $stringtype = $this->parseAnyType();
                if (!$this->compareTypes('object', $stringtype)) {
                    throw new \Exception("Error parsing type, class-string type isn't class.");
                }
                $this->parseToken('>');
            }
            $type = 'string';
        } elseif (strtolower($next) == 'callable-string') {
            // Callable-string.
            $this->parseToken('callable-string');
            $type = 'callable-string';
        } elseif (in_array(strtolower($next), ['array', 'non-empty-array', 'list', 'non-empty-list'])) {
            // Array.
            $arraytype = strtolower($this->parseToken());
            if ($this->next == '<') {
                // Typed array.
                $this->parseToken('<');
                $firsttype = $this->parseAnyType();
                if ($this->next == ',') {
                    if (in_array($arraytype, ['list', 'non-empty-list'])) {
                        throw new \Exception("Error parsing type, lists cannot have keys specified.");
                    }
                    $key = $firsttype;
                    if (!$this->compareTypes('array-key', $key)) {
                        throw new \Exception("Error parsing type, invalid array key.");
                    }
                    $this->parseToken(',');
                    $value = $this->parseAnyType();
                } else {
                    $key = null;
                    $value = $firsttype;
                }
                $this->parseToken('>');
            } elseif ($this->next == '{') {
                // Array shape.
                if (in_array($arraytype, ['non-empty-array', 'non-empty-list'])) {
                    throw new \Exception("Error parsing type, non-empty-arrays cannot have shapes.");
                }
                $this->parseToken('{');
                do {
                    $next = $this->next;
                    if (
                                $next != null
                                && (ctype_alpha($next) || $next[0] == '_' || $next[0] == '\'' || $next[0] == '"'
                                    || (ctype_digit($next[0]) || $next[0] == '-') && strpos($next, '.') === false)
                                && ($this->next(1) == ':' || $this->next(1) == '?' && $this->next(2) == ':')
                            ) {
                        $this->parseToken();
                        if ($this->next == '?') {
                            $this->parseToken('?');
                        }
                        $this->parseToken(':');
                    }
                    $this->parseAnyType();
                    $havecomma = $this->next == ',';
                    if ($havecomma) {
                        $this->parseToken(',');
                    }
                } while ($havecomma);
                $this->parseToken('}');
            }
            $type = 'array';
        } elseif (strtolower($next) == 'object') {
            // Object.
            $this->parseToken('object');
            if ($this->next == '{') {
                // Object shape.
                $this->parseToken('{');
                do {
                    $next = $this->next;
                    if (
                            $next == null
                            || !(ctype_alpha($next) || $next[0] == '_' || $next[0] == '\'' || $next[0] == '"')
                        ) {
                        throw new \Exception("Error parsing type, invalid object key.");
                    }
                    $this->parseToken();
                    if ($this->next == '?') {
                        $this->parseToken('?');
                    }
                    $this->parseToken(':');
                    $this->parseAnyType();
                    $havecomma = $this->next == ',';
                    if ($havecomma) {
                        $this->parseToken(',');
                    }
                } while ($havecomma);
                $this->parseToken('}');
            }
            $type = 'object';
        } elseif (strtolower($next) == 'resource') {
            // Resource.
            $this->parseToken('resource');
            $type = 'resource';
        } elseif (in_array(strtolower($next), ['never', 'never-return', 'never-returns', 'no-return'])) {
            // Never.
            $this->parseToken();
            $type = 'never';
        } elseif (strtolower($next) == 'null') {
            // Null.
            $this->parseToken('null');
            $type = 'null';
        } elseif (strtolower($next) == 'void') {
            // Void.
            $this->parseToken('void');
            $type = 'void';
        } elseif (strtolower($next) == 'self') {
            // Self.
            $this->parseToken('self');
            $type = 'self';
        } elseif (strtolower($next) == 'parent') {
            // Parent.
            $this->parseToken('parent');
            $type = 'parent';
        } elseif (in_array(strtolower($next), ['static', '$this'])) {
            // Static.
            $this->parseToken();
            $type = 'static';
        } elseif (
                    strtolower($next) == 'callable'
                    || $next == "\\Closure" || $next == 'Closure' && $this->namespace == "\\"
                ) {
            // Callable.
            $callabletype = $this->parseToken();
            if ($this->next == '(') {
                $this->parseToken('(');
                while ($this->next != ')') {
                    $this->parseAnyType();
                    if ($this->next == '&') {
                        $this->parseToken('&');
                    }
                    if ($this->next == '...') {
                        $this->parseToken('...');
                    }
                    if ($this->next == '=') {
                        $this->parseToken('=');
                    }
                    $nextchar = ($this->next != null) ? $this->next[0] : null;
                    if ($nextchar == '$') {
                        $this->parseToken();
                    }
                    if ($this->next != ')') {
                        $this->parseToken(',');
                    }
                }
                $this->parseToken(')');
                $this->parseToken(':');
                if ($this->next == '?') {
                    $this->parseAnyType();
                } else {
                    $this->parseSingleType();
                }
            }
            if (strtolower($callabletype) == 'callable') {
                $type = 'callable';
            } else {
                $type = "\\Closure";
            }
        } elseif (strlower($next) == 'mixed') {
            // Mixed.
            $this->parseToken('mixed');
            $type = 'mixed';
        } elseif (strtolower($next) == 'iterable') {
            // Iterable (Traversable|array).
            $this->parseToken('iterable');
            if ($this->next == '<') {
                $this->parseToken('<');
                $firsttype = $this->parseAnyType();
                if ($this->next == ',') {
                    $key = $firsttype;
                    $this->parseToken(',');
                    $value = $this->parseAnyType();
                } else {
                    $key = null;
                    $value = $firsttype;
                }
                $this->parseToken('>');
            }
            $type = 'iterable';
        } elseif (strtolower($next) == 'array-key') {
            // Array-key (int|string).
            $this->parseToken('array-key');
            $type = 'array-key';
        } elseif (strtolower($next) == 'scalar') {
            // Scalar can be (bool|int|float|string).
            $this->parseToken('scalar');
            $type = 'scalar';
        } elseif (strtolower($next) == 'key-of') {
            // Key-of.
            $this->parseToken('key-of');
            $this->parseToken('<');
            $iterable = $this->parseAnyType();
            if (!($this->compareTypes('iterable', $iterable) || $this->compareTypes('object', $iterable))) {
                throw new \Exception("Error parsing type, can't get key of non-iterable.");
            }
            $this->parseToken('>');
            $type = $this->gowide ? 'mixed' : 'never';
        } elseif (strtolower($next) == 'value-of') {
            // Value-of.
            $this->parseToken('value-of');
            $this->parseToken('<');
            $iterable = $this->parseAnyType();
            if (!($this->compareTypes('iterable', $iterable) || $this->compareTypes('object', $iterable))) {
                throw new \Exception("Error parsing type, can't get value of non-iterable.");
            }
            $this->parseToken('>');
            $type = $this->gowide ? 'mixed' : 'never';
        } elseif (
                    (ctype_alpha($next[0]) || $next[0] == '_' || $next[0] == '\\')
                    && strpos($next, '-') === false && strpos($next, '\\\\') === false
                ) {
            // Class name.
            $type = $this->parseToken();
            if ($type[0] != "\\") {
                $type = $this->namespace . $type;
            }
            if (array_key_exists($type, $this->usealiases)) {
                $type = $this->usealiases[$type];
            }
            assert($type != '');
            if ($this->templates[$type] ?? null) {
                $type = $this->templates[$type];
            } elseif ($this->next == '<') {
                // Collection / Traversable.
                $this->parseToken('<');
                $firsttype = $this->parseAnyType();
                if ($this->next == ',') {
                    $key = $firsttype;
                    $this->parseToken(',');
                    $value = $this->parseAnyType();
                } else {
                    $key = null;
                    $value = $firsttype;
                }
                $this->parseToken('>');
            }
        } else {
            throw new \Exception("Error parsing type, unrecognised type.");
        }

        // Suffix.
        // We can't embed this in the class name section, because it could apply to relative classes.
        if ($this->next == '::' && (in_array('object', $this->superTypes($type)))) {
            // Class constant.
            $this->parseToken('::');
            $nextchar = ($this->next == null) ? null : $this->next[0];
            $haveconstantname = $nextchar != null && (ctype_alpha($nextchar) || $nextchar == '_');
            if ($haveconstantname) {
                $this->parseToken();
            }
            if ($this->next == '*' || !$haveconstantname) {
                $this->parseToken('*');
            }
            $type = $this->gowide ? 'mixed' : 'never';
        }

        return $type;
    }
}
