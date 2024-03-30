<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * A collection of invalid types for testing
 *
 * Every type annotation should give an error either when checked with PHPStan or Psalm.
 * Having just invalid types in here means the number of errors should match the number of type annotations.
 *
 * @package   local_codechecker
 * @copyright 2024 Otago Polytechnic
 * @author    James Calder
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later, CC BY-SA v4 or later, and BSD-3-Clause
 */

/**
 * A collection of invalid types for testing
 *
 * @package   local_codechecker
 * @copyright 2024 Otago Polytechnic
 * @author    James Calder
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later, CC BY-SA v4 or later, and BSD-3-Clause
 */
class types_invalid {

    // PHPDoc function is not documented
    public function fun_not_doc(): void {
    }

    /**
     * PHPDoc function parameter $p not documented
     * PHPDoc missing function @return tag
     */
    public function fun_missing_param_ret(int $p): int {
        return $p;
    }

    // PHPDoc variable or constant is not documented
    public int $v1;

    /** PHPDoc missing @var tag */
    public int $v2;

}