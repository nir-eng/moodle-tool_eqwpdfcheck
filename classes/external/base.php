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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;

/**
 * Shared base for the plugin's AJAX external functions.
 *
 * Every function returns a single {response} JSON string carrying the
 * {success, data} envelope the ported front-end expects. Sesskey/login are
 * enforced by Moodle's web-service layer; capabilities are enforced here by
 * guard_system() / guard_file() at the top of every execute().
 *
 * @package    tool_eqwpdfcheck
 * @copyright  2026 EqualWeb
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base extends external_api {
    /**
     * States in which a row is mid-process or already done.
     *
     * No check and no remediation may start while a row is in one of these:
     * a second run would upload the wrong content, clobber the "before" score,
     * or spend a second credit on the same file.
     */
    const BUSY_STATES = ['checking', 'remediating', 'remediated_ready', 'remediated'];

    /**
     * Common return: a JSON payload string.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'response' => new external_value(PARAM_RAW, 'JSON {success, data} payload'),
        ]);
    }

    /**
     * System-context :manage — settings/registration/balance.
     */
    protected static function guard_system(): \context {
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('tool/eqwpdfcheck:manage', $context);
        return $context;
    }

    /**
     * Edition gate for the endpoints the free "EqualWeb PDF Checker" build
     * ships without (remediation, apply/restore, settings/balance and the
     * whole classification surface). Returns the standard error envelope to
     * hand straight back when this build is the free edition, or null in the
     * full edition — call it at the very top of a paid-only execute().
     *
     * @return array|null Error envelope in the free edition, null in full.
     */
    protected static function guard_full_edition(): ?array {
        if (\tool_eqwpdfcheck\local\edition::is_free()) {
            return self::err(get_string('err_freeedition', 'tool_eqwpdfcheck'));
        }
        return null;
    }

    /**
     * Per-file guard: system :manage bypasses, everyone else needs the
     * per-action capability (tool/eqwpdfcheck:$action) in the course that
     * owns the file — resolved from the file record itself, never from
     * client input, so a forged fileid cannot pick the context it is
     * checked against.
     *
     * Returns the document stored_file, or null when the fileid is invalid /
     * an unsupported type OR out of the caller's scope (courseless file, or
     * missing capability in its course). Both cases produce the exact same
     * "Invalid document." envelope so fileid probing cannot reveal which
     * files exist.
     */
    protected static function guard_file(int $fileid, string $action): ?\stored_file {
        $sys = \context_system::instance();
        self::validate_context($sys);
        $file = self::pdf($fileid);
        if (has_capability('tool/eqwpdfcheck:manage', $sys)) {
            return $file; // Admin page flow — unchanged.
        }
        if (!$file) {
            return null;
        }
        $filectx = \context::instance_by_id($file->get_contextid(), IGNORE_MISSING);
        $coursectx = $filectx ? $filectx->get_course_context(false) : false;
        if (!$coursectx || !has_capability('tool/eqwpdfcheck:' . $action, $coursectx)) {
            return null; // Non-course file, or no capability in its course.
        }
        return $file;
    }

    /**
     * Single-fileid parameter set.
     */
    protected static function fileid_params(): external_function_parameters {
        return new external_function_parameters([
            'fileid' => new external_value(PARAM_INT, 'File id'),
        ]);
    }

    /**
     * The document stored_file for a fileid, or null when invalid.
     */
    protected static function pdf(int $fileid): ?\stored_file {
        $file = get_file_storage()->get_file_by_id($fileid);
        // Invalid, unsupported-type and nonexistent files all share the ONE
        // identical "Invalid document." envelope (no file-existence oracle).
        if (
            !$file || $file->is_directory()
                || !array_key_exists((string) $file->get_mimetype(), \tool_eqwpdfcheck\local\page_renderer::DOC_MIMES)
        ) {
            return null;
        }
        return $file;
    }

    /**
     * Validate a selection of file ids for a classification bulk action:
     * deduped, capped at 200, each confirmed to be an existing supported
     * document. The caller is already system :manage (guard_system), so scope
     * is the whole site. Returns the surviving file ids.
     *
     * @param int[] $fids
     * @return int[]
     */
    protected static function cls_validate_fids(array $fids): array {
        $out = [];
        foreach (array_slice(array_unique(array_map('intval', $fids)), 0, 200) as $fid) {
            if ($fid > 0 && self::pdf($fid) !== null) {
                $out[] = $fid;
            }
        }
        return $out;
    }

    /**
     * Copy a stored_file's content to a request-scoped temp path (auto-cleaned).
     */
    protected static function stored_to_temp(\stored_file $file): string {
        $path = make_request_directory() . '/eqw-' . $file->get_id() . '.pdf';
        $file->copy_content_to($path);
        return $path;
    }

    /**
     * Success envelope: {success: true, data: ...} as the JSON response string.
     *
     * @param array $data
     * @return array
     */
    protected static function ok(array $data = []): array {
        return ['response' => json_encode(['success' => true, 'data' => $data])];
    }

    /**
     * Error envelope: {success: false, data: {message}} as the JSON response string.
     *
     * @param string $message
     * @return array
     */
    protected static function err(string $message): array {
        return ['response' => json_encode(['success' => false, 'data' => ['message' => $message]])];
    }
}
