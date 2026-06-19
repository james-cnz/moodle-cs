<?php

// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANdTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace MoodleHQ\MoodleCS\moodle\Sniffs\Commenting;

use MoodleHQ\MoodleCS\moodle\Util\Docblocks;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * Checks PHPDoc types.
 *
 * @copyright  2026 James Calder and Otago Polytechnic
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class PHPDocTypesSniff implements Sniff
{
    /**
     * Register for class tags.
     *
     * @return array
     */
    public function register() {
        return [
            T_FUNCTION,
        ];
    }

    /**
     * Processes PHP files and perform PHPDoc type checks.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position in the stack.
     * @return int|null
     */
    public function process(File $phpcsFile, $stackPtr): ?int {
        return $this->processFunction($phpcsFile, $stackPtr);
    }

    /**
     * Processes a function.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int $stackPtr The position in the stack.
     * @return int|null
     */
    protected function processFunction(File $phpcsFile, int $stackPtr): ?int {
        $tokens = $phpcsFile->getTokens();

        // Get Doc Block.
        $docBlockPtr = Docblocks::getDocBlockPointer($phpcsFile, $stackPtr);
        if ($docBlockPtr === null) {
            // No DocBlock for this function.
            return null;
        }

        // Get native parameters.
        $nativeParams = $phpcsFile->getMethodParameters($stackPtr);
        foreach ($nativeParams as $index => $nativeParam) {
            $nativeParams[$index]['type_hint'] = $this->simplifyType($nativeParam['type_hint']);
        }

        // Get Doc parameters.
        $docParams = [];
        $docParamTagPtrs = Docblocks::getMatchingDocTags($phpcsFile, $docBlockPtr, '@param');
        foreach ($docParamTagPtrs as $docParamTagPtr) {
            $docParamToken = $tokens[$docParamTagPtr + 2] ?? null;
            $docParamString = ($docParamToken && $docParamToken['code'] == T_DOC_COMMENT_STRING) ?
                $docParamToken['content'] : '';
            $docParamString = preg_replace('/ +/', ' ', trim($docParamString)) . '  ';
            $docParam2ndSpace = strpos($docParamString, ' ', strpos($docParamString, ' ') + 1);
            $docParamString = substr($docParamString, 0, $docParam2ndSpace);
            $docParamString = preg_replace(
                '/& | &|\\.\\.\\. | \\.\\.\\.|&\\.\\.\\. |& \\.\\.\\.| &\\.\\.\\./',
                ' ',
                $docParamString
            );
            $docParamArray = explode(' ', $docParamString);
            $docParams[] = [
                'type_hint' => $this->simplifyType($docParamArray[0]),
                'name' => $docParamArray[1],
            ];
        }

        // Check parameters match.
        $match = count($docParams) == count($nativeParams);
        if ($match) {
            foreach ($docParams as $index => $docParam) {
                $nativeParam = $nativeParams[$index];
                $match = $match && ($docParam['name'] !== '') && ($docParam['type_hint'] !== '');
                $match = $match && ($docParam['name'] == $nativeParam['name']);
                if ($match) {
                    $match = $match && $this->typeMatch($docParam['type_hint'], $nativeParam['type_hint']);
                }
                if (!$match) {
                    break;
                }
            }
        }

        // Check return tags have types.
        if ($match) {
            $docRetTagPtrs = Docblocks::getMatchingDocTags($phpcsFile, $docBlockPtr, '@return');
            foreach ($docRetTagPtrs as $docRetTagPtr) {
                $docRetToken = $tokens[$docRetTagPtr + 2] ?? null;
                $docRetString = ($docRetToken && $docRetToken['code'] == T_DOC_COMMENT_STRING) ?
                    $docRetToken['content'] : '';
                $docRetString = trim($docRetString);
                $match = $match && ($docRetString != '');
                if (!$match) {
                    break;
                }
            }
        }

        // Report error if appropriate.
        if (!$match) {
            $phpcsFile->addError(
                'PHPDoc types for function error',
                $docBlockPtr,
                'PHPDocTypesFunction'
            );
        }

        return null;
    }

    /**
     * Simplify type to make comparison easier.
     *
     * @param string $type The type to be simplified
     * @return string
     */
    protected function simplifyType(string $type): string {
        // Simplify multi-type arrays.
        do {
            $type = preg_replace('/\\([^()]*\\)\\[\\]/', 'mixed[]', $type, -1, $count);
        } while ($count > 0);

        // Simplify single-type arrays.
        $type = preg_replace('/[^|&\\[\\]?]+\\[\\]/', 'array', $type);

        // Simplify nullable types.
        $type = preg_replace('/\\?/', 'null|', $type);

        // Remove namespaces.
        $type = preg_replace('/[^|&\\\\]+\\\\/', '\\', $type);
        $type = preg_replace('/\\\\/', '', $type);

        return $type;
    }

    /**
     * Check if types match
     *
     * @param string $docTypeStr
     * @param string $nativeTypeStr
     * @return bool
     */
    protected function typeMatch(string $docTypeStr, $nativeTypeStr): bool {
        if ($nativeTypeStr == '' || $docTypeStr == $nativeTypeStr) {
            return true;
        }

        $docTypeArray = explode('|', $docTypeStr);
        $nativeTypeArray = explode('|', $nativeTypeStr);

        // We need to check every Doc type.
        foreach ($docTypeArray as $docType) {
            $docParts = explode('&', $docType);
            if (in_array('never', $docParts)) {
                continue;
            }

            // And make sure there is a matching native type.
            $found = false;
            foreach ($nativeTypeArray as $nativeType) {
                $nativeParts = explode('&', $nativeType);
                if (
                    $nativeType == 'mixed' && $docType != 'void'
                    || count(array_diff($nativeParts, $docParts)) == 0
                ) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }

        return true;
    }
}
