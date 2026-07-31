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
 * AJAX external functions for tool_eqwpdfcheck.
 *
 * @package    tool_eqwpdfcheck
 * @copyright  2026 EqualWeb
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$ns = 'tool_eqwpdfcheck\\external\\';

// The 'capabilities' entries are documentation-only in Moodle — the real
// enforcement lives in each function's execute() (base::guard_file() checks
// the per-action capability at the file's own course context, or system
// :manage; base::guard_system() is strictly system :manage).
$functions = [
    'tool_eqwpdfcheck_check' => [
        'classname' => $ns . 'check', 'methodname' => 'execute',
        'description' => 'Start a free accessibility check', 'type' => 'write',
        'ajax' => true, 'capabilities' => 'tool/eqwpdfcheck:check',
    ],
    // Poll is any-of check OR remediate, not check alone: a role granted
    // remediate without check must still be able to follow its own paid run to
    // completion (poll::execute() enforces exactly that).
    'tool_eqwpdfcheck_poll' => [
        'classname' => $ns . 'poll', 'methodname' => 'execute',
        'description' => 'Poll processing status', 'type' => 'read',
        'ajax' => true, 'capabilities' => 'tool/eqwpdfcheck:check, tool/eqwpdfcheck:remediate',
    ],
    'tool_eqwpdfcheck_report' => [
        'classname' => $ns . 'report', 'methodname' => 'execute',
        'description' => 'Return the stored report(s)', 'type' => 'read',
        'ajax' => true, 'capabilities' => 'tool/eqwpdfcheck:view',
    ],
    'tool_eqwpdfcheck_register_site' => [
        'classname' => $ns . 'register_site', 'methodname' => 'execute',
        'description' => 'Register the site for free checks', 'type' => 'write',
        'ajax' => true, 'capabilities' => 'tool/eqwpdfcheck:manage',
    ],

    // Classification & triage (v2.8.0). Every action is system :manage —
    // enforced by guard_system() in each execute(); there is deliberately no
    // "exempt" category and Ignore is logged + reversible, never a delete.
];
