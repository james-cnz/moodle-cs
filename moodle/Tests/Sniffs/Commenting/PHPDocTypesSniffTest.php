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

namespace MoodleHQ\MoodleCS\moodle\Tests\Sniffs\Commenting;

use MoodleHQ\MoodleCS\moodle\Tests\MoodleCSBaseTestCase;

/**
 * Test the PHPDocTypes sniff.
 *
 * @author     2024 James Calder
 * @copyright  based on work by 2024 onwards Andrew Lyons <andrew@nicols.co.uk>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \MoodleHQ\MoodleCS\moodle\Sniffs\Commenting\PHPDocTypesSniff
 */
class PHPDocTypesSniffTest extends MoodleCSBaseTestCase
{
    /**
     * @dataProvider provider
     * @param string $fixture
     * @param array $errors
     * @param array $warnings
     */
    public function testPHPDocTypesCorrectness(
        string $fixture,
        array $errors,
        array $warnings
    ): void {
        $this->setStandard('moodle');
        $this->setSniff('moodle.Commenting.PHPDocTypes');
        $this->setFixture(sprintf("%s/fixtures/%s.php", __DIR__, $fixture));
        $this->setWarnings($warnings);
        $this->setErrors($errors);
        /*$this->setApiMappings([
            'test' => [
                'component' => 'core',
                'allowspread' => true,
                'allowlevel2' => false,
            ],
        ]);*/

        $this->verifyCsResults();
    }

    public static function provider(): array {
        return [
            'PHPDocTypes right' => [
                'fixture' => 'phpdoctypes_right',
                'errors' => [],
                'warnings' => [],
            ],
            'PHPDocTypes wrong' => [
                'fixture' => 'phpdoctypes_wrong',
                'errors' => [
                    45 => 'PHPDoc function parameter 1 name missing or malformed',
                    52 => 'PHPDoc function parameter 1 name missing or malformed',
                    57 => 'PHPDoc var type missing or malformed',
                    60 => 'PHPDoc var type missing or malformed',
                    64 => 'PHPDoc var type missing or malformed',
                    68 => 'PHPDoc var type missing or malformed',
                    72 => 'PHPDoc var type missing or malformed',
                    75 => 'PHPDoc var type missing or malformed',
                    78 => 'PHPDoc var type missing or malformed',
                    81 => 'PHPDoc var type missing or malformed',
                    84 => 'PHPDoc var type missing or malformed',
                    87 => 'PHPDoc var type missing or malformed',
                    90 => 'PHPDoc var type missing or malformed',
                    94 => 'PHPDoc var type missing or malformed',
                    97 => 'PHPDoc var type missing or malformed',
                    100 => 'PHPDoc var type missing or malformed',
                    103 => 'PHPDoc var type missing or malformed',
                    106 => 'PHPDoc var type missing or malformed',
                    109 => 'PHPDoc var type missing or malformed',
                    112 => 'PHPDoc var type missing or malformed',
                    115 => 'PHPDoc var type missing or malformed',
                    121 => 'PHPDoc function parameter 1 type missing or malformed',
                    126 => 'PHPDoc var type missing or malformed',
                    129 => 'PHPDoc var type missing or malformed',
                    135 => 'PHPDoc function parameter 1 type mismatch',
                ],
                'warnings' => [],
            ],
        ];
    }
}
