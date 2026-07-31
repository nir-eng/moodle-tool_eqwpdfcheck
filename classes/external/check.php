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
use tool_eqwpdfcheck\local\client;
use tool_eqwpdfcheck\local\pdfstore;

/**
 * External function: start a free accessibility check.
 *
 * @package    tool_eqwpdfcheck
 * @copyright  2026 EqualWeb
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check extends base {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return self::fileid_params();
    }

    /**
     * Start a free accessibility check for a file.
     *
     * @param int $fileid
     * @return array
     */
    public static function execute(int $fileid): array {
        global $USER;
        $file = self::guard_file($fileid, 'check');
        if (!$file) {
            return self::err(get_string('err_invaliddoc', 'tool_eqwpdfcheck'));
        }
        // The free checker is PDF-only — office documents go straight to
        // remediation (which converts them to an accessible PDF).
        if ($file->get_mimetype() !== 'application/pdf') {
            return self::err(get_string('err_checkpdfonly', 'tool_eqwpdfcheck'));
        }

        // Re-checking a mid-process / ready / already-remediated file would upload
        // the wrong content and clobber the "before" score (WP v2.1.1 guard).
        $rec = pdfstore::get_row($fileid);
        $state = $rec->state ?? 'none';
        if (in_array($state, self::BUSY_STATES, true)) {
            return self::err(get_string('err_checkstate', 'tool_eqwpdfcheck'));
        }

        $client = client::for_check();
        if (is_string($client)) {
            return self::err($client);
        }
        // Unbilled and slow: stays outside the commit lock below.
        $docid = $client->upload_file(self::stored_to_temp($file), $file->get_filename());
        if (strpos((string) $docid, 'ERR:') === 0) {
            return self::err(substr($docid, 4));
        }

        // Checks are free, but a concurrent check/remediate can still clobber
        // this row's docid and state, stranding the other run. Start + state
        // write are therefore one atomic section under the poll finaliser's own
        // lock key, exactly as on the paid path.
        $factory = \core\lock\lock_config::get_lock_factory('tool_eqwpdfcheck');
        $lock = $factory->get_lock('poll_' . $fileid, 0);
        if (!$lock) {
            return self::err(get_string('err_busy', 'tool_eqwpdfcheck'));
        }
        $error = '';
        $started = null;
        try {
            // Something else may have claimed the file while the upload ran.
            if (in_array(pdfstore::get_row($fileid)->state ?? 'none', self::BUSY_STATES, true)) {
                $error = get_string('err_statechanged', 'tool_eqwpdfcheck');
            } else {
                $started = $client->start_process($docid, 'check');
                if ($started === true) {
                    pdfstore::upsert($fileid, [
                        'docid' => $docid,
                        // Which EqualWeb account this run lives in. Saving or
                        // removing the personal key mid-flight would otherwise
                        // send the next poll to the OTHER account, where the doc
                        // id does not exist, and the run would be discarded as
                        // "no longer on the EqualWeb service".
                        'docbackend' => $client->backend(),
                        'state' => 'checking',
                        // Spend/activity attribution: who initiated this check.
                        'checkedby' => (int) $USER->id,
                    ]);
                }
            }
        } finally {
            $lock->release();
        }
        if ($error !== '') {
            return self::err($error);
        }
        if (is_string($started)) {
            return self::err($started);
        }
        return self::ok(['state' => 'checking']);
    }
}
