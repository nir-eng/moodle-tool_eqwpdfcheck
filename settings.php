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
 * Admin navigation node for tool_eqwpdfcheck.
 *
 * @package    tool_eqwpdfcheck
 * @copyright  2026 EqualWeb
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    // Give the tool its own top-level entry in Site administration so admins and
    // managers can find it easily, instead of it being buried under Reports.
    $ADMIN->add('root', new admin_category(
        'tool_eqwpdfcheck_cat',
        get_string('pluginname', 'tool_eqwpdfcheck')
    ));
    $ADMIN->add('tool_eqwpdfcheck_cat', new admin_externalpage(
        'tool_eqwpdfcheck',
        get_string('pluginname', 'tool_eqwpdfcheck'),
        new moodle_url('/admin/tool/eqwpdfcheck/index.php'),
        'tool/eqwpdfcheck:manage'
    ));
}
