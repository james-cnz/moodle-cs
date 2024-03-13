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
 * Type parser
 *
 * Checks that PHPDoc types are well formed, and returns a simplified version if so, or null otherwise.
 * Global constants and the Collection|Type[] construct aren't supported.
 *
 * @copyright   2023-2024 Otago Polytechnic
 * @author      James Calder
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later (or CC BY-SA v4 or later)
 */

namespace MoodleHQ\MoodleCS\moodle\Util;

/**
 * Type parser
 */
class PHPDocTypeParser
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

    /** @var array<non-empty-string, object{extends: ?non-empty-string, implements: non-empty-string[]}> inheritance heirarchy */
    protected array $artifacts;

    /** @var object{namespace: string, uses: string[], templates: string[], classname: ?string, parentname: ?string} */
    protected object $scope;

    /** @var string the text to be parsed */
    protected string $text = '';

    /** @var bool when we encounter an unknown type, should we go wide or narrow */
    protected bool $gowide = false;

    /** @var object{startpos: non-negative-int, endpos: non-negative-int, text: ?non-empty-string}[] next tokens */
    protected array $nexts = [];

    /** @var ?non-empty-string the next token */
    protected ?string $next = null;

    /**
     * Constructor
     * @param ?array $artifacts
     */
    public function __construct(?array $artifacts = null) {
        $this->artifacts = $artifacts ?? [];
    }

    /**
     * Parse a type and possibly variable name
     * @param ?object{namespace: string, uses: string[], templates: string[], classname: ?string, parentname: ?string} $scope
     * @param string $text the text to parse
     * @param 0|1|2|3 $getwhat what to get 0=type only 1=also var 2=also modifiers (& ...) 3=also default
     * @param bool $gowide if we can't determine the type, should we assume wide (for native type) or narrow (for PHPDoc)?
     * @return object{type: ?non-empty-string, var: ?non-empty-string, rem: string, nullable: bool}
     *          the simplified type, variable, remaining text, and whether the type is explicitly nullable
     */
    public function parseTypeAndVar(?object $scope, string $text, int $getwhat, bool $gowide): object {

        // Initialise variables.
        if ($scope) {
            $this->scope = $scope;
        } else {
            $this->scope = (object)['namespace' => '', 'uses' => [], 'templates' => [], 'classname' => null, 'parentname' => null];
        }
        $this->text = $text;
        $this->gowide = $gowide;
        $this->nexts = [];
        $this->next = $this->next();

        // Try to parse type.
        $savednexts = $this->nexts;
        try {
            $type = $this->parseAnyType();
            $explicitnullable = strpos("|{$type}|", "|null|") !== false; // For code smell check.
            if (
                !($this->next == null || $getwhat >= 1 // TODO: Always require space?
                    || ctype_space(substr($this->text, $this->nexts[0]->startpos - 1, 1))
                    || in_array($this->next, [',', ';', ':', '.']))
            ) {
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
                        // because the old checker disallowed pass by reference & in PHPDocs,
                        // so adding this would be a nusiance for people who changed their PHPDocs
                        // to conform to the previous rules, and would make it impossible to conform
                        // if both checkers were used.
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
                $variable .= $this->next;
                assert($variable != '');
                $this->parseToken();
                if (
                    !($this->next == null || $getwhat >= 3 && $this->next == '='
                        || ctype_space(substr($this->text, $this->nexts[0]->startpos - 1, 1))
                        || in_array($this->next, [',', ';', ':', '.']))
                ) {
                    // Code smell check.
                    throw new \Exception("Warning parsing type, no space after variable name.");
                }
                if ($getwhat >= 3) {
                    if ($this->next == '=' && $this->next(1) == 'null' && $type != null) { // TODO: Check no more content?
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
     * Parse a template
     * @param ?object{namespace: string, uses: string[], templates: string[], classname: ?string, parentname: ?string} $scope
     * @param string $text the text to parse
     * @return object{type: ?non-empty-string, var: ?non-empty-string, rem: string}
     *          the simplified type, variable, and remaining text
     */
    public function parseTemplate(?object $scope, string $text): object {

        // Initialise variables.
        if ($scope) {
            $this->scope = $scope;
        } else {
            $this->scope = (object)['namespace' => '', 'uses' => [], 'templates' => [], 'classname' => null, 'parentname' => null];
        }
        $this->text = $text;
        $this->gowide = false;
        $this->nexts = [];
        $this->next = $this->next();

        // Try to parse variable.
        $savednexts = $this->nexts;
        try {
            if (!($this->next != null && ctype_alpha($this->next[0]))) {
                throw new \Exception("Error parsing type, expected variable, saw \"{$this->next}\".");
            }
            $variable = $this->next;
            assert($variable != '');
            $this->parseToken();
            if (
                !($this->next == null || $this->next == 'of'
                    || ctype_space(substr($this->text, $this->nexts[0]->startpos - 1, 1))
                    || in_array($this->next, [',', ';', ':', '.']))
            ) {
                // Code smell check.
                throw new \Exception("Warning parsing type, no space after variable name.");
            }
        } catch (\Exception $e) {
            $this->nexts = $savednexts;
            $this->next = $this->next();
            $variable = null;
        }

        if ($this->next == 'of') {
            $this->parseToken('of');
            // Try to parse type.
            $savednexts = $this->nexts;
            try {
                $type = $this->parseAnyType();
                if (
                    !($this->next == null
                        || ctype_space(substr($this->text, $this->nexts[0]->startpos - 1, 1))
                        || in_array($this->next, [',', ';', ':', '.']))
                ) {
                    // Code smell check.
                    throw new \Exception("Warning parsing type, no space after type.");
                }
            } catch (\Exception $e) {
                $this->nexts = $savednexts;
                $this->next = $this->next();
                $type = null;
            }
        } else {
            $type = 'mixed';
        }

        return (object)['type' => $type, 'var' => $variable, 'rem' => trim(substr($text, $this->nexts[0]->startpos))];
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
     * @return ?non-empty-string
     */
    protected function next(int $lookahead = 0): ?string {

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
            } elseif (ctype_alpha($firstchar) || $firstchar == '_' || $firstchar == '$' || $firstchar == "\\") {
                // Identifier token.
                $endpos = $startpos;
                do {
                    $endpos = $endpos + 1;
                    $nextchar = ($endpos < strlen($this->text)) ? $this->text[$endpos] : null;
                } while (
                    $nextchar != null && (ctype_alnum($nextchar) || $nextchar == '_'
                                        || $firstchar != '$' && ($nextchar == '-' || $nextchar == "\\"))
                );
            } elseif (
                ctype_digit($firstchar)
                || $firstchar == '-' && strlen($this->text) >= $startpos + 2 && ctype_digit($this->text[$startpos + 1])
            ) {
                // Number token.
                $nextchar = $firstchar;
                $havepoint = false;
                $endpos = $startpos;
                do {
                    $havepoint = $havepoint || $nextchar == '.';
                    $endpos = $endpos + 1;
                    $nextchar = ($endpos < strlen($this->text)) ? $this->text[$endpos] : null;
                } while ($nextchar != null && (ctype_digit($nextchar) || $nextchar == '.' && !$havepoint || $nextchar == '_'));
            } elseif ($firstchar == '"' || $firstchar == "'") {
                // String token.
                $endpos = $startpos + 1;
                $nextchar = ($endpos < strlen($this->text)) ? $this->text[$endpos] : null;
                while ($nextchar != $firstchar && $nextchar != null) { // There may be unterminated strings.
                    if ($nextchar == "\\" && strlen($this->text) >= $endpos + 2) {
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
     * @param ?non-empty-string $expect the expected text, or null for any
     * @return non-empty-string
     */
    protected function parseToken(?string $expect = null): string {

        $next = $this->next;

        // Check we have the expected token.
        if ($next == null) {
            throw new \Exception("Error parsing type, unexpected end.");
        } elseif ($expect != null && strtolower($next) != strtolower($expect)) {
            throw new \Exception("Error parsing type, expected \"{$expect}\", saw \"{$next}\".");
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
                            if (
                                !(in_array($intersectiontype, ['object', 'iterable', 'callable'])
                                    || in_array('object', $supertypes))
                            ) {
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
            || $nextchar == '"' || $nextchar == "'"
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
                        && (ctype_alpha($next) || $next[0] == '_' || $next[0] == "'" || $next[0] == '"'
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
                        || !(ctype_alpha($next) || $next[0] == '_' || $next[0] == "'" || $next[0] == '"')
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
            $type = $this->scope->classname ? $this->scope->classname : 'self';
        } elseif (strtolower($next) == 'parent') {
            // Parent.
            $this->parseToken('parent');
            $type = $this->scope->parentname ? $this->scope->parentname : 'parent';  // TODO: Throw error if no parent?
        } elseif (in_array(strtolower($next), ['static', '$this'])) {
            // Static.
            $this->parseToken();
            $type = $this->scope->classname ? "static({$this->scope->classname})" : 'static';
        } elseif (
            strtolower($next) == 'callable'
            || $next == "\\Closure" || $next == 'Closure' && $this->scope->namespace == ''
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
        } elseif (strtolower($next) == 'mixed') {
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
            (ctype_alpha($next[0]) || $next[0] == '_' || $next[0] == "\\")
            && strpos($next, '-') === false && strpos($next, "\\\\") === false
        ) {
            // Class name.
            $type = $this->parseToken();
            if (strrpos($type, "\\") === strlen($type) - 1) {
                throw new \Exception("Error parsing type, class name has trailing slash.");
            }
            if ($type[0] != "\\") {
                // TODO: What's the correct order for this?
                if (array_key_exists($type, $this->scope->uses)) {
                    $type = $this->scope->uses[$type];
                } elseif (array_key_exists($type, $this->scope->templates)) {
                    $type = $this->scope->templates[$type];
                } else {
                    $type = $this->scope->namespace . "\\" . $type;
                }
                assert($type != '');
            }
            if ($this->next == '<') {
                // Collection / Traversable.  // TODO: Could be any generic?
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