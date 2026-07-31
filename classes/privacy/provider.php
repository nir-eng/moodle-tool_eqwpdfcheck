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

namespace tool_eqwpdfcheck\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for tool_eqwpdfcheck.
 *
 * The plugin transmits documents to the EqualWeb accessibility service (an
 * external location) and, in its {tool_eqwpdfcheck_state} table, records which
 * user uploaded each document and which user started each free check and paid
 * remediation. The state row itself describes a FILE, so an erasure request
 * anonymises the per-user attribution columns rather than deleting the row (that
 * would strand the document's backup/accessible fileareas and its scores).
 *
 * The 'ignored_by' column stores a display NAME, not a user id, so it cannot be
 * matched to a specific account: it is declared in the metadata and cleared when
 * a whole context is purged, but a per-user erasure (which only knows the user
 * id) leaves it untouched.
 *
 * @package    tool_eqwpdfcheck
 * @copyright  2026 EqualWeb
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /** @var string The state table. */
    const TABLE = 'tool_eqwpdfcheck_state';

    /**
     * Describe the data this plugin stores and the external location it sends to.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        // Documents are uploaded to the EqualWeb accessibility service.
        $collection->add_external_location_link('equalweb', [
            'document' => 'privacy:metadata:equalweb:document',
            'filename' => 'privacy:metadata:equalweb:filename',
        ], 'privacy:metadata');

        // Per-document state, including who initiated each action.
        $collection->add_database_table(self::TABLE, [
            'userid' => 'privacy:metadata:tool_eqwpdfcheck_state:userid',
            'checkedby' => 'privacy:metadata:tool_eqwpdfcheck_state:checkedby',
            'remediatedby' => 'privacy:metadata:tool_eqwpdfcheck_state:remediatedby',
            'ignored_by' => 'privacy:metadata:tool_eqwpdfcheck_state:ignored_by',
        ], 'privacy:metadata:tool_eqwpdfcheck_state');

        return $collection;
    }

    /**
     * Contexts holding data for a user: the context of any file the user
     * uploaded, checked or remediated (resolved from the stored contextid).
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT s.contextid
                  FROM {" . self::TABLE . "} s
                 WHERE s.contextid IS NOT NULL
                   AND (s.userid = :uid1 OR s.checkedby = :uid2 OR s.remediatedby = :uid3)";
        $contextlist->add_from_sql($sql, ['uid1' => $userid, 'uid2' => $userid, 'uid3' => $userid]);
        return $contextlist;
    }

    /**
     * Users with data in a given context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        $params = ['contextid' => $context->id];
        $userlist->add_from_sql(
            'userid',
            "SELECT s.userid FROM {" . self::TABLE . "} s WHERE s.contextid = :contextid AND s.userid <> 0",
            $params
        );
        $userlist->add_from_sql(
            'checkedby',
            "SELECT s.checkedby FROM {" . self::TABLE . "} s WHERE s.contextid = :contextid AND s.checkedby IS NOT NULL",
            $params
        );
        $userlist->add_from_sql(
            'remediatedby',
            "SELECT s.remediatedby FROM {" . self::TABLE . "} s WHERE s.contextid = :contextid AND s.remediatedby IS NOT NULL",
            $params
        );
    }

    /**
     * Export every document a user is attributed on, within the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        if (!$contextlist->count()) {
            return;
        }
        $userid = $contextlist->get_user()->id;
        [$ctxsql, $ctxparams] = $DB->get_in_or_equal($contextlist->get_contextids(), SQL_PARAMS_NAMED, 'ctx');
        $params = $ctxparams + ['uid1' => $userid, 'uid2' => $userid, 'uid3' => $userid];
        $sql = "SELECT s.*
                  FROM {" . self::TABLE . "} s
                 WHERE s.contextid $ctxsql
                   AND (s.userid = :uid1 OR s.checkedby = :uid2 OR s.remediatedby = :uid3)
              ORDER BY s.contextid ASC, s.id ASC";
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $row) {
            $context = \context::instance_by_id((int) $row->contextid, IGNORE_MISSING);
            if (!$context) {
                continue;
            }
            $data = (object) [
                'filename' => (string) $row->filename,
                'state' => (string) ($row->state ?? ''),
                'uploaded' => transform::yesno((int) $row->userid === $userid),
                'startedcheck' => transform::yesno((int) $row->checkedby === $userid),
                'startedremediation' => transform::yesno((int) $row->remediatedby === $userid),
            ];
            $subcontext = [
                get_string('pluginname', 'tool_eqwpdfcheck'),
                $row->filename . ' (' . (int) $row->fileid . ')',
            ];
            writer::with_context($context)->export_data($subcontext, $data);
        }
        $rs->close();
    }

    /**
     * Delete all users' data in a context (anonymise the attribution columns —
     * the document record itself is not personal data about a single user).
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        self::anonymise_context($context->id);
    }

    /**
     * Delete one user's data across the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $params = ['ctx' => $context->id, 'uid' => $userid];
            $DB->set_field_select(self::TABLE, 'userid', 0, 'contextid = :ctx AND userid = :uid', $params);
            $DB->set_field_select(self::TABLE, 'checkedby', null, 'contextid = :ctx AND checkedby = :uid', $params);
            $DB->set_field_select(self::TABLE, 'remediatedby', null, 'contextid = :ctx AND remediatedby = :uid', $params);
        }
    }

    /**
     * Delete the data of the listed users within a single context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }
        $context = $userlist->get_context();
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $params = ['ctx' => $context->id] + $inparams;
        $DB->set_field_select(self::TABLE, 'userid', 0, "contextid = :ctx AND userid $insql", $params);
        $DB->set_field_select(self::TABLE, 'checkedby', null, "contextid = :ctx AND checkedby $insql", $params);
        $DB->set_field_select(self::TABLE, 'remediatedby', null, "contextid = :ctx AND remediatedby $insql", $params);
    }

    /**
     * Null every per-user attribution column for the rows in one context. Used
     * by the whole-context purge; 'ignored_by' (a display name) is cleared here
     * because every user's data is going, but never in a per-user erasure where
     * only a user id is known.
     *
     * @param int $contextid
     */
    protected static function anonymise_context(int $contextid): void {
        global $DB;
        $params = ['ctx' => $contextid];
        $DB->set_field_select(self::TABLE, 'userid', 0, 'contextid = :ctx', $params);
        $DB->set_field_select(self::TABLE, 'checkedby', null, 'contextid = :ctx', $params);
        $DB->set_field_select(self::TABLE, 'remediatedby', null, 'contextid = :ctx', $params);
        $DB->set_field_select(self::TABLE, 'ignored_by', null, 'contextid = :ctx', $params);
    }
}
