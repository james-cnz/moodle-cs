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

namespace MoodleHQ\MoodleCS\moodle\Tests\Util;

use PHPUnit\Framework\TestCase;
use MoodleHQ\MoodleCS\moodle\Util\PHPDocTypeParser;

/**
 * Tests for the PHPDocTypeParser.
 *
 * @author    James Calder
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Tests for the PHPDocTypeParser.
 *
 * @covers \MoodleHQ\MoodleCS\moodle\Util\PHPDocTypeParser
 */
final class PHPDocTypeParserTest extends TestCase
{
    /**
     * Test valid types.
     *
     * @return void
     */
    public function testValidTypes()
    {
        $typeparser = new PHPDocTypeParser(null);
        // Boolean types
        $this->assertSame(
            $typeparser->parseTypeAndVar(null, 'bool|boolean|true|false', 0, false)->type,
            'bool'
        );
        // Integer types
        $this->assertSame(
            $typeparser->parseTypeAndVar(null, 'int|integer', 0, false)->type,
            'int'
        );
        $this->assertSame(
            $typeparser->parseTypeAndVar(null, 'positive-int|negative-int|non-positive-int|non-negative-int', 0, false)->type,
            'int'
        );
        $this->assertSame(
            $typeparser->parseTypeAndVar(null, 'int<0, 100>|int<min, 100>|int<50, max>|int<-100, max>', 0, false)->type,
            'int'
        );
        $this->assertSame(
            $typeparser->parseTypeAndVar(null, '234|-234|int-mask<1, 2, 4>', 0, false)->type,
            'int'
        );
        $this->assertSame(
            $typeparser->parseTypeAndVar(null, '1_000|-1_000|int-mask<types_valid::INT_ONE, types_valid::INT_TWO>', 0, false)->type,
            'int'
        );
        $this->assertSame(
            $typeparser->parseTypeAndVar(null, 'int-mask-of<types_valid::INT_*>', 0, false)->type,
            'int'
        );
        // Float types
        $this->assertSame(
            $typeparser->parseTypeAndVar(null, 'float|double|1.0|-1.0', 0, false)->type,
            'float'
        );
        // String types
        $this->assertSame(
            $typeparser->parseTypeAndVar(null, 'string|class-string|class-string<types_valid>', 0, false)->type,
            'string'
        );
        $this->assertSame(
            $typeparser->parseTypeAndVar(null, 'callable-string|numeric-string|non-empty-string', 0, false)->type,
            'string'
        );
        $this->assertSame(
            $typeparser->parseTypeAndVar(null, 'non-falsy-string|truthy-string|literal-string', 0, false)->type,
            'string'
        );
        $this->assertSame(
            $typeparser->parseTypeAndVar(null, "'foo'|'bar'", 0, false)->type,
            'string'
        );
        $this->assertSame(
            $typeparser->parseTypeAndVar(null, "class-string<types_valid|types_valid_interface>|'\\''", 0, false)->type,
            'string'
        );
    }
}
