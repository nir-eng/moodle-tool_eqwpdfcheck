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
 * Replace / backup / restore / save-as-new for PDF files in Moodle's file store.
 *
 * Moodle files are content-addressed; stored_file::replace_file_with() swaps the
 * content while keeping the same file id + pluginfile URL, so links keep working.
 * The original is copied into our 'backup' filearea first (guarded by a lock so
 * two concurrent replaces cannot destroy it — the WordPress v2.1.0 lesson).
 *
 * @package    tool_eqwpdfcheck
 * @copyright  2026 EqualWeb
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class filemanager {
    /**
     * System context id (our fileareas live here).
     *
     * @return int
     */
    protected static function ctxid(): int {
        return (int) \context_system::instance()->id;
    }

    /**
     * Acquire the per-file replace/restore lock.
     *
     * @param int $fileid
     * @return \core\lock\lock|bool the lock, or false if it could not be obtained.
     */
    protected static function lock(int $fileid) {
        $factory = \core\lock\lock_config::get_lock_factory('tool_eqwpdfcheck');
        return $factory->get_lock('replace_' . $fileid, 30);
    }

    /**
     * The backup stored_file for an original, or null.
     *
     * @param int $fileid
     * @return \stored_file|null
     */
    public static function backup_file(int $fileid): ?\stored_file {
        $files = get_file_storage()->get_area_files(self::ctxid(), 'tool_eqwpdfcheck', 'backup', $fileid, 'id', false);
        return $files ? reset($files) : null;
    }

    /**
     * The saved-as-new accessible copy for an original, or null.
     *
     * @param int $fileid
     * @return \stored_file|null
     */
    public static function accessible_file(int $fileid): ?\stored_file {
        $files = get_file_storage()->get_area_files(self::ctxid(), 'tool_eqwpdfcheck', 'accessible', $fileid, 'id', false);
        return $files ? reset($files) : null;
    }

    /**
     * Drop the throwaway area: the replace staging area.
     *
     * 'accessible' is NOT in the list and must never be: after "save as new" it
     * holds the ONLY copy of the accessible PDF, and every shared or embedded
     * pluginfile URL points straight at it. Deleting it 404s all of them with
     * nothing to recover from. 'backup' is not in the list either: restore
     * consumes it in restore_backup(), and a caller with no backup has nothing
     * to drop.
     */
    public static function forget_transient_areas(int $fileid): void {
        get_file_storage()->delete_area_files(self::ctxid(), 'tool_eqwpdfcheck', 'temp', $fileid);
    }

    /**
     * Drop every plugin filearea for a file id.
     *
     * Only for a file that no longer exists: with the original gone, its backup
     * and its accessible copy are unreachable orphans (nothing links to them and
     * nothing can restore from them). Anything that keeps the original alive
     * must use forget_transient_areas() instead.
     *
     * 'remediated' is a LEGACY area: a pre-2.7.3 version cached the audited PDF
     * there. Nothing writes it any more (EqualWeb retains the audited output for
     * autoRemove days, so it is fetched on demand), but an upgraded site can
     * still hold copies — sweeping them here keeps a deleted original from
     * orphaning a full-size paid PDF in the file store.
     */
    public static function forget_all_areas(int $fileid): void {
        $fs = get_file_storage();
        foreach (['backup', 'accessible', 'remediated', 'temp'] as $area) {
            $fs->delete_area_files(self::ctxid(), 'tool_eqwpdfcheck', $area, $fileid);
        }
    }

    /**
     * Reset a wedged remediation back to 'checked'.
     *
     * The paid run cannot be finished (EqualWeb no longer has the audited file:
     * the document aged out of its autoRemove window), and every exit from
     * 'remediating' / 'remediated_ready' is blocked by a state guard, so the row
     * would stay stuck forever. Dropping the run lets the user simply remediate
     * again. Callers keep their own state guard: only THEY know the row is still
     * theirs to reset.
     */
    public static function reset_remediation(int $fileid): void {
        pdfstore::upsert($fileid, ['state' => 'checked']);
        pdfstore::forget($fileid, ['remdocid', 'summaryafter', 'reportafter']);
    }

    /**
     * Pluginfile URL of the accessible copy, or ''.
     *
     * @param int $fileid
     * @return string
     */
    public static function accessible_url(int $fileid): string {
        $file = self::accessible_file($fileid);
        if (!$file) {
            return '';
        }
        return \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            'tool_eqwpdfcheck',
            'accessible',
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename()
        )->out(false);
    }

    /**
     * Overwrite the original file's content with a remediated file (same file id,
     * same URL). The original is kept in our backup filearea.
     *
     * @param int $fileid
     * @param string $temppath Path to the downloaded remediated PDF.
     * @return true|string
     */
    public static function replace_in_place(int $fileid, string $temppath) {
        if (!is_readable($temppath) || filesize($temppath) < 100) {
            return get_string('err_remfilemissing', 'tool_eqwpdfcheck');
        }
        $fs = get_file_storage();
        $original = $fs->get_file_by_id($fileid);
        if (!$original || $original->is_directory()) {
            return get_string('err_nooriginal', 'tool_eqwpdfcheck');
        }
        $lock = self::lock($fileid);
        if (!$lock) {
            return get_string('err_fileprocessing', 'tool_eqwpdfcheck');
        }
        try {
            $ctx = self::ctxid();
            // Back up the original once (before any overwrite).
            if (!self::backup_file($fileid)) {
                $fs->create_file_from_storedfile([
                    'contextid' => $ctx, 'component' => 'tool_eqwpdfcheck', 'filearea' => 'backup',
                    'itemid' => $fileid, 'filepath' => '/', 'filename' => $original->get_filename(),
                ], $original);
            }
            // Stage the remediated content, then swap it in.
            $fs->delete_area_files($ctx, 'tool_eqwpdfcheck', 'temp', $fileid);
            $temp = $fs->create_file_from_pathname([
                'contextid' => $ctx, 'component' => 'tool_eqwpdfcheck', 'filearea' => 'temp',
                'itemid' => $fileid, 'filepath' => '/', 'filename' => 'remediated.pdf',
            ], $temppath);
            $original->replace_file_with($temp);
            $temp->delete();
            self::bump_caches($original);
            pdfstore::upsert($fileid, ['hasbackup' => 1, 'remediatedat' => time(), 'contenthash' => $original->get_contenthash()]);
            return true;
        } catch (\Throwable $e) {
            debugging($e->getMessage(), DEBUG_DEVELOPER);
            return get_string('err_overwritefail', 'tool_eqwpdfcheck');
        } finally {
            $lock->release();
        }
    }

    /**
     * Restore the original from backup.
     *
     * @param int $fileid
     * @return true|string
     */
    public static function restore_backup(int $fileid) {
        $fs = get_file_storage();
        $original = $fs->get_file_by_id($fileid);
        $backup = self::backup_file($fileid);
        if (!$original || !$backup) {
            return 'No backup found for this file.';
        }
        $lock = self::lock($fileid);
        if (!$lock) {
            return get_string('err_fileprocessing', 'tool_eqwpdfcheck');
        }
        try {
            $original->replace_file_with($backup);
            $backup->delete();
            self::bump_caches($original);
            pdfstore::upsert($fileid, ['hasbackup' => 0, 'contenthash' => $original->get_contenthash()]);
            return true;
        } catch (\Throwable $e) {
            debugging($e->getMessage(), DEBUG_DEVELOPER);
            return get_string('err_restorefail', 'tool_eqwpdfcheck');
        } finally {
            $lock->release();
        }
    }

    /**
     * Save the remediated file as a new accessible copy (kept in our filearea,
     * served via pluginfile). Returns the new stored_file id.
     *
     * @param int $fileid
     * @param string $temppath
     * @return int|string
     */
    public static function add_as_new(int $fileid, string $temppath) {
        clearstatcache(true, $temppath);
        if (!is_readable($temppath) || filesize($temppath) < 100) {
            return get_string('err_remfilemissing', 'tool_eqwpdfcheck');
        }
        $fs = get_file_storage();
        $original = $fs->get_file_by_id($fileid);
        if (!$original || $original->is_directory()) {
            return get_string('err_nooriginal', 'tool_eqwpdfcheck');
        }
        $ctx = self::ctxid();
        // The remediated result is always a PDF. PDF sources keep the
        // historical "-accessible.pdf" naming EXACTLY; office sources keep the
        // same base filename with a .pdf extension (report.docx -> report.pdf).
        $base = pathinfo($original->get_filename() ?: 'document.pdf', PATHINFO_FILENAME);
        $name = $original->get_mimetype() === 'application/pdf' ? $base . '-accessible.pdf' : $base . '.pdf';
        $fs->delete_area_files($ctx, 'tool_eqwpdfcheck', 'accessible', $fileid);
        try {
            $new = $fs->create_file_from_pathname([
                'contextid' => $ctx, 'component' => 'tool_eqwpdfcheck', 'filearea' => 'accessible',
                'itemid' => $fileid, 'filepath' => '/', 'filename' => $name,
            ], $temppath);
        } catch (\Throwable $e) {
            debugging($e->getMessage(), DEBUG_DEVELOPER);
            return get_string('err_savefail', 'tool_eqwpdfcheck');
        }
        // The accessible version is now live, which is what "remediated" means:
        // stamp the applied-timestamp the Executive report buckets remediations
        // by. replace_in_place() stamps the same field on the other apply path,
        // so both ways of applying a run are counted in the year it happened.
        pdfstore::upsert($fileid, ['remediatedat' => time()]);
        return (int) $new->get_id();
    }

    /**
     * Overwrite the original with the remediated file and refresh cached PDF
     * metadata (title/lang/pages re-extracted from the new content).
     *
     * @param int $fileid
     * @param string $temppath
     * @return array|string ['title' => .., 'lang' => ..] or error message.
     */
    public static function replace_and_refresh(int $fileid, string $temppath) {
        $res = self::replace_in_place($fileid, $temppath);
        if (is_string($res)) {
            return $res;
        }
        pdfstore::upsert($fileid, ['state' => 'remediated']);
        pdfstore::reset_metadata($fileid);
        $file = get_file_storage()->get_file_by_id($fileid);
        $newtitle = pdfstore::doc_title($fileid, $file);
        $newlang = pdfstore::doc_lang($fileid, $file);
        pdfstore::page_count($fileid, $file);
        return [
            'title' => ($newtitle !== '' ? $newtitle : ($file ? $file->get_filename() : '')),
            'lang' => $newlang,
        ];
    }

    /**
     * The content URL changed under a stable pluginfile URL — bump the owning
     * module's revision / purge caches so students are not served a stale PDF.
     */
    protected static function bump_caches(\stored_file $file): void {
        global $DB;
        $component = $file->get_component();
        // The mod_resource / mod_folder modules cache-bust via a per-instance revision counter.
        if ($component === 'mod_resource' || $component === 'mod_folder') {
            $context = \context::instance_by_id($file->get_contextid(), IGNORE_MISSING);
            if ($context && $context->contextlevel == CONTEXT_MODULE) {
                $cm = get_coursemodule_from_id('', $context->instanceid, 0, false, IGNORE_MISSING);
                if ($cm) {
                    $table = str_replace('mod_', '', $component);
                    $DB->set_field($table, 'revision', time(), ['id' => $cm->instance]);
                    \course_modinfo::purge_course_module_cache($cm->course, $cm->id);
                }
            }
        }
    }
}
