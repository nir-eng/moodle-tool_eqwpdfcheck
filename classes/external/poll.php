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
use core_external\external_value;
use tool_eqwpdfcheck\local\client;
use tool_eqwpdfcheck\local\filemanager;
use tool_eqwpdfcheck\local\pdfstore;

/**
 * External function: poll processing status for a check or a remediation.
 *
 * @package    tool_eqwpdfcheck
 * @copyright  2026 EqualWeb
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class poll extends base {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'fileid' => new external_value(PARAM_INT, 'File id'),
            'mode' => new external_value(PARAM_ALPHA, 'check|remediate', VALUE_DEFAULT, 'check'),
        ]);
    }

    /**
     * Poll processing status for a check or a remediation.
     *
     * @param int $fileid
     * @param string $mode check|remediate
     * @return array
     */
    public static function execute(int $fileid, string $mode = 'check'): array {
        // Poll itself stays available in the free edition — checks still need
        // it — but its remediate mode belongs to the paid flow, so only that
        // mode is edition-gated. (Every other mode value normalises to 'check'
        // below, so matching the raw value here covers exactly that mode.)
        if ($mode === 'remediate') {
            $guard = self::guard_full_edition();
            if ($guard !== null) {
                return $guard;
            }
        }
        // Poll (both modes) is gated by 'check' OR 'remediate' (any-of): it
        // never spends credits, and a role granted remediate without check
        // must be able to follow its own paid run to completion. Auto-replace
        // at finalization runs purely per the site setting so the outcome is
        // deterministic regardless of whose tab finalizes. Both guards failing
        // yields the same no-oracle envelope as a bad fileid.
        $file = self::guard_file($fileid, 'check') ?? self::guard_file($fileid, 'remediate');
        if (!$file) {
            return self::err(get_string('err_invaliddoc', 'tool_eqwpdfcheck'));
        }
        $mode = ($mode === 'remediate') ? 'remediate' : 'check';
        $rec = pdfstore::get_row($fileid);

        // A check must be polled on the SAME EqualWeb account it started on:
        // its doc id does not exist in the other one. Remediation is always on
        // the personal key, so it has nothing to bind to.
        $client = $mode === 'remediate'
            ? client::for_remediation()
            : client::for_check(($rec->docbackend ?? '') ?: null);
        if (is_string($client)) {
            // The account a running CHECK was started on is unreachable (its
            // key was removed, or the registration lapsed). The doc id only
            // exists in THAT account, so the run cannot be resumed. Reset
            // instead of failing forever: 'checking' is a busy state, so a row
            // left in it can never be re-checked and would be wedged with no
            // way out. A check is free, so re-running it costs nothing.
            // A run on the shared check service stops because this site is no
            // longer registered there: say that, in the words for the user's role.
            if ($mode === 'check' && (pdfstore::get_row($fileid)->state ?? '') === 'checking') {
                pdfstore::forget($fileid, ['state', 'docid', 'docbackend']);
                return self::err(($rec->docbackend ?? '') === 'key'
                    ? get_string('err_checkaccountgone', 'tool_eqwpdfcheck')
                    : client::not_registered_message());
            }
            return self::err($client);
        }
        $docid = $mode === 'remediate' ? ($rec->remdocid ?? '') : ($rec->docid ?? '');
        if (!$docid) {
            return self::err(get_string('err_noprocess', 'tool_eqwpdfcheck'));
        }

        $status = $client->get_status($docid);
        if (is_string($status)) {
            // The document can vanish from EqualWeb (retention / manual
            // delete). Endlessly polling a gone doc would wedge the row, so
            // reset it and let the user simply run the process again.
            //
            // doc_gone() is asserted by the client only when it actually
            // fetched the account's document list and the doc was not in it.
            // Any other failure leaves the row alone: discarding a PAID
            // remediation on, say, a gateway error would be pure loss.
            if ($client->doc_gone()) {
                $now = pdfstore::get_row($fileid)->state ?? '';
                if ($mode === 'remediate' && $now === 'remediating') {
                    filemanager::reset_remediation($fileid);
                    return self::err(get_string('err_docgone_rem', 'tool_eqwpdfcheck'));
                }
                if ($mode === 'check' && $now === 'checking') {
                    pdfstore::forget($fileid, ['state', 'docid', 'docbackend']);
                    return self::err(get_string('err_docgone_check', 'tool_eqwpdfcheck'));
                }
            }
            return self::err($status);
        }
        // Captured, not written: the page count feeds analytics totals and
        // credit estimates, so a stale poll for a superseded run must not
        // overwrite it. It is committed below, once the CAS has proved this
        // poll still owns the run. A document always has at least one page, so
        // a zero here means "unknown", not "no pages".
        $pages = ((int) ($status['numberOfPages'] ?? 0)) > 0 ? (int) $status['numberOfPages'] : null;
        $rawstatus = $status['status'] ?? '';
        if ($rawstatus !== 'done') {
            return self::ok(['state' => 'processing', 'raw_status' => $rawstatus]);
        }

        if ($mode === 'check') {
            $report = $client->get_report($docid, 'original');
            if (is_string($report)) {
                return self::err($report);
            }
            $summary = [
                'score' => isset($report['checkerScore']) ? (int) $report['checkerScore'] : null,
                'issues' => $report['totalIssues'] ?? [],
                'checked_at' => time(),
            ];

            // Commit under the same single-writer lock as the remediate path:
            // a stale check poll must never clobber a row that has meanwhile
            // moved on (e.g. to 'remediating' — that would strand a paid run).
            $factory = \core\lock\lock_config::get_lock_factory('tool_eqwpdfcheck');
            $lock = $factory->get_lock('poll_' . $fileid, 5);
            if (!$lock) {
                return self::ok(['state' => 'processing', 'raw_status' => 'finalizing']);
            }
            try {
                $rec = pdfstore::get_row($fileid);
                if (($rec->docid ?? '') !== $docid || ($rec->state ?? '') !== 'checking') {
                    // Stale run — discard, report the row as it is now.
                    return self::ok(['state' => $rec->state ?? 'none']);
                }
                $commit = ['summary' => $summary, 'report' => $report, 'state' => 'checked'];
                if ($pages !== null) {
                    $commit['pages'] = $pages;
                }
                pdfstore::upsert($fileid, $commit);
                return self::ok([
                    'state' => 'checked',
                    'summary' => $summary,
                    'report_url' => client::report_url($docid),
                ]);
            } finally {
                $lock->release();
            }
        }

        // Remediation finished. Fetch the combined report (reads only — every
        // write happens inside the single-writer commit section below, so a
        // stale poll can never pollute a row that restore/apply just changed).
        $report = $client->get_report($docid, 'both');
        $after = null;
        if (!is_string($report) && !empty($report['audited'])) {
            $after = [
                'score' => isset($report['audited']['remediatedScore']) ? (int) $report['audited']['remediatedScore'] : null,
                'issues' => $report['audited']['totalIssues'] ?? [],
                'checked_at' => time(),
            ];
        }

        // EqualWeb RETAINS the audited output (deleteAfterAudit is never sent as
        // true; retention is the account's autoRemove window), so a finished run
        // no longer has to secure a local copy: download / replace / save-as-new
        // fetch the file from EqualWeb when they actually need it.
        //
        // The two AUTO branches are the exception — they write the file as part
        // of this very poll, so they need the bytes NOW. The download is slow and
        // unbilled, so it stays outside the commit lock.
        $ispdf = $file->get_mimetype() === 'application/pdf';
        $defaults = client::audit_defaults();
        $autoreplace = !empty($defaults['auto_replace']) && $ispdf;
        $autosave = !$ispdf;
        $tmp = '';
        $dl = true;
        if ($autoreplace || $autosave) {
            $tmp = make_request_directory() . '/eqw-remediated.pdf';
            $dl = $client->download_to_file($docid, $tmp);
        }

        // Commit section — exactly ONE request may finalize a remediation.
        $factory = \core\lock\lock_config::get_lock_factory('tool_eqwpdfcheck');
        $lock = $factory->get_lock('poll_' . $fileid, 5);
        if (!$lock) {
            return self::ok(['state' => 'processing', 'raw_status' => 'finalizing']);
        }
        try {
            $rec = pdfstore::get_row($fileid);
            if (($rec->remdocid ?? '') !== $docid || ($rec->state ?? '') !== 'remediating') {
                // Another request already finalized this run, or a restore /
                // new run replaced it. Discard OUR work — never write anything
                // for a stale run — and report the row as it is now.
                $afternow = ($rec && $rec->summaryafter !== null && $rec->summaryafter !== '')
                    ? json_decode($rec->summaryafter, true) : null;
                return self::ok([
                    'state' => $rec->state ?? 'none',
                    'summary' => self::summary_array($fileid),
                    'summary_after' => $afternow,
                    'report_url_after' => !empty($rec->remdocid) ? client::report_url($rec->remdocid) : '',
                ]);
            }

            // This poll owns the finalization: only now is the row written.
            if ($pages !== null) {
                pdfstore::upsert($fileid, ['pages' => $pages]);
            }
            if ($after !== null) {
                pdfstore::upsert($fileid, ['summaryafter' => $after, 'reportafter' => $report['audited']]);
            }
            $hassummary = $rec->summary !== null && $rec->summary !== '';
            // Uses isset(), NOT empty(): a never-checked, fully untagged PDF scores
            // 0, and empty(0) is true. Dropping the "before" summary there is
            // exactly backwards - the 0-to-99 case is the one worth showing.
            if (!is_string($report) && isset($report['original']['checkerScore']) && !$hassummary) {
                pdfstore::upsert($fileid, [
                    'summary' => [
                        'score' => (int) $report['original']['checkerScore'],
                        'issues' => $report['original']['totalIssues'] ?? [],
                        'checked_at' => time(),
                    ],
                    'report' => $report['original'],
                ]);
            }

            // Auto-replace: when enabled, apply immediately instead of asking.
            // PDF sources only — the remediated result is always a PDF, so it
            // must never overwrite an office original; office rows auto-SAVE
            // below.
            $meta = null;
            if ($autoreplace && $dl === true) {
                $meta = filemanager::replace_and_refresh($fileid, $tmp);
            }

            // Office auto-save: an office remediation always produces a NEW
            // accessible PDF — there is no replace decision to ask for, so
            // skip the choice step and save it into the plugin's 'accessible'
            // filearea right away (the exact add_as_new path apply-new uses:
            // {basename}.pdf, original owner's file untouched). This sits
            // inside the same single-writer commit as auto-replace — the CAS
            // above already proved this poll owns the finalization, so
            // nothing else can apply the same result twice. On ANY failure,
            // fall through to remediated_ready: EqualWeb still holds the
            // audited file, so the manual choice box works from there.
            $newfileid = null;
            if ($autosave && $dl === true) {
                $saved = filemanager::add_as_new($fileid, $tmp);
                if (!is_string($saved)) {
                    $newfileid = (int) $saved;
                }
            }

            if ($meta !== null && !is_string($meta)) {
                return self::ok($meta + [
                    'state' => 'remediated',
                    'message' => get_string('msg_autoreplaced', 'tool_eqwpdfcheck'),
                    'summary' => self::summary_array($fileid),
                    'summary_after' => $after,
                    'report_url_after' => client::report_url($docid),
                ]);
            }

            if ($newfileid !== null) {
                // Mirror apply-new exactly: the accessible copy is now its own
                // stored file, so advance the row (state + newfileid). No backup
                // exists — the original office file was never touched.
                pdfstore::upsert($fileid, ['state' => 'remediated', 'newfileid' => $newfileid]);
                return self::ok([
                    'state' => 'remediated',
                    'message' => get_string('msg_savednew', 'tool_eqwpdfcheck'),
                    'new_url' => filemanager::accessible_url($fileid),
                    'summary' => self::summary_array($fileid),
                    'summary_after' => $after,
                    'report_url_after' => client::report_url($docid),
                ]);
            }

            pdfstore::upsert($fileid, ['state' => 'remediated_ready']);
            return self::ok([
                'state' => 'remediated_ready',
                'summary' => self::summary_array($fileid),
                'summary_after' => $after,
                'report_url_after' => client::report_url($docid),
            ]);
        } finally {
            $lock->release();
        }
    }

    /**
     * Decoded 'summary' array for a file, or null.
     */
    protected static function summary_array(int $fileid) {
        $rec = pdfstore::get_row($fileid);
        if (!$rec || $rec->summary === null || $rec->summary === '') {
            return null;
        }
        return json_decode($rec->summary, true);
    }
}
