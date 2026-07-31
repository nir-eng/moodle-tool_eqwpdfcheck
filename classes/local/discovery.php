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
 * Populates the indexed state table from the (huge) {files} table so the admin
 * list can paginate server-side instead of scanning {files} on every load.
 *
 * @package    tool_eqwpdfcheck
 * @copyright  2026 EqualWeb
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class discovery {
    /**
     * Scan {files} for supported documents — PDF + Word/PowerPoint/Excel
     * (one row per occurrence — duplicate content in two places gets two
     * rows, so course scoping stays sound; drafts and our own fileareas
     * excluded) and upsert them into the state table; prune vanished files.
     *
     * @return int number of document occurrences indexed.
     */
    public static function run(): int {
        global $DB;

        [$mimesql, $mimeparams] = $DB->get_in_or_equal(
            array_keys(page_renderer::DOC_MIMES),
            SQL_PARAMS_NAMED,
            'mime'
        );
        $sql = "SELECT f.id, f.contenthash, f.pathnamehash, f.contextid, f.component, f.filearea,
                       f.itemid, f.filepath, f.filename, f.filesize, f.timecreated, f.userid
                  FROM {files} f
                 WHERE f.mimetype $mimesql
                   AND f.filename <> :dot
                   AND f.filesize > 0
                   AND NOT (f.component = :usercomp AND f.filearea = :draftarea)
                   AND f.component <> :ourcomp
              ORDER BY f.id ASC";
        $params = $mimeparams + [
            'dot' => '.',
            'usercomp' => 'user', 'draftarea' => 'draft', 'ourcomp' => 'tool_eqwpdfcheck',
        ];

        $rs = $DB->get_recordset_sql($sql, $params);
        $count = 0;
        foreach ($rs as $f) {
            $existing = $DB->get_record('tool_eqwpdfcheck_state', ['fileid' => $f->id], 'id, contenthash');
            $row = (object) [
                'contenthash' => $f->contenthash,
                'pathnamehash' => $f->pathnamehash,
                'contextid' => $f->contextid,
                'component' => $f->component,
                'filearea' => $f->filearea,
                'itemid' => $f->itemid,
                // Files are indexed at ANY filepath, so the pluginfile URL the
                // list renders needs the real one - '/' only works at the root.
                'filepath' => $f->filepath,
                'filename' => $f->filename,
                'filesize' => $f->filesize,
                'filetimecreated' => $f->timecreated,
                'courseid' => self::courseid_for((int) $f->contextid),
                'userid' => (int) $f->userid,
                'timemodified' => time(),
            ];
            if ($existing) {
                $row->id = $existing->id;
                $DB->update_record('tool_eqwpdfcheck_state', $row);
            } else {
                $row->fileid = $f->id;
                $row->hasbackup = 0;
                $DB->insert_record('tool_eqwpdfcheck_state', $row);
            }
            $count++;
        }
        $rs->close();

        // Prune state rows whose underlying file no longer exists — including
        // our fileareas (backup/accessible/remediated caches), so deleted
        // originals do not orphan full-size PDF copies in the file store.
        $stateids = $DB->get_fieldset_sql('SELECT fileid FROM {tool_eqwpdfcheck_state}');
        foreach ($stateids as $sid) {
            if (!$DB->record_exists('files', ['id' => $sid])) {
                $DB->delete_records('tool_eqwpdfcheck_state', ['fileid' => $sid]);
                filemanager::forget_all_areas((int) $sid);
            }
        }

        set_config('lastdiscovery', time(), 'tool_eqwpdfcheck');
        return $count;
    }

    /**
     * Course id owning a file's context (0 when none/system), cached per
     * contextid — discovery iterates many files sharing the same context.
     *
     * @param int $contextid
     * @return int
     */
    protected static function courseid_for(int $contextid): int {
        static $cache = [];
        if (!isset($cache[$contextid])) {
            $context = \context::instance_by_id($contextid, IGNORE_MISSING);
            $coursecontext = $context ? $context->get_course_context(false) : false;
            $cache[$contextid] = $coursecontext ? (int) $coursecontext->instanceid : 0;
        }
        return $cache[$contextid];
    }

    /**
     * Run discovery at most once per 10 minutes when the admin page is opened.
     */
    public static function maybe_run_on_view(): void {
        global $DB;
        $last = (int) get_config('tool_eqwpdfcheck', 'lastdiscovery');
        if (!$DB->record_exists('tool_eqwpdfcheck_state', []) || $last < time() - 600) {
            self::run();
        }
    }
}
