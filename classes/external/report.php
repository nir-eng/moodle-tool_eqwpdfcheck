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

namespace tool_eqwpdfcheck\external;

use core_external\external_function_parameters;
use tool_eqwpdfcheck\local\pdfstore;

/**
 * External function: return the stored full report(s) for the details modal.
 *
 * @package    tool_eqwpdfcheck
 * @copyright  2026 EqualWeb
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report extends base {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return self::fileid_params();
    }

    /**
     * Return the stored report(s) for a file.
     *
     * @param int $fileid
     * @return array
     */
    public static function execute(int $fileid): array {
        if (!self::guard_file($fileid, 'view')) {
            return self::err(get_string('err_invaliddoc', 'tool_eqwpdfcheck'));
        }
        $rec = pdfstore::get_row($fileid);
        $report = ($rec && $rec->report) ? json_decode($rec->report, true) : null;
        $after = ($rec && $rec->reportafter) ? json_decode($rec->reportafter, true) : null;
        if (!$report && !$after) {
            return self::err(get_string('err_noreport', 'tool_eqwpdfcheck'));
        }
        return self::ok(['report' => $report, 'report_after' => $after]);
    }
}
