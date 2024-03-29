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

declare(strict_types=1);

namespace MoodleHQ\MoodleCS\moodle\Sniffs\Commenting;

define('DEBUG_MODE', false);
define('CHECK_HAS_DOCS', false);

use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Util\Tokens;
use MoodleHQ\MoodleCS\moodle\Util\PHPDocTypeParser;

/**
 * Check PHPDoc Types.
 */
class PHPDocTypesSniff implements Sniff
{
    /** @var ?File the current file */
    protected ?File $file = null;

    /** @var array{'code': ?array-key, 'content': string, 'scope_opener'?: int, 'scope_closer'?: int,
     *              'parenthesis_opener'?: int, 'parenthesis_closer'?: int, 'attribute_closer'?: int}[]
     * file tokens */
    protected array $tokens = [];

    /** @var array<non-empty-string, object{extends: ?non-empty-string, implements: non-empty-string[]}>
     * classish things: classes, interfaces, traits, and enums */
    protected array $artifacts = [];

    /** @var ?PHPDocTypeParser for parsing and comparing types */
    protected ?PHPDocTypeParser $typeparser = null;

    /** @var 1|2 pass 1 for gathering artifact/classish info, 2 for checking */
    protected int $pass = 1;

    /** @var int current token pointer in the file */
    protected int $fileptr = 0;

    /** @var ?(\stdClass&object{ptr: int, tags: array<string, object{ptr: int, content: string, cstartptr: ?int, cendptr: ?int}[]>})
     * PHPDoc comment for upcoming declaration */
    protected ?object $commentpending = null;

    /** @var array{'code': ?array-key, 'content': string, 'scope_opener'?: int, 'scope_closer'?: int,
     *              'parenthesis_opener'?: int, 'parenthesis_closer'?: int, 'attribute_closer'?: int}
     * the current token */
    protected array $token = ['code' => null, 'content' => ''];

    /** @var array{'code': ?array-key, 'content': string, 'scope_opener'?: int, 'scope_closer'?: int,
     *              'parenthesis_opener'?: int, 'parenthesis_closer'?: int, 'attribute_closer'?: int}
     * the previous token */
    protected array $tokenprevious = ['code' => null, 'content' => ''];

    /**
     * Register for open tag (only process once per file).
     * @return array-key[]
     */
    public function register(): array {
        return [T_OPEN_TAG];
    }

    /**
     * Processes PHP files and perform PHPDoc type checks with file.
     * @param File $phpcsfile The file being scanned.
     * @param int $stackptr The position in the stack.
     * @return int
     */
    public function process(File $phpcsfile, $stackptr): int {

        try {
            $this->file = $phpcsfile;
            $this->tokens = $phpcsfile->getTokens();

            // Gather atifact info.
            $this->artifacts = [];
            $this->pass = 1;
            $this->typeparser = null;
            $this->fileptr = $stackptr;
            $this->processPass();

            // Check the PHPDoc types.
            $this->pass = 2;
            $this->typeparser = new PHPDocTypeParser($this->artifacts);
            $this->fileptr = $stackptr;
            $this->processPass();
        } catch (\Exception $e) {
            // We should only end up here in debug mode.
            $this->file->addError(
                'The PHPDoc type sniff failed to parse the file.  PHPDoc type checks were not performed.  ' .
                'Error: ' . $e->getMessage(),
                $this->fileptr < count($this->tokens) ? $this->fileptr : $this->fileptr - 1,
                'phpdoc_type_parse'
            );
        }

        return count($this->tokens);
    }

    /**
     * A pass over the file.
     * @return void
     * @phpstan-impure
     */
    protected function processPass(): void {
        $scope = (object)[
            'namespace' => '', 'uses' => [], 'templates' => [], 'closer' => null,
            'classname' => null, 'parentname' => null, 'type' => 'root',
        ];
        $this->tokenprevious = ['code' => null, 'content' => ''];
        $this->fetchToken();
        $this->commentpending = null;

        $this->processBlock($scope, 0);
    }

    /**
     * Process the content of a file, class, function, or parameters
     * @param \stdClass&object{namespace: string, uses: string[], templates: string[],
     *              classname: ?string, parentname: ?string, type: string, closer: ?int} $scope
     * @param 0|1|2 $type 0=file 1=block 2=parameters
     * @return void
     * @phpstan-impure
     */
    protected function processBlock(object $scope, int $type): void {

        // Check we are at the start of a scope, and store scope closer.
        if ($type == 0/*file*/) {
            if (DEBUG_MODE && $this->token['code'] != T_OPEN_TAG) {
                throw new \Exception("Expected PHP open tag");
            }
            $scope->closer = count($this->tokens);
        } elseif ($type == 1/*block*/) {
            if (
                !isset($this->token['scope_opener'])
                || $this->token['scope_opener'] != $this->fileptr
                || !isset($this->token['scope_closer'])
            ) {
                throw new \Exception("Malformed block");
            }
            $scope->closer = $this->token['scope_closer'];
        } else /*parameters*/ {
            if (
                !isset($this->token['parenthesis_opener'])
                || $this->token['parenthesis_opener'] != $this->fileptr
                || !isset($this->token['parenthesis_closer'])
            ) {
                throw new \Exception("Malformed parameters");
            }
            $scope->closer = $this->token['parenthesis_closer'];
        }
        $this->advance();

        while (true) {
            // If parsing fails, we'll give up whatever we're doing, and try again.
            try {
                // Skip irrelevant tokens.
                while (
                    !in_array(
                        $this->token['code'],
                        array_merge(
                            [T_NAMESPACE, T_USE],
                            Tokens::$methodPrefixes,
                            [T_READONLY],
                            Tokens::$ooScopeTokens,
                            [T_FUNCTION, T_CLOSURE, T_FN,
                            T_VAR, T_CONST,
                            null]
                        )
                    )
                    && !($this->fileptr >= $scope->closer)
                ) {
                    $this->advance();
                }


                if ($this->fileptr >= $scope->closer) {
                    // End of the block.
                    break;
                } elseif ($this->token['code'] == T_NAMESPACE && $scope->type == 'root') {
                    // Namespace.
                    $this->processNamespace($scope);
                } elseif ($this->token['code'] == T_USE) {
                    // Use.
                    if ($scope->type == 'root' || $scope->type == 'namespace') {
                        $this->processUse($scope);
                    } elseif ($scope->type == 'classish') {
                        $this->processClassTraitUse();
                    } else {
                        $this->advance(T_USE);
                        throw new \Exception("Unrecognised use of: use");
                    }
                } elseif (
                    in_array(
                        $this->token['code'],
                        array_merge(
                            Tokens::$methodPrefixes,
                            [T_READONLY],
                            Tokens::$ooScopeTokens,
                            [T_FUNCTION, T_CLOSURE, T_FN,
                            T_CONST, T_VAR, ]
                        )
                    )
                ) {
                    // Declarations.
                    // Fetch comment.
                    $comment = $this->commentpending;
                    $this->commentpending = null;
                    // Ignore preceding stuff, and gather info to check this is actually a declaration.
                    $static = false;
                    $staticprecededbynew = ($this->tokenprevious['code'] == T_NEW);
                    while (
                        in_array(
                            $this->token['code'],
                            array_merge(Tokens::$methodPrefixes, [T_READONLY])
                        )
                    ) {
                        $static = ($this->token['code'] == T_STATIC);
                        $this->advance();
                    }
                    // What kind of declaration is this?
                    if ($static && ($this->token['code'] == T_DOUBLE_COLON || $staticprecededbynew)) {
                        // It's not a declaration, it's a static late binding.  Ignore.
                        $this->processPossVarComment($scope, $comment);
                    } elseif (in_array($this->token['code'], Tokens::$ooScopeTokens)) {
                        // Classish thing.
                        $this->processClassish($scope, $comment);
                    } elseif (in_array($this->token['code'], [T_FUNCTION, T_CLOSURE, T_FN])) {
                        // Function.
                        $this->processFunction($scope, $comment);
                    } else {
                        // Variable.
                        $this->processVariable($scope, $comment);
                    }
                } else {
                    // We got something unrecognised.
                    $this->advance();
                    throw new \Exception("Unrecognised construct");
                }
            } catch (\Exception $e) {
                if (DEBUG_MODE) {
                    throw $e;
                }
            }
        }

        // Check we are at the end of the scope.
        if (DEBUG_MODE && $this->fileptr != $scope->closer) {
            throw new \Exception("Malformed scope closer");
        }
        // We can't consume this token.  Arrow functions close on the token following their body.
        /*if ($this->token['code']) {
            $this->advance();
        }*/
    }

    /**
     * Fetch the current tokens.
     * @return void
     * @phpstan-impure
     */
    protected function fetchToken(): void {
        $this->token = ($this->fileptr < count($this->tokens)) ?
            $this->tokens[$this->fileptr]
            : ['code' => null, 'content' => ''];
    }

    /**
     * Advance the token pointer when reading PHP code.
     * @param array-key $expectedcode What we expect, or null if anything's OK
     * @return void
     * @phpstan-impure
     */
    protected function advance($expectedcode = null): void {

        // Check we have something to fetch, and it's what's expected.
        if ($expectedcode && $this->token['code'] != $expectedcode || $this->token['code'] == null) {
            throw new \Exception("Unexpected token, saw: {$this->token['content']}");
        }

        // Dispose of unused comment.
        if ($this->commentpending) {
            $this->processPossVarComment(null, $this->commentpending);
            $this->commentpending = null;
        }

        $this->tokenprevious = $this->token;

        $this->fileptr++;
        $this->fetchToken();

        // Skip stuff that doesn't affect us.
        // TODO: What, if anything, is T_DOC_COMMENT for?
        // And do we need to manage PHPCS comments, or does PHPCS do that for us?
        while (
            $this->fileptr < count($this->tokens)
            && in_array($this->tokens[$this->fileptr]['code'], Tokens::$emptyTokens)
            && $this->tokens[$this->fileptr]['code'] != T_DOC_COMMENT_OPEN_TAG
        ) {
            $this->fileptr++;
            $this->fetchToken();
        }

        // Process PHPDoc comments.
        while ($this->fileptr < count($this->tokens) && $this->tokens[$this->fileptr]['code'] == T_DOC_COMMENT_OPEN_TAG) {
            if ($this->pass == 2 && $this->commentpending) {
                $this->processPossVarComment(null, $this->commentpending);
                $this->commentpending = null;
            }
            $this->processComment();
        }

        // Allow attributes between the comment and what it relates to.
        while (
            $this->fileptr < count($this->tokens)
            && in_array($this->tokens[$this->fileptr]['code'], [T_WHITESPACE, T_ATTRIBUTE])
        ) {
            if ($this->tokens[$this->fileptr]['code'] == T_ATTRIBUTE) {
                $this->fileptr = $this->tokens[$this->fileptr]['attribute_closer'] + 1;
            } else {
                $this->fileptr++;
            }
            $this->fetchToken();
        }

        // If we're at the end of the file, dispose of unused comment.
        if (!$this->token['code'] && $this->pass == 2 && $this->commentpending) {
            $this->processPossVarComment(null, $this->commentpending);
            $this->commentpending = null;
        }
    }

    /**
     * Advance the token pointer to a specific point.
     * @param int $newptr
     * @return void
     * @phpstan-impure
     */
    protected function advanceTo(int $newptr): void {
        while ($this->fileptr < $newptr) {
            $this->advance();
        }
        if ($this->fileptr != $newptr) {
            throw new \Exception("Malformed code");
        }
    }

    /**
     * Advance the token pointer when reading PHPDoc comments.
     * @param array-key $expectedcode What we expect, or null if anything's OK
     * @return void
     * @phpstan-impure
     */
    protected function advanceComment($expectedcode = null): void {

        // Check we are actually in a PHPDoc comment.
        if (
            !in_array(
                $this->token['code'],
                [T_DOC_COMMENT_OPEN_TAG, T_DOC_COMMENT_CLOSE_TAG, T_DOC_COMMENT_STAR,
                T_DOC_COMMENT_TAG, T_DOC_COMMENT_STRING, T_DOC_COMMENT_WHITESPACE]
            )
        ) {
            throw new \Exception("Expected PHPDoc comment");
        }

        // Check we have something to fetch, and it's what's expected.
        if ($expectedcode && $this->token['code'] != $expectedcode || $this->token['code'] == null) {
            throw new \Exception("Unexpected token, saw: {$this->token['content']}");
        }

        $this->fileptr++;
        $this->fetchToken();

        // If we're expecting the end of the comment, then we need to advance to the next PHP code.
        if ($expectedcode == T_DOC_COMMENT_CLOSE_TAG) {
            while (
                $this->fileptr < count($this->tokens)
                && in_array($this->tokens[$this->fileptr]['code'], Tokens::$emptyTokens)
                && $this->tokens[$this->fileptr]['code'] != T_DOC_COMMENT_OPEN_TAG
            ) {
                $this->fileptr++;
                $this->fetchToken();
            }
        }
    }

    /**
     * Process a PHPDoc comment.
     * @return void
     * @phpstan-impure
     */
    protected function processComment(): void {
        $this->commentpending = (object)['ptr' => $this->fileptr, 'tags' => []];

        // Skip line starting stuff.
        while (
            in_array($this->token['code'], [T_DOC_COMMENT_OPEN_TAG, T_DOC_COMMENT_STAR])
                || $this->token['code'] == T_DOC_COMMENT_WHITESPACE
                    && !in_array(substr($this->token['content'], -1), ["\n", "\r"])
        ) {
            $this->advanceComment();
        }

        // For each tag.
        while ($this->token['code'] && $this->token['code'] != T_DOC_COMMENT_CLOSE_TAG) {
            $tag = (object)['ptr' => $this->fileptr, 'content' => '', 'cstartptr' => null, 'cendptr' => null];
            // Fetch the tag type.
            if ($this->token['code'] == T_DOC_COMMENT_TAG) {
                $tagtype = $this->token['content'];
                $this->advanceComment(T_DOC_COMMENT_TAG);
                while (
                    $this->token['code'] == T_DOC_COMMENT_WHITESPACE
                    && !in_array(substr($this->token['content'], -1), ["\n", "\r"])
                ) {
                    $this->advanceComment(T_DOC_COMMENT_WHITESPACE);
                }
            } else {
                $tagtype = '';
            }

            // For each line, until we reach a new tag.
            // Note: the logic for fixing a comment tag must exactly match this.
            do {
                $newline = false;
                // Fetch line content.
                while ($this->token['code'] != T_DOC_COMMENT_CLOSE_TAG && !$newline) {
                    if (!$tag->cstartptr) {
                        $tag->cstartptr = $this->fileptr;
                    }
                    $tag->cendptr = $this->fileptr;
                    $newline = in_array(substr($this->token['content'], -1), ["\n", "\r"]);
                    $tag->content .= ($newline ? "\n" : $this->token['content']);
                    $this->advanceComment();
                }
                // Skip next line starting stuff.
                while (
                    in_array($this->token['code'], [T_DOC_COMMENT_OPEN_TAG, T_DOC_COMMENT_STAR])
                        || $this->token['code'] == T_DOC_COMMENT_WHITESPACE
                            && !in_array(substr($this->token['content'], -1), ["\n", "\r"])
                ) {
                    $this->advanceComment();
                }
            } while (!in_array($this->token['code'], [T_DOC_COMMENT_CLOSE_TAG, T_DOC_COMMENT_TAG]));

            // Store tag content.
            if (!isset($this->commentpending->tags[$tagtype])) {
                $this->commentpending->tags[$tagtype] = [];
            }
            $this->commentpending->tags[$tagtype][] = $tag;
        }
        $this->advanceComment(T_DOC_COMMENT_CLOSE_TAG);
    }

    /**
     * Check for misplaced tags
     * @param object{ptr: int, tags: array<string, object{ptr: int, content: string, cstartptr: ?int, cendptr: ?int}[]>} $comment
     * @param string[] $tagnames
     * @return void
     */
    protected function checkNo(object $comment, array $tagnames): void {
        foreach ($tagnames as $tagname) {
            if (isset($comment->tags[$tagname])) {
                $this->file->addWarning(
                    'PHPDoc misplaced tag',
                    $comment->tags[$tagname][0]->ptr,
                    'phpdoc_tag_misplaced'
                );
            }
        }
    }

    /**
     * Fix a PHPDoc comment tag.
     * @param object{ptr: int, content: string, cstartptr: ?int, cendptr: ?int} $tag
     * @param string $replacement
     * @return void
     * @phpstan-impure
     */
    protected function fixCommentTag(object $tag, string $replacement): void {
        $replacementarray = explode("\n", $replacement);
        $replacementcounter = 0;
        $donereplacement = false;
        $ptr = $tag->cstartptr;

        $this->file->fixer->beginChangeset();

        // For each line, until we reach a new tag.
        // Note: the logic for this must exactly match that for processing a comment tag.
        do {
            $newline = false;
            // Change line content.
            while ($this->tokens[$ptr]['code'] != T_DOC_COMMENT_CLOSE_TAG && !$newline) {
                $newline = in_array(substr($this->tokens[$ptr]['content'], -1), ["\n", "\r"]);
                if (!$newline) {
                    if ($donereplacement || $replacementarray[$replacementcounter] === "") {
                        throw new \Exception("Error during replacement");
                    }
                    $this->file->fixer->replaceToken($ptr, $replacementarray[$replacementcounter]);
                    $donereplacement = true;
                } else {
                    if (!($donereplacement || $replacementarray[$replacementcounter] === "")) {
                        throw new \Exception("Error during replacement");
                    }
                    $replacementcounter++;
                    $donereplacement = false;
                }
                $ptr++;
            }
            // Skip next line starting stuff.
            while (
                in_array($this->tokens[$ptr]['code'], [T_DOC_COMMENT_OPEN_TAG, T_DOC_COMMENT_STAR])
                    || $this->tokens[$ptr]['code'] == T_DOC_COMMENT_WHITESPACE
                        && !in_array(substr($this->tokens[$ptr]['content'], -1), ["\n", "\r"])
            ) {
                $ptr++;
            }
        } while (!in_array($this->tokens[$ptr]['code'], [T_DOC_COMMENT_CLOSE_TAG, T_DOC_COMMENT_TAG]));

        // Check we're done all the expected replacements, otherwise something's gone seriously wrong.
        if (
            !($replacementcounter == count($replacementarray) - 1
            && ($donereplacement || $replacementarray[count($replacementarray) - 1] === ""))
        ) {
            throw new \Exception("Error during replacement");
        }

        $this->file->fixer->endChangeset();
    }

    /**
     * Process a namespace declaration.
     * @param \stdClass&object{namespace: string, uses: string[], templates: string[],
     *              classname: ?string, parentname: ?string, type: string, closer: ?int} $scope
     * @return void
     * @phpstan-impure
     */
    protected function processNamespace(object $scope): void {

        $this->advance(T_NAMESPACE);

        // Fetch the namespace.
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

        // Check it's right.
        if ($namespace != '' && $namespace[strlen($namespace) - 1] == "\\") {
            throw new \Exception("Namespace trailing backslash");
        }

        // Check it's fully qualified.
        if ($namespace != '' && $namespace[0] != "\\") {
            $namespace = "\\" . $namespace;
        }

        // What kind of namespace is it?
        if (!in_array($this->token['code'], [T_OPEN_CURLY_BRACKET, T_SEMICOLON])) {
            throw new \Exception("Namespace malformed");
        }
        if ($this->token['code'] == T_OPEN_CURLY_BRACKET) {
            $scope = clone($scope);
            $scope->type = 'namespace';
            $scope->namespace = $namespace;
            $this->processBlock($scope, 1);
        } else {
            $scope->namespace = $namespace;
            $this->advance(T_SEMICOLON);
        }
    }

    /**
     * Process a use declaration.
     * @param \stdClass&object{namespace: string, uses: string[], templates: string[],
     *              classname: ?string, parentname: ?string, type: string, closer: ?int} $scope
     * @return void
     * @phpstan-impure
     */
    protected function processUse(object $scope): void {

        $this->advance(T_USE);

        // Loop until we've fetched all imports.
        $more = false;
        do {
            // Get the type.
            $type = 'class';
            if ($this->token['code'] == T_FUNCTION) {
                $type = 'function';
                $this->advance(T_FUNCTION);
            } elseif ($this->token['code'] == T_CONST) {
                $type = 'const';
                $this->advance(T_CONST);
            }

            // Get what's being imported
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

            // Check it's fully qualified.
            if ($namespace != '' && $namespace[0] != "\\") {
                $namespace = "\\" . $namespace;
            }

            if ($this->token['code'] == T_OPEN_USE_GROUP) {
                // It's a group.
                $namespacestart = $namespace;
                if ($namespacestart && strrpos($namespacestart, "\\") != strlen($namespacestart) - 1) {
                    throw new \Exception("Malformed use statement");
                }
                $typestart = $type;

                // Fetch everything in the group.
                $this->advance(T_OPEN_USE_GROUP);
                do {
                    // Get the type.
                    $type = $typestart;
                    if ($this->token['code'] == T_FUNCTION) {
                        $type = 'function';
                        $this->advance(T_FUNCTION);
                    } elseif ($this->token['code'] == T_CONST) {
                        $type = 'const';
                        $this->advance(T_CONST);
                    }

                    // Get what's being imported.
                    $namespaceend = '';
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

                    // Figure out the alias.
                    $alias = substr($namespace, strrpos($namespace, "\\") + 1);
                    $asalias = $this->processUseAsAlias();
                    $alias = $asalias ?? $alias;

                    // Store it.
                    if ($type == 'class') {
                        $scope->uses[$alias] = $namespace;
                    }

                    $more = ($this->token['code'] == T_COMMA);
                    if ($more) {
                        $this->advance(T_COMMA);
                    }
                } while ($more);
                $this->advance(T_CLOSE_USE_GROUP);
            } else {
                // It's a single import.
                // Figure out the alias.
                $alias = (strrpos($namespace, "\\") !== false) ?
                    substr($namespace, strrpos($namespace, "\\") + 1)
                    : $namespace;
                if ($alias == '') {
                    throw new \Exception("Malformed use statement");
                }
                $asalias = $this->processUseAsAlias();
                $alias = $asalias ?? $alias;

                // Store it.
                if ($type == 'class') {
                    $scope->uses[$alias] = $namespace;
                }
            }
            $more = ($this->token['code'] == T_COMMA);
            if ($more) {
                $this->advance(T_COMMA);
            }
        } while ($more);

        $this->advance(T_SEMICOLON);
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
            $alias = $this->token['content'];
            $this->advance(T_STRING);
        }
        return $alias;
    }

    /**
     * Process a classish thing.
     * @param \stdClass&object{namespace: string, uses: string[], templates: string[],
     *          classname: ?string, parentname: ?string, type: string, closer: ?int} $scope
     * @param ?(\stdClass&object{ptr: int,
     *          tags: array<string, object{ptr: int, content: string, cstartptr: ?int, cendptr: ?int}[]>}) $comment
     *
     * @return void
     * @phpstan-impure
     */
    protected function processClassish(object $scope, ?object $comment): void {

        $ptr = $this->fileptr;
        $token = $this->token;
        $this->advance();

        // New scope.
        $scope = clone($scope);
        $scope->type = 'classish';
        $scope->closer = null;

        // Get details.
        $name = $this->file->getDeclarationName($ptr);
        $name = $name ? $scope->namespace . "\\" . $name : null;
        $parent = $this->file->findExtendedClassName($ptr);
        if ($parent === false) {
            $parent = null;
        } elseif ($parent && $parent[0] != "\\") {
            if (isset($scope->uses[$parent])) {
                $parent = $scope->uses[$parent];
            } else {
                $parent = $scope->namespace . "\\" . $parent;
            }
        }
        $interfaces = $this->file->findImplementedInterfaceNames($ptr);
        if (!is_array($interfaces)) {
            $interfaces = [];
        }
        foreach ($interfaces as $index => $interface) {
            if ($interface && $interface[0] != "\\") {
                if (isset($scope->uses[$interface])) {
                    $interfaces[$index] = $scope->uses[$interface];
                } else {
                    $interfaces[$index] = $scope->namespace . "\\" . $interface;
                }
            }
        }
        $scope->classname = $name;
        $scope->parentname = $parent;

        if ($this->pass == 1 && $name) {
            // Store details.
            $this->artifacts[$name] = (object)['extends' => $parent, 'implements' => $interfaces];
        } elseif ($this->pass == 2) {
            // Check no misplaced tags.
            if ($comment) {
                $this->checkNo($comment, ['@param', '@return', '@var']);
            }
            // Check and store templates.
            if ($comment && isset($comment->tags['@template'])) {
                $this->processTemplates($scope, $comment);
            }
            // Check properties.
            if ($comment) {
                // Check each property type.
                foreach (['@property', '@property-read', '@property-write'] as $tagname) {
                    if (!isset($comment->tags[$tagname])) {
                        $comment->tags[$tagname] = [];
                    }

                    // Check each individual property.
                    foreach ($comment->tags[$tagname] as $docprop) {
                        $docpropparsed = $this->typeparser->parseTypeAndVar(
                            $scope,
                            $docprop->content,
                            1,
                            false
                        );
                        if (!$docpropparsed->type) {
                            $this->file->addError(
                                'PHPDoc class property type missing or malformed',
                                $docprop->ptr,
                                'phpdoc_class_prop_type'
                            );
                        } elseif (!$docpropparsed->var) {
                            $this->file->addError(
                                'PHPDoc class property name missing or malformed',
                                $docprop->ptr,
                                'phpdoc_class_prop_name'
                            );
                        } elseif ($docpropparsed->fixed) {
                            $fix = $this->file->addFixableWarning(
                                "PHPDoc class property type doesn't conform to recommended style",
                                $docprop->ptr,
                                'phpdoc_class_prop_type_style'
                            );
                            if ($fix) {
                                $this->fixCommentTag(
                                    $docprop,
                                    $docpropparsed->fixed
                                );
                            }
                        }
                    }
                }
            }
        }

        $parametersptr = isset($token['parenthesis_opener']) ? $token['parenthesis_opener'] : null;
        $blockptr = isset($token['scope_opener']) ? $token['scope_opener'] : null;

        // If it's an anonymous class, it could have parameters.
        // And those parameters could have other anonymous classes or functions in them.
        if ($parametersptr) {
            $this->advanceTo($parametersptr);
            $this->processBlock($scope, 2);
        }

        // Process the content.
        if ($blockptr) {
            $this->advanceTo($blockptr);
            $this->processBlock($scope, 1);
        };
    }

    /**
     * Skip over a class trait usage.
     * We need to ignore these, because if it's got public, protected, or private in it,
     * it could be confused for a declaration.
     * @return void
     * @phpstan-impure
     */
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
            $this->advance(T_CLOSE_CURLY_BRACKET);
        }
    }

    /**
     * Process a function.
     * @param \stdClass&object{namespace: string, uses: string[], templates: string[],
     *          classname: ?string, parentname: ?string, type: string, closer: ?int} $scope
     * @param ?(\stdClass&object{ptr: int,
     *          tags: array<string, object{ptr: int, content: string, cstartptr: ?int, cendptr: ?int}[]>}) $comment
     * @return void
     * @phpstan-impure
     */
    protected function processFunction(object $scope, ?object $comment): void {

        $ptr = $this->fileptr;
        $token = $this->token;
        $this->advance();

        // New scope.
        $scope = clone($scope);
        $scope->type = 'function';
        $scope->closer = null;

        // Get details.
        // TODO: Check we have a parenthesis opener.
        $name = ($token['code'] == T_FN) ? null : $this->file->getDeclarationName($ptr);
        $parametersptr = isset($token['parenthesis_opener']) ? $token['parenthesis_opener'] : null;
        $blockptr = isset($token['scope_opener']) ? $token['scope_opener'] : null;
        $parameters = $this->file->getMethodParameters($ptr);
        $properties = $this->file->getMethodProperties($ptr);

        if (
            !$parametersptr
            || !isset($this->tokens[$parametersptr]['parenthesis_opener'])
            || !isset($this->tokens[$parametersptr]['parenthesis_closer'])
        ) {
            throw new \Exception("Malformed function parameters");
        }

        // Checks.
        if ($this->pass == 2) {
            // Check for missing docs if not anonymous.
            if (CHECK_HAS_DOCS && $name && !$comment) {
                $this->file->addWarning(
                    'PHPDoc function is not documented',
                    $ptr,
                    'phpdoc_fun_doc_missing'
                );
            }

            // Check for misplaced tags.
            if ($comment) {
                $this->checkNo($comment, ['@property', '@property-read', '@property-write', '@var']);
            }

            // Check and store templates.
            if ($comment && isset($comment->tags['@template'])) {
                $this->processTemplates($scope, $comment);
            }

            // Check parameter types.
            if ($comment) {
                // Gather parameter data.
                $paramparsedarray = [];
                foreach ($parameters as $parameter) {
                    $paramtext = trim($parameter['content']);
                    while (
                        strpos($paramtext, ' ')
                        && in_array(
                            strtolower(substr($paramtext, 0, strpos($paramtext, ' '))),
                            ['public', 'private', 'protected', 'readonly']
                        )
                    ) {
                        $paramtext = trim(substr($paramtext, strpos($paramtext, ' ') + 1));
                    }
                    $paramparsed = $this->typeparser->parseTypeAndVar(
                        $scope,
                        $paramtext,
                        3,
                        true
                    );
                    if ($paramparsed->var && !isset($paramparsedarray[$paramparsed->var])) {
                        $paramparsedarray[$paramparsed->var] = $paramparsed;
                    }
                }

                if (!isset($comment->tags['@param'])) {
                    $comment->tags['@param'] = [];
                }

                // Check each individual doc parameter.
                $docparamsexist = [];
                foreach ($comment->tags['@param'] as $docparam) {
                    $docparamparsed = $this->typeparser->parseTypeAndVar(
                        $scope,
                        $docparam->content,
                        2,
                        false
                    );
                    if (!$docparamparsed->type) {
                        $this->file->addError(
                            'PHPDoc function parameter type missing or malformed',
                            $docparam->ptr,
                            'phpdoc_fun_param_type'
                        );
                    } elseif (!$docparamparsed->var) {
                        $this->file->addError(
                            'PHPDoc function parameter name missing or malformed',
                            $docparam->ptr,
                            'phpdoc_fun_param_name'
                        );
                    } elseif (!isset($paramparsedarray[$docparamparsed->var])) {
                            // Function parameter doesn't exist.
                            $this->file->addError(
                                "PHPDoc function parameter doesn't exist",
                                $docparam->ptr,
                                'phpdoc_fun_param_name_wrong'
                            );
                    } else {
                        // Compare docs against actual parameter.
                        // Fetch actual parameter.
                        $paramparsed = $paramparsedarray[$docparamparsed->var];

                        if (isset($docparamsexist[$docparamparsed->var])) {
                            $this->file->addError(
                                'PHPDoc function parameter repeated',
                                $docparam->ptr,
                                'phpdoc_fun_param_type_repeat'
                            );
                        }
                        $docparamsexist[$docparamparsed->var] = true;

                        if (!$this->typeparser->comparetypes($paramparsed->type, $docparamparsed->type)) {
                            $this->file->addError(
                                'PHPDoc function parameter type mismatch',
                                $docparam->ptr,
                                'phpdoc_fun_param_type_mismatch'
                            );
                        } elseif ($docparamparsed->fixed) {
                            $fix = $this->file->addFixableWarning(
                                "PHPDoc function parameter type doesn't conform to recommended style",
                                $docparam->ptr,
                                'phpdoc_fun_param_type_style'
                            );
                            if ($fix) {
                                $this->fixCommentTag(
                                    $docparam,
                                    $docparamparsed->fixed
                                );
                            }
                        }
                        if ($paramparsed->passsplat != $docparamparsed->passsplat) {
                            $this->file->addWarning(
                                'PHPDoc function parameter splat mismatch',
                                $docparam->ptr,
                                'phpdoc_fun_param_pass_splat_mismatch'
                            );
                        }
                    }
                }

                // Check all parameters are documented.
                if (CHECK_HAS_DOCS) {
                    foreach ($paramparsedarray as $paramname => $paramparsed) {
                        if (!isset($docparamsexist[$paramname])) {
                            $this->file->addWarning(
                                "PHPDoc function parameter %s not documented",
                                $comment->ptr,
                                'phpdoc_fun_param_not_documented',
                                [$paramname]
                            );
                        }
                    }
                }

                // Check parameters are in the correct order.
                reset($paramparsedarray);
                reset($docparamsexist);
                while (key($paramparsedarray) || key($docparamsexist)) {
                    if (key($docparamsexist) == key($paramparsedarray)) {
                        next($paramparsedarray);
                        next($docparamsexist);
                    } elseif (key($paramparsedarray) && !isset($docparamsexist[key($paramparsedarray)])) {
                        next($paramparsedarray);
                    } else {
                        $this->file->addWarning(
                            "PHPDoc function parameter order wrong",
                            $comment->ptr,
                            'phpdoc_fun_param_order'
                        );
                        break;
                    }
                }
            }

            // Check return type.
            if ($comment) {
                if (!isset($comment->tags['@return'])) {
                    $comment->tags['@return'] = [];
                }
                // The old checker didn't check this.
                if (CHECK_HAS_DOCS && count($comment->tags['@return']) < 1 && $name != '__construct') {
                    $this->file->addWarning(
                        'PHPDoc missing function @return tag',
                        $ptr,
                        'phpdoc_fun_ret_missing'
                    );
                } elseif (count($comment->tags['@return']) > 1) {
                    $this->file->addError(
                        'PHPDoc multiple function @return tags--Put in one tag, seperated by vertical bars |',
                        $comment->tags['@return'][1]->ptr,
                        'phpdoc_fun_ret_multiple'
                    );
                }
                $retparsed = $properties['return_type'] ?
                    $this->typeparser->parseTypeAndVar(
                        $scope,
                        $properties['return_type'],
                        0,
                        true
                    )
                    : (object)['type' => 'mixed'];

                // Check each individual return tag, in case there's more than one.
                foreach ($comment->tags['@return'] as $docret) {
                    $docretparsed = $this->typeparser->parseTypeAndVar(
                        $scope,
                        $docret->content,
                        0,
                        false
                    );
                    if (!$docretparsed->type) {
                        $this->file->addError(
                            'PHPDoc function return type missing or malformed',
                            $docret->ptr,
                            'phpdoc_fun_ret_type'
                        );
                    } elseif (!$this->typeparser->comparetypes($retparsed->type, $docretparsed->type)) {
                        $this->file->addError(
                            'PHPDoc function return type mismatch',
                            $docret->ptr,
                            'phpdoc_fun_ret_type_mismatch'
                        );
                    } elseif ($docretparsed->fixed) {
                        $fix = $this->file->addFixableWarning(
                            "PHPDoc function return type doesn't conform to recommended style",
                            $docret->ptr,
                            'phpdoc_fun_ret_type_style'
                        );
                        if ($fix) {
                            $this->fixCommentTag(
                                $docret,
                                $docretparsed->fixed
                            );
                        }
                    }
                }
            }
        }

        // Parameters could contain anonymous classes or functions.
        if ($parametersptr) {
            $this->advanceTo($parametersptr);
            $this->processBlock($scope, 2);
        }

        // Content.
        if ($blockptr) {
            $this->advanceTo($blockptr);
            $this->processBlock($scope, 1);
        };
    }

    /**
     * Process templates.
     * @param \stdClass&object{namespace: string, uses: string[], templates: string[],
     *          classname: ?string, parentname: ?string, type: string, closer: ?int} $scope
     * @param ?(\stdClass&object{ptr: int,
     *          tags: array<string, object{ptr: int, content: string, cstartptr: ?int, cendptr: ?int}[]>}) $comment
     * @return void
     * @phpstan-impure
     */
    protected function processTemplates(object $scope, ?object $comment): void {
        foreach ($comment->tags['@template'] as $doctemplate) {
            $doctemplateparsed = $this->typeparser->parseTemplate($scope, $doctemplate->content);
            if (!$doctemplateparsed->var) {
                $this->file->addError('PHPDoc template name missing or malformed', $doctemplate->ptr, 'phpdoc_template_name');
            } elseif (!$doctemplateparsed->type) {
                $this->file->addError('PHPDoc template type missing or malformed', $doctemplate->ptr, 'phpdoc_template_type');
                $scope->templates[$doctemplateparsed->var] = 'never';
            } else {
                $scope->templates[$doctemplateparsed->var] = $doctemplateparsed->type;
                if ($doctemplateparsed->fixed) {
                    $fix = $this->file->addFixableWarning(
                        "PHPDoc tempate type doesn't conform to recommended style",
                        $doctemplate->ptr,
                        'phpdoc_template_type_style'
                    );
                    if ($fix) {
                        $this->fixCommentTag(
                            $doctemplate,
                            $doctemplateparsed->fixed
                        );
                    }
                }
            }
        }
    }

    /**
     * Process a variable.
     * @param \stdClass&object{namespace: string, uses: string[], templates: string[],
     *          classname: ?string, parentname: ?string, type: string, closer: ?int} $scope
     * @param ?(\stdClass&object{ptr: int,
     *          tags: array<string, object{ptr: int, content: string, cstartptr: ?int, cendptr: ?int}[]>}) $comment
     * @return void
     * @phpstan-impure
     */
    protected function processVariable(object $scope, ?object $comment): void {

        // Parse var/const token.
        $const = ($this->token['code'] == T_CONST);
        if ($const) {
            $this->advance(T_CONST);
        } elseif ($this->token['code'] == T_VAR) {
            $this->advance(T_VAR);
        }

        // Parse type.
        if (!$const) {
            // TODO: Add T_TYPE_OPEN_PARENTHESIS and T_TYPE_CLOSE_PARENTHESIS if/when this change happens.
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
            throw new \Exception("Expected declaration.");
        }

        // Type checking.
        if ($this->pass == 2) {
            // Get properties, unless it's a function static variable or constant.
            $properties = ($scope->type == 'classish' && !$const) ?
                $this->file->getMemberProperties($this->fileptr)
                : null;
            $vartype = ($properties && $properties['type']) ? $properties['type'] : 'mixed';

            if (CHECK_HAS_DOCS && !$comment && $scope->type == 'classish') {
                // Require comments for class variables and constants.
                $this->file->addWarning(
                    'PHPDoc variable or constant is not documented',
                    $this->fileptr,
                    'phpdoc_var_doc_missing'
                );
            } elseif ($comment) {
                // Check for misplaced tags.
                $this->checkNo(
                    $comment,
                    ['@template', '@property', '@property-read', '@property-write', '@param', '@return']
                );

                if (!isset($comment->tags['@var'])) {
                    $comment->tags['@var'] = [];
                }
                // Missing or multiple vars.
                if (CHECK_HAS_DOCS && count($comment->tags['@var']) < 1) {
                    $this->file->addWarning('PHPDoc missing @var tag', $comment->ptr, 'phpdoc_var_missing');
                } elseif (count($comment->tags['@var']) > 1) {
                    $this->file->addError('PHPDoc multiple @var tags', $comment->tags['@var'][1]->ptr, 'phpdoc_var_multiple');
                }
                // Var type check and match.
                $varparsed = $this->typeparser->parseTypeAndVar(
                    $scope,
                    $vartype,
                    0,
                    true
                );
                foreach ($comment->tags['@var'] as $docvar) {
                    $docvarparsed = $this->typeparser->parseTypeAndVar(
                        $scope,
                        $docvar->content,
                        0,
                        false
                    );
                    if (!$docvarparsed->type) {
                        $this->file->addError(
                            'PHPDoc var type missing or malformed',
                            $docvar->ptr,
                            'phpdoc_var_type'
                        );
                    } elseif (!$this->typeparser->comparetypes($varparsed->type, $docvarparsed->type)) {
                        $this->file->addError(
                            'PHPDoc var type mismatch',
                            $docvar->ptr,
                            'phpdoc_var_type_mismatch'
                        );
                    } elseif ($docvarparsed->fixed) {
                        $fix = $this->file->addFixableWarning(
                            "PHPDoc var type doesn't conform to recommended style",
                            $docvar->ptr,
                            'phpdoc_var_type_style'
                        );
                        if ($fix) {
                            $this->fixCommentTag(
                                $docvar,
                                $docvarparsed->fixed
                            );
                        }
                    }
                }
            }
        }

        $this->advance();

        if (!in_array($this->token['code'], [T_EQUAL, T_COMMA, T_SEMICOLON, T_CLOSE_PARENTHESIS])) {
            throw new \Exception("Expected one of: = , ; )");
        }
    }

    /**
     * Process a possible variable comment.
     * @param \stdClass&object{namespace: string, uses: string[], templates: string[],
     *          classname: ?string, parentname: ?string, type: string, closer: ?int} $scope
     * @param ?(\stdClass&object{ptr: int,
     *          tags: array<string, object{ptr: int, content: string, cstartptr: ?int, cendptr: ?int}[]>}) $comment
     * @return void
     * @phpstan-impure
     */
    protected function processPossVarComment(?object $scope, ?object $comment): void {
        if ($this->pass == 2 && $comment) {
            $this->checkNo(
                $comment,
                ['@template', '@property', '@property-read', '@property-write', '@param', '@return']
            );

            // Check @var tags if any.
            if (isset($comment->tags['@var'])) {
                foreach ($comment->tags['@var'] as $docvar) {
                    $docvarparsed = $this->typeparser->parseTypeAndVar(
                        $scope,
                        $docvar->content,
                        0,
                        false
                    );
                    if (!$docvarparsed->type) {
                        $this->file->addError(
                            'PHPDoc var type missing or malformed',
                            $docvar->ptr,
                            'phpdoc_var_type'
                        );
                    } elseif ($docvarparsed->fixed) {
                        $fix = $this->file->addFixableWarning(
                            "PHPDoc var type doesn't conform to recommended style",
                            $docvar->ptr,
                            'phpdoc_var_type_style'
                        );
                        if ($fix) {
                            $this->fixCommentTag(
                                $docvar,
                                $docvarparsed->fixed
                            );
                        }
                    }
                }
            }
        }
    }
}
