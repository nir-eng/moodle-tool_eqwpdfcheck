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
 * The two-edition seam (mirrors the Shopify app's EQW_EDITION split).
 *
 * 'full' is the canonical paid product (this tree, unchanged behavior).
 * 'free' is the "EqualWeb PDF Checker" build: checker only - no remediation,
 * no classification, no key storage, no purchase surfaces. The free plugin
 * tree is GENERATED from this canonical source by deploy/build_moodle_checker.py,
 * which renames the component and flips the EDITION constant below to 'free'.
 * Never hand-edit the generated tree.
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
