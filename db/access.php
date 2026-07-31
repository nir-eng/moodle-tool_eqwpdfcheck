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
 * Capabilities for tool_eqwpdfcheck.
 *
 * @package    tool_eqwpdfcheck
 * @copyright  2026 EqualWeb
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    // Site-wide administration: settings/API key, registration, and full access
    // to every PDF regardless of course.
    'tool/eqwpdfcheck:manage' => [
        'riskbitmask'          => RISK_CONFIG | RISK_PERSONAL | RISK_DATALOSS,
        'captype'              => 'write',
        'contextlevel'         => CONTEXT_SYSTEM,
        'archetypes'           => ['manager' => CAP_ALLOW],
        'clonepermissionsfrom' => 'moodle/site:config',
    ],

    // See the course PDF accessibility page, stored scores and reports.
    'tool/eqwpdfcheck:view' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => ['editingteacher' => CAP_ALLOW, 'manager' => CAP_ALLOW],
    ],

    // Run free checks (uploads course file content to the EqualWeb service).
    'tool/eqwpdfcheck:check' => [
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => ['editingteacher' => CAP_ALLOW, 'manager' => CAP_ALLOW],
    ],

    // Start paid remediation (spends the site's EqualWeb credits).

    // Replace live PDFs with remediated versions and restore backups.
];
