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
 * @copyright  2024 Otago Polytechnic
 * @author     James Calder
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later (or CC BY-SA v4 or later)
 */

namespace MoodleHQ\MoodleCS\moodle\Sniffs\Commenting;

use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Files\File;
use MoodleHQ\MoodleCS\moodle\Util\PHPDocTypeParser;

/**
 * Check PHPDoc Types.
 */
class PHPDocTypesSniff implements Sniff
{
    /** @var ?File the current file */
    protected ?File $file = null;

    /** @var array[] file tokens */
    protected array $tokens = [];

    /** @var array<string, object{extends: ?string, implements: string[]}> */
    protected array $artifacts = [];

    /** @var ?PHPDocTypeParser */
    protected ?PHPDocTypeParser $typeparser = null;

    /** @var int */
    protected int $pass = 0;

    /** @var int pointer in the file */
    protected int $fileptr = 0;

    /** @var (\stdClass&object{type: string, namespace: string, uses: string[], templates: string[],
     *                  classname: ?string, parentname: ?string, opened: bool, closer: ?int})[] scopes */
    protected array $scopes = [];  // TODO: Add remaining properties.

    /** @var ?(\stdClass&object{tags: array<string, string[]>}) PHPDoc comment for upcoming declaration */
    protected ?object $commentpending = null;

    protected int $commentpendingcounter = 0;

    /** @var ?(\stdClass&object{tags: array<string, string[]>}) PHPDoc comment for current declaration */
    protected ?object $comment = null;

    /** @var array{'code': ?array-key, 'content': string, 'scope_opener'?: int, 'scope_closer'?: int}
     * the current token */
    protected array $token = ['code' => null, 'content' => ''];

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
    public function process(File $phpcsfile, $stackptr): void {

        if ($phpcsfile == $this->file) {
            return;
        }

        try {
            $this->file = $phpcsfile;
            $this->tokens = $phpcsfile->getTokens();
            $this->artifacts = [];

            $this->pass = 1;
            $this->typeparser = null;
            $this->fileptr = $stackptr;
            $this->processPass();

            $this->pass = 2;
            $this->typeparser = new PHPDocTypeParser($this->artifacts);
            $this->fileptr = $stackptr;
            $this->processPass();
        } catch (\Exception $e) {
            $this->file->addError(
                'The PHPDoc type checker failed to parse the file.  PHPDoc type checks were not performed.  ' .
                'Debug info: file %s line %s',
                $this->fileptr < count($this->tokens) ? $this->fileptr : $this->fileptr - 1,
                'phpdoc_type_parse',
                [$e->getFile(), $e->getLine()]
            );
            // var_dump($this->token);  // TODO: Remove.
        }
    }

    /**
     * A pass over the file.
     * @return void
     * @phpstan-impure
     */
    protected function processPass(): void {
        $this->scopes = [(object)['type' => 'root', 'namespace' => '', 'uses' => [], 'templates' => [],
                        'classname' => null, 'parentname' => null, 'opened' => true, 'closer' => null]];
        $this->fetchToken();
        $this->commentpending = null;
        $this->comment = null;
        $this->advance(T_OPEN_TAG);

        while ($this->token['code']) {
            // Skip irrelevant stuff.
            while (
                !in_array(
                    $this->token['code'],
                    [T_DOC_COMMENT_OPEN_TAG, T_NAMESPACE, T_USE,
                    T_ABSTRACT, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_READONLY, T_FINAL,
                    T_CLASS, T_ANON_CLASS, T_INTERFACE, T_TRAIT, T_ENUM, T_FUNCTION, T_CLOSURE, T_VAR, T_CONST,
                    T_DECLARE, // T_DEFINE
                    T_SEMICOLON, null]
                )
                && (!isset($this->token['scope_opener']) || $this->token['scope_opener'] != $this->fileptr)
                && (!isset($this->token['scope_closer']) || $this->token['scope_closer'] != $this->fileptr)
            ) {
                $this->advance();
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

            // Namespace.
            if ($this->token['code'] == T_NAMESPACE && end($this->scopes)->opened) {
                $this->processNamespace();
                continue;
            }

            // Use.
            if ($this->token['code'] == T_USE) {
                if (end($this->scopes)->type == 'classish' && end($this->scopes)->opened) {
                    $this->processClassTraitUse();
                } elseif (end($this->scopes)->type == 'function' && !end($this->scopes)->opened) {
                    $this->advance(T_USE);
                } else {
                    $this->processUse();
                }
                continue;
            }

            // Malformed prior declaration. // TODO: Remove?
            if (
                !end($this->scopes)->opened
                    && !(isset($this->token['scope_opener']) && $this->token['scope_opener'] == $this->fileptr
                        || $this->token['code'] == T_SEMICOLON)
            ) {
                throw new \Exception();
            }

            // Scopes.
            if (isset($this->token['scope_opener']) && $this->token['scope_opener'] == $this->fileptr) {
                if ($this->token['scope_closer'] == end($this->scopes)->closer) {
                    if (count($this->scopes) > 1) {
                        array_pop($this->scopes);
                    } else {
                        throw new \Exception();
                    }
                }
                if (!end($this->scopes)->opened) {
                    end($this->scopes)->opened = true;
                } else {
                    $oldscope = end($this->scopes);
                    array_push($this->scopes, $newscope = clone $oldscope);
                    $newscope->type = 'other';
                    $newscope->opened = true;
                    $newscope->closer = $this->tokens[$this->fileptr]['scope_closer'];
                }
                $this->advance();
                continue; // TODO: Remove.
            }
            if (isset($this->token['scope_closer']) && $this->token['scope_closer'] == $this->fileptr) {
                if (count($this->scopes) > 1) {
                    array_pop($this->scopes);
                } else {
                    $this->advance();
                    throw new \Exception();
                }
                $this->advance();
                continue; // TODO: Remove.
            }

            // Empty declarations and other semicolons.
            if ($this->token['code'] == T_SEMICOLON) {
                if (!end($this->scopes)->opened) {
                    array_pop($this->scopes);
                }
                $this->advance(T_SEMICOLON);
                continue; // TODO: Remove.
            }

            // Declare.
            if ($this->token['code'] == T_DECLARE) {
                $this->processDeclare();
                continue;
            }

            // TODO: Define.

            // Declarations.
            if (
                in_array($this->token['code'],
                    [T_ABSTRACT, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_READONLY, T_FINAL,
                    T_CLASS, T_ANON_CLASS, T_INTERFACE, T_TRAIT, T_ENUM,
                    T_FUNCTION, T_CLOSURE,
                    T_CONST, T_VAR,])
            ) {
                $this->comment = $this->commentpending;
                $this->commentpending = null;
                $static = false;
                while (
                    in_array(
                        $this->token['code'],
                        [T_ABSTRACT, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_READONLY, T_FINAL]
                    )
                ) {
                    $static = ($this->token['code'] == T_STATIC);
                    $this->advance();
                }
                if ($static && (in_array($this->token['code'], [T_DOUBLE_COLON, T_OPEN_PARENTHESIS, T_SEMICOLON]))) {
                    // Static late binding.  // TODO: Check previous token is new.  Have to do something about comment.
                    continue;
                } elseif (in_array($this->token['code'], [T_CLASS,  T_ANON_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])) {
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
            }

            throw new \Exception();  // TODO: Check for variable assignments?
        }

        if (!$this->token['code'] && count($this->scopes) != 1) {
            throw new \Exception();
        }
    }

    /**
     * Fetch the current tokens.
     * @return void
     * @phpstan-impure
     */
    protected function fetchToken(): void {
        $this->token = ($this->fileptr < count($this->tokens)) ? $this->tokens[$this->fileptr] : ['code' => null, 'content' => ''];
    }

    /**
     * Advance the token pointer.
     * @param mixed $expectedcode
     * @param bool $skipphpdoc
     * @return void
     * @phpstan-impure
     */
    protected function advance($expectedcode = null, $skipphpdoc = true): void {
        if ($expectedcode && $this->token['code'] != $expectedcode || $this->token['code'] == null) {
            throw new \Exception();
        }
        $nextptr = $this->fileptr + 1;
        while (
            $nextptr < count($this->tokens)
                && (in_array($this->tokens[$nextptr]['code'], [T_WHITESPACE, T_COMMENT, T_OPEN_TAG, T_CLOSE_TAG])
                    || $skipphpdoc && in_array(
                        $this->tokens[$nextptr]['code'],
                        [T_DOC_COMMENT_OPEN_TAG, T_DOC_COMMENT_CLOSE_TAG, T_DOC_COMMENT_STAR,
                            T_DOC_COMMENT_TAG, T_DOC_COMMENT_STRING, T_DOC_COMMENT_WHITESPACE]
                    ))
        ) {
            // TODO: Check unexpected PHPDoc comment.
            if ($this->tokens[$nextptr]['code'] == T_DOC_COMMENT_OPEN_TAG) {
                $this->fileptr = $nextptr;
                $this->fetchToken();
                $this->processComment();
                $this->commentpendingcounter = 2;
                $nextptr = $this->fileptr;
            } else {
                if (
                    $this->commentpending && $this->commentpendingcounter > 0
                    && $this->tokens[$nextptr]['code'] != T_WHITESPACE
                ) {
                    $this->commentpendingcounter--;
                    if ($this->commentpendingcounter <= 0) {
                        $this->commentpending = null;
                    }
                }
                $nextptr++;
            }
        }
        $this->fileptr = $nextptr;
        $this->fetchToken();
    }

    /**
     * Process a PHPDoc comment.
     * @return void
     * @phpstan-impure
     */
    protected function processComment(): void {
        $this->commentpending = (object)['tags' => []];

        // Skip line starting stuff.
        while (
            in_array($this->token['code'], [T_DOC_COMMENT_OPEN_TAG, T_DOC_COMMENT_STAR])
                || $this->token['code'] == T_DOC_COMMENT_WHITESPACE
                    && !in_array(substr($this->token['content'], -1), ["\n", "\r"])
        ) {
            $this->advance(null, false);
        }
        // For each tag.
        while ($this->token['code'] != T_DOC_COMMENT_CLOSE_TAG) {
            // Check new tag.
            if ($this->token['code'] == T_DOC_COMMENT_TAG) {
                $tagtype = $this->token['content'];
                $this->advance(T_DOC_COMMENT_TAG, false);
            } else {
                $tagtype = '';
            }
            $tagcontent = '';
            // For each line.
            do {
                $newline = false;
                // Fetch line content.
                while ($this->token['code'] != T_DOC_COMMENT_CLOSE_TAG && !$newline) {
                    $tagcontent .= $this->token['content'];
                    $newline = in_array(substr($this->token['content'], -1), ["\n", "\r"]);
                    $this->advance(null, false);
                }
                // Skip line starting stuff.
                while (
                    in_array($this->token['code'], [T_DOC_COMMENT_OPEN_TAG, T_DOC_COMMENT_STAR])
                        || $this->token['code'] == T_DOC_COMMENT_WHITESPACE
                            && !in_array(substr($this->token['content'], -1), ["\n", "\r"])
                ) {
                    $this->advance(null, false);
                }
            } while (!in_array($this->token['code'], [T_DOC_COMMENT_CLOSE_TAG, T_DOC_COMMENT_TAG]));
            if (!isset($this->commentpending->tags[$tagtype])) {
                $this->commentpending->tags[$tagtype] = [];
            }
            $this->commentpending->tags[$tagtype][] = trim($tagcontent);
        }
        $this->advance(T_DOC_COMMENT_CLOSE_TAG, false);
    }

    /**
     * Process a namespace declaration.
     * @return void
     * @phpstan-impure
     */
    protected function processNamespace(): void {
        $this->advance(T_NAMESPACE);
        $namespace = '';
        while (
            in_array(
                $this->token['code'],
                [T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE, T_NS_SEPARATOR, T_STRING]
            )
        ) {
            $namespace .= $this->token['content'];
            $this->advance();
        }
        while ($namespace != '' && $namespace[strlen($namespace) - 1] == "\\") {
            $namespace = substr($namespace, 0, strlen($namespace) - 1);
        }
        if ($namespace != '' && $namespace[0] != "\\") {
            $namespace = "\\" . $namespace;
        }
        if ($this->pass == 2) {
            //$this->file->addWarning('Found namespace %s', $this->fileptr, 'debug', [$namespace]);
        }
        // TODO: Expect bracket or semicolon.
        if ($this->token['code'] == T_SEMICOLON) {
            end($this->scopes)->namespace = $namespace;
        } else {
            $oldscope = end($this->scopes);
            array_push($this->scopes, $newscope = clone $oldscope);
            $newscope->type = 'namespace';
            $newscope->namespace = $namespace;
            $newscope->opened = false;
            $newscope->closer = null;
        }
    }

    /**
     * Process a use declaration.
     * @return void
     * @phpstan-impure
     */
    protected function processUse(): void {
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
            while (
                in_array(
                    $this->token['code'],
                    [T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE, T_NS_SEPARATOR, T_STRING]
                )
            ) {
                $namespace .= $this->token['content'];
                $this->advance();
            }
            if ($namespace != '' && $namespace[0] != "\\") {
                $namespace = "\\" . $namespace;
            }
            if ($this->token['code'] == T_OPEN_USE_GROUP || $this->token['code'] == T_OPEN_CURLY_BRACKET) {
                $namespacestart = $namespace;  // TODO: Check there's a trailing backslash?
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
                        in_array(
                            $this->token['code'],
                            [T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE, T_NS_SEPARATOR, T_STRING]
                        )
                    ) {
                        $namespaceend .= $this->token['content'];
                        $this->advance();
                    }
                    $namespace = $namespacestart . $namespaceend;
                    $alias = substr($namespace, strrpos($namespace, "\\") + 1);
                    $asalias = $this->processUseAsAlias();
                    $alias = $asalias ?? $alias;
                    if ($this->pass == 2 && $type == 'class') {
                        end($this->scopes)->uses[$alias] = $namespace;
                        //$this->file->addWarning('Found use %s', $this->fileptr, 'debug', [$alias]);
                    }
                    $more = ($this->token['code'] == T_COMMA);
                    if ($more) {
                        $this->advance(T_COMMA);
                    }
                } while ($more);
                if ($this->token['code'] != T_CLOSE_USE_GROUP && $this->token['code'] != T_CLOSE_CURLY_BRACKET) {
                    throw new \Exception();
                }
                $this->advance();
            } else {
                // TODO: Check there's no trailing backslash?
                $alias = (strrpos($namespace, "\\") !== false) ?
                    substr($namespace, strrpos($namespace, "\\") + 1)
                    : $namespace;
                $asalias = $this->processUseAsAlias();
                $alias = $asalias ?? $alias;
                if ($this->pass == 2 && $type == 'class') {
                    end($this->scopes)->uses[$alias] = $namespace;
                    //$this->file->addWarning('Found use %s', $this->fileptr, 'debug', [$alias]);
                }
            }
            $more = ($this->token['code'] == T_COMMA);
            if ($more) {
                $this->advance(T_COMMA);
            }
        } while ($more);
        // TODO: Expect semicolon.
    }

    /**
     * Process a use as alias.
     * @return ?string
     * @phpstan-impure
     */
    protected function processUseAsAlias(): ?string {
        $alias = null;
        if ($this->token['code'] == T_AS) {
            $this->advance(T_AS);
            if ($this->token['code'] == T_STRING) {
                $alias = $this->token['content'];
                $this->advance(T_STRING);
            }
        }
        return $alias;
    }

    /**
     * Process a classish thing.
     * @return void
     * @phpstan-impure
     */
    protected function processClassish(): void {
        $name = $this->file->getDeclarationName($this->fileptr);
        $name = $name ? end($this->scopes)->namespace . "\\" . $name : null;
        $parent = $this->file->findExtendedClassName($this->fileptr);
        if ($parent && $parent[0] != "\\") {
            $parent = end($this->scopes)->namespace . "\\" . $parent;
        }
        $interfaces = $this->file->findImplementedInterfaceNames($this->fileptr);
        if (!is_array($interfaces)) {
            $interfaces = [];
        }
        foreach ($interfaces as $index => $interface) {
            if ($interface && $interface[0] != "\\") {
                $interfaces[$index] = end($this->scopes)->namespace . "\\" . $interface;
            }
        }
        // Check not anonymous.
        //$this->file->addWarning('Found classish %s', $this->fileptr, 'debug', [$name]);
        $oldscope = end($this->scopes);
        array_push($this->scopes, $newscope = clone $oldscope);
        $newscope->type = 'classish';
        $newscope->classname = $name;
        $newscope->parentname = $parent;
        $newscope->opened = false;
        $newscope->closer = null;
        if ($this->pass == 1 && $name) {
            $this->artifacts[$name] = (object)['extends' => $parent, 'implements' => $interfaces];
        } elseif ($this->pass == 2) {
            if ($name && $this->comment && isset($this->comment->tags['@template'])) {
                foreach ($this->comment->tags['@template'] as $templatetext) {
                    //$this->file->addWarning('Found template %s', $this->fileptr, 'debug', [$templatetext]);
                    $templatedata = $this->typeparser->parseTemplate($newscope, $templatetext);
                    if (!$templatedata->var) {
                        $this->file->addError('PHPDoc template name missing or malformed', $this->fileptr, 'phpdoc_template_name');
                    } elseif (!$templatedata->type) {
                        $this->file->addError('PHPDoc template type missing or malformed', $this->fileptr, 'phpdoc_template_type');
                        $newscope->templates[$templatedata->var] = 'never';
                    } else {
                        $newscope->templates[$templatedata->var] = $templatedata->type;
                    }
                }
            }
        }
        $this->advance();
    }

    protected function processClassTraitUse(): void {
        $this->advance(T_USE);
        while (
            in_array(
                $this->token['code'],
                [T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE, T_NS_SEPARATOR, T_STRING]
            )
        ) {
            $this->advance();
        }
        if ($this->token['code'] == T_OPEN_CURLY_BRACKET) {
            $this->advance(T_OPEN_CURLY_BRACKET);
            do {
                $this->advance(T_STRING);
                if ($this->token['code'] == T_AS) {
                    $this->advance(T_AS);
                    while (in_array($this->token['code'], [T_PUBLIC, T_PROTECTED, T_PRIVATE])) {
                        $this->advance();
                    }
                    if ($this->token['code'] == T_STRING) {
                        $this->advance(T_STRING);
                    }
                }
                if ($this->token['code'] == T_SEMICOLON) {
                    $this->advance(T_SEMICOLON);
                }
            } while ($this->token['code'] != T_CLOSE_CURLY_BRACKET);
            $this->advance(T_CLOSE_CURLY_BRACKET); // TODO: Delay this.
        }
    }

    /**
     * Process a function.
     * @return void
     * @phpstan-impure
     */
    protected function processFunction(): void {
        // TODO: Check not anonymous?
        $name = $this->file->getDeclarationName($this->fileptr);
        $parameters = $this->file->getMethodParameters($this->fileptr);
        $properties = $this->file->getMethodProperties($this->fileptr);
        // TODO: Can templates be defined here?
        $oldscope = end($this->scopes);
        array_push($this->scopes, $newscope = clone $oldscope);
        $newscope->type = 'function';
        $newscope->opened = false;
        $newscope->closer = null;

        if ($this->pass == 2) {
            /*$this->file->addWarning(
                'Found function %s params %s return %s',
                $this->fileptr,
                'debug',
                [$name, count($parameters), $properties['return_type']]
            );*/
            if ($this->comment && isset($parameters)) {
                if (!isset($this->comment->tags['@param'])) {
                    $this->comment->tags['@param'] = [];
                }
                if (count($this->comment->tags['@param']) != count($parameters)) {
                    $this->file->addError(
                        'PHPDoc number of function parameters doesn\'t match actual number',
                        $this->fileptr,
                        'phpdoc_fun_param_count'
                    ); // TODO: Don't give error if no parameters documented?
                }
                for ($varnum = 0; $varnum < count($this->comment->tags['@param']); $varnum++) {
                    $docparamdata = $this->typeparser->parseTypeAndVar(
                        $newscope,
                        $this->comment->tags['@param'][$varnum],
                        2,
                        false
                    );
                    if (!$docparamdata->type) {
                        $this->file->addError(
                            'PHPDoc function parameter %s type missing or malformed',
                            $this->fileptr,
                            'phpdoc_fun_param_type',
                            [$varnum + 1]
                        );
                    } elseif (!$docparamdata->var) {
                        $this->file->addError(
                            'PHPDoc function parameter %s name missing or malformed',
                            $this->fileptr,
                            'phpdoc_fun_param_name',
                            [$varnum + 1]
                        );
                    } elseif ($varnum < count($parameters)) {
                        $paramdata = $this->typeparser->parseTypeAndVar(
                            $newscope,
                            $parameters[$varnum]['content'],
                            3,
                            true
                        );
                        if (!$this->typeparser->comparetypes($paramdata->type, $docparamdata->type)) {
                            $this->file->addError(
                                'PHPDoc function parameter %s type mismatch',
                                $this->fileptr,
                                'phpdoc_fun_param_type_mismatch',
                                [$varnum + 1]
                            );
                        } // TODO: Check doc type is nullable if native type is explicitly so?
                        if ($paramdata->var != $docparamdata->var) {
                            $this->file->addError(
                                'PHPDoc function parameter %s name mismatch',
                                $this->fileptr,
                                'phpdoc_fun_param_name_mismatch',
                                [$varnum + 1]
                            );
                        }
                    }
                }
            }
            if ($this->comment && isset($properties)) {
                if (!isset($this->comment->tags['@return'])) {
                    $this->comment->tags['@return'] = [];
                }
                // The old checker didn't check this.
                /*if (count($this->comment->tags['@return']) < 1 && $name != '__construct') {
                    $this->file->addError(
                        'PHPDoc missing function return type',
                        $this->fileptr,
                        'phpdoc_fun_ret_missing'
                    );
                } else*/
                if (count($this->comment->tags['@return']) > 1) {
                    $this->file->addError(
                        'PHPDoc multiple function return types--Put in one tag, seperated by vertical bars |',
                        $this->fileptr,
                        'phpdoc_fun_ret_multiple'
                    );
                }
                //echo "about to get ret data\n";
                $retdata = $properties['return_type'] ?
                    $this->typeparser->parseTypeAndVar(
                        $newscope,
                        $properties['return_type'],
                        0,
                        true
                    )
                    : (object)['type' => 'mixed'];
                //echo "got ret data\n";
                for ($retnum = 0; $retnum < count($this->comment->tags['@return']); $retnum++) {
                    /*$this->file->addWarning(
                        'PHP ret %s vs PHPDoc ret %s',
                        $this->fileptr,
                        'debug',
                        [$properties['return_type'], $this->comment->tags['@return'][$retnum]]
                    );*/
                    $docretdata = $this->typeparser->parseTypeAndVar(
                        $newscope,
                        $this->comment->tags['@return'][$retnum],
                        0,
                        false
                    );
                    if (!$this->typeparser->comparetypes($retdata->type, $docretdata->type)) {
                        $this->file->addError(
                            'PHPDoc function return type mismatch',
                            $this->fileptr,
                            'phpdoc_fun_ret_type_mismatch'
                        );
                    } // TODO: Check doc type is nullable if native type is?
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
        // Parameters.
        if ($this->token['code'] != T_OPEN_PARENTHESIS) {
            throw new \Exception();
        }
    }

    /**
     * Process a possible variable.
     * @return void
     * @phpstan-impure
     */
    protected function processVariable(): void {

        // Parse var/const token.
        $const = ($this->token['code'] == T_CONST);
        if ($const) {
            $this->advance(T_CONST);
        } elseif ($this->token['code'] == T_VAR) {
            $this->advance(T_VAR);
        }

        // Parse type.
        if (!$const) {
            while (
                in_array(
                    $this->token['code'],
                    [T_TYPE_UNION, T_TYPE_INTERSECTION, T_NULLABLE, T_OPEN_PARENTHESIS, T_CLOSE_PARENTHESIS,
                    T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE, T_NS_SEPARATOR, T_STRING,
                    T_NULL, T_ARRAY, T_OBJECT, T_SELF, T_PARENT, T_FALSE, T_TRUE, T_CALLABLE, T_STATIC, ]
                )
            ) {
                $this->advance();
            }
        }

        // Check name.
        if ($this->token['code'] != ($const ? T_STRING : T_VARIABLE)) {
            throw new \Exception();
        }

        // Important stuff.  Note, could be function static variable.
        if ($this->pass == 2 && end($this->scopes)->type == 'classish' && !$const) {
            //$this->file->addWarning('Found variable %s', $this->fileptr, 'debug', [$name]);
            $properties = $this->file->getMemberProperties($this->fileptr);
            if ($this->comment) {
                if (!isset($this->comment->tags['@var'])) {
                    $this->comment->tags['@var'] = [];
                }
                if (count($this->comment->tags['@var']) < 1) {
                    $this->file->addError('PHPDoc missing var', $this->fileptr, 'phpdoc_var_missing');
                } elseif (count($this->comment->tags['@var']) > 1) {
                    $this->file->addError('PHPDoc multiple vars', $this->fileptr, 'phpdoc_var_multiple');
                }
                $vardata = $properties['type'] ?
                    $this->typeparser->parseTypeAndVar(
                        end($this->scopes),
                        $properties['type'],
                        0,
                        true
                    )
                    : (object)['type' => 'mixed'];
                for ($varnum = 0; $varnum < count($this->comment->tags['@var']); $varnum++) {
                    /*$this->file->addWarning(
                        'PHP var %s vs PHPDoc var %s',
                        $this->fileptr,
                        'debug',
                        [$properties['type'], $this->comment->tags['@var'][$varnum]]
                    );*/
                    $docvardata = $this->typeparser->parseTypeAndVar(
                        end($this->scopes),
                        $this->comment->tags['@var'][$varnum],
                        0,
                        false
                    );
                    if (!$docvardata->type) {
                        $this->file->addError(
                            'PHPDoc var type missing or malformed',
                            $this->fileptr,
                            'phpdoc_var_type',
                            [$varnum + 1]
                        );
                    } elseif (!$this->typeparser->comparetypes($vardata->type, $docvardata->type)) {
                        $this->file->addError(
                            'PHPDoc var type mismatch',
                            $this->fileptr,
                            'phpdoc_fun_var_type_mismatch'
                        );
                    } // TODO: Check doc type is nullable if native type is?
                }
            }
        }
        $this->advance();

        if (!in_array($this->token['code'], [T_EQUAL, T_COMMA, T_SEMICOLON])) {
            throw new \Exception();
        }
    }

    /**
     * Process a declare.
     * @return void
     * @phpstan-impure
     */
    protected function processDeclare(): void {

        $this->advance(T_DECLARE);
        $this->advance(T_OPEN_PARENTHESIS);
        $this->advance(T_STRING);
        $this->advance(T_EQUAL);
    }
}
