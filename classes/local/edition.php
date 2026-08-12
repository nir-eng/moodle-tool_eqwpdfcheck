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

namespace tool_eqwpdfcheck\local;

/**
 * Edition marker for this plugin.
 *
 * This build is the EqualWeb PDF Checker: accessibility checking and scoring
 * only - no remediation, no classification and no stored API key.
 *
 * @package    tool_eqwpdfcheck
 * @copyright  2026 EqualWeb
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class edition {
    /** @var string The build's edition: 'full' or 'free' (rewritten by the checker build). */
    public const EDITION = 'free';

    /**
     * True when this build is the free "EqualWeb PDF Checker" edition.
     *
     * @return bool
     */
    public static function is_free(): bool {
        return self::EDITION === 'free';
    }
}
