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
 * Test the PHPDocTypesSniff.
 *
 * @copyright  2026 James Calder and Otago Polytechnic
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \MoodleHQ\MoodleCS\moodle\Sniffs\Commenting\PHPDocTypesSniff
 */
class PHPDocTypesSniffTest extends MoodleCSBaseTestCase
{
    /**
     * Test PHPDocTypesSniff
     *
     * @param string $fixture
     * @param string[] $errors
     * @param string[] $warnings
     * @dataProvider provider
     */
    public function testPHPDocTypesSniff(
        string $fixture,
        array $errors,
        array $warnings
    ): void {
        $this->setStandard('moodle');
        $this->setSniff('moodle.Commenting.PHPDocTypes');
        $this->setFixture(sprintf("%s/fixtures/PHPDocTypes/%s.php", __DIR__, $fixture));
        $this->setWarnings($warnings);
        $this->setErrors($errors);

        $this->verifyCsResults();
    }

    /**
     * PHPDocTypesSniff test provider
     *
     * @return (string|string[])[]
     */
    public static function provider(): array {
        $cases = [
            [
                'fixture' => 'phpdoc_constructor_property_promotion_readonly',
                'errors' => [],
                'warnings' => [],
            ],
            [
                'fixture' => 'phpdoc_constructor_property_promotion',
                'errors' => [],
                'warnings' => [],
            ],
            [
                'fixture' => 'phpdoc_method_multiline',
                'errors' => [],
                'warnings' => [],
            ],
            [
                'fixture' => 'phpdoc_method_union_types',
                'errors' => [],
                'warnings' => [],
            ],
            [
                'fixture' => 'phpdoc_tags_general',
                'errors' => [
                    66 => 'PHPDoc types for function error',
                    76 => 'PHPDoc types for function error',
                    86 => 'PHPDoc types for function error',
                    93 => 'PHPDoc types for function error',
                    103 => 'PHPDoc types for function error',
                    112 => 'PHPDoc types for function error',
                    122 => 'PHPDoc types for function error',
                    132 => 'PHPDoc types for function error',
                    142 => 'PHPDoc types for function error',
                    216 => 'PHPDoc types for function error',
                ],
                'warnings' => [],
            ],
            [
                'fixture' => 'phpdoc_tags_inline',
                'errors' => [],
                'warnings' => [],
            ],
        ];
        return $cases;
    }
}
