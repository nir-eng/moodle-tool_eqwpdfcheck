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
 * EqualWeb PDF Accessibility admin behaviour (ported from the WP/Drupal front-end).
 *
 * @module     tool_eqwpdfcheck/admin
 * @copyright  2026 EqualWeb
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/ajax'], function($, Ajax) {
    'use strict';

    return {
        init: function(cfg) {
            var i18n = cfg.i18n || {};
            var POLL_INTERVAL = 5000;
            // Per-capability booleans (course page); absent = admin page = all true.
            // manage gates the classification admin controls (bulk tag/ignore,
            // clickable chips, restore-all); it defaults OFF for course teachers.
            var can = $.extend({check: true, remediate: true, replace: true, manage: false}, cfg.can || {});
            // FREE edition ("EqualWeb PDF Checker"): checker-only UI. PHP omits
            // the bulk bar, category panel, chips, checkboxes and the after
            // column; renderRow() below omits the row-level paid pieces
            // (remediation buttons, Details) and classification wiring is
            // skipped entirely. Mirrors the Shopify free-checker rows.
            var free = !!cfg.free;

            // Inline SVG UI icons (~12px, stroke follows the text color, decorative).
            // IDENTICAL markup to page_renderer::icon() — PHP-rendered and
            // JS-rendered links must look the same; change both together.
            var ICONS = {
                external: '<svg class="eqw-i" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M14 4h6v6"/><path d="M20 4l-9 9"/><path d="M9 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-3"/></svg>',
                download: '<svg class="eqw-i" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 4v11"/><path d="M6 10l6 6 6-6"/><path d="M4 20h16"/></svg>'
            };

            // The external icon is decorative (aria-hidden); this visually-hidden
            // text (Moodle's .accesshide) makes screen readers announce that the
            // link opens an external site. Emit it immediately BEFORE
            // ICONS.external on every external link.
            var EXT_SR = '<span class="accesshide"> (' + (i18n.externalLink || 'external link') + ')</span>';

            // Route through Moodle's web-service AJAX (adds sesskey); each function
            // returns {response: "<json>"} carrying the {success, data} envelope.
            function ajax(action, data) {
                return Ajax.call([{methodname: 'tool_eqwpdfcheck_' + action, args: data || {}}])[0]
                    .then(function(res) {
                        return JSON.parse(res.response);
                    });
            }

            /* -------------------- Row rendering -------------------- */

            function scoreBadge(score) {
                score = Math.max(0, Math.min(100, Math.round(Number(score) || 0)));
                var cls = score >= 90 ? 'eqw-ok' : (score >= 70 ? 'eqw-warn' : 'eqw-bad');
                return '<span class="eqw-score"><span class="eqw-badge ' + cls + '" title="' + i18n.score + '">' + score + '/100</span>' +
                    '<span class="eqw-meter" aria-hidden="true"><i class="' + cls + '-fill" style="width:' + score + '%"></i></span></span>';
            }

            // A score is UNKNOWN, not zero, when it is missing: null, undefined
            // and '' all mean "we do not have one". 0 is a real score (an
            // untagged PDF genuinely scores 0) and must still render a badge.
            function noScore(score) {
                return score === '' || score === undefined || score === null;
            }

            function scoreCell(score, reportUrl) {
                // The score and the report link are independent: a remediation can
                // finish and produce a report at EqualWeb even when the score fetch
                // failed, so a missing score renders the placeholder but the "View
                // report" link is still shown whenever a reportUrl is present. (A
                // score of 0 is a real score, rendered by scoreBadge, never missing.)
                var html = noScore(score) ? '<span class="eqw-empty">—</span>' : scoreBadge(Number(score));
                if (reportUrl) {
                    html += ' <a class="eqw-viewlink" href="' + reportUrl + '" target="_blank" rel="noopener" title="' +
                        i18n.viewReport + '">' + i18n.viewReport + EXT_SR + ICONS.external + '</a>';
                }
                return html;
            }

            function renderRow($row) {
                var state = $row.data('state');
                var score = $row.data('score');
                var scoreAfter = $row.data('score-after');
                var url = $row.attr('data-report-url');
                var urlAfter = $row.attr('data-report-url-after');
                $row.find('.eqw-before-cell').html(scoreCell(score, url));
                if (!free) {
                    // Free edition has no after-score (nothing remediates): PHP
                    // drops the column header and may drop the <td> too — when
                    // the cell does exist it must simply stay empty.
                    $row.find('.eqw-after-cell').html(scoreCell(scoreAfter, urlAfter));
                }
                var $status = $row.find('.eqw-status-cell').empty();
                var $actions = $row.find('.eqw-actions-cell').empty();

                function btn(action, label, primary) {
                    return $('<button type="button" class="button ' + (primary ? 'button-primary' : '') + '"></button>')
                        .attr('data-action', action).text(label);
                }

                // Office documents (Word/PowerPoint/Excel) have their own,
                // smaller state machine: no checker, no replace, no restore —
                // remediation always returns a NEW accessible PDF. PDF rows
                // keep the exact behavior below, untouched.
                var doctype = $row.attr('data-doctype') || 'pdf';
                if (doctype !== 'pdf') {
                    if (free) {
                        // Free checker: the checker is PDF-only and remediation
                        // is a paid concept the checker never mentions, so an
                        // office row has no action at all in any state — a
                        // neutral dash (matching the empty score cells) reads
                        // as "nothing here" without the paid framing.
                        $status.html('<span class="eqw-badge eqw-plain">—</span>');
                        $actions.html('<span class="eqw-badge eqw-plain">—</span>');
                        return;
                    }
                    switch (state) {
                        case 'remediating':
                            if (can.check || can.remediate) {
                                $status.html('<span class="eqw-spin"></span> ' + i18n.remediating);
                                startPolling($row, 'remediate');
                            } else {
                                $status.html('<span class="eqw-badge">' + i18n.inProgress + '</span>');
                            }
                            break;
                        case 'remediated_ready':
                            $status.html('<span class="eqw-badge eqw-warn">' + i18n.ready + '</span>');
                            // Save-as-new needs the replace capability (same
                            // per-action gate as PDF apply); Download is
                            // view-level.
                            var $oReady = $('<div class="eqw-actions-row"/>');
                            if (can.replace) {
                                $oReady.append(btn('apply-new', i18n.saveAsPdf, true)).append(' ');
                            }
                            $oReady.append(btn('download', i18n.download));
                            $actions.append($('<div class="eqw-choice"/>')
                                .append($('<p/>').text(i18n.officeReady))
                                .append($oReady));
                            break;
                        case 'remediated':
                            // The accessible PDF was saved as a new file (the
                            // auto-save at poll time, or apply-new via the
                            // fallback choice box): offer it for viewing and
                            // for download.
                            $status.html('<span class="eqw-badge eqw-ok">✓ ' + i18n.accessible + '</span>');
                            if ($row.attr('data-new-url')) {
                                $actions.append(
                                    $('<a class="button" target="_blank" rel="noopener"></a>')
                                        .attr('href', $row.attr('data-new-url'))
                                        .text(i18n.openAccessiblePdf).append(EXT_SR).append(ICONS.external)
                                ).append(' ').append(
                                    $('<a class="button"></a>')
                                        .attr('href', $row.attr('data-new-url'))
                                        .attr('download', '')
                                        .text(i18n.downloadPdf).append(ICONS.download)
                                );
                            }
                            break;
                        default:
                            // 'none', a reset row ('checked') and anything
                            // unknown all offer the single entry point: send
                            // for remediation. The button is rendered DISABLED
                            // (with a tooltip) when no personal API key is
                            // configured — free mode only checks PDFs.
                            $status.html('<span class="eqw-badge eqw-plain">' + i18n.notRemediated + '</span>');
                            if (can.remediate) {
                                var $send = btn('remediate', i18n.sendRemediation, true);
                                if (!cfg.hasApiKey) {
                                    $send.prop('disabled', true).attr('title', i18n.keyRequired);
                                }
                                $actions.append($send);
                            }
                    }
                    return;
                }

                // Free edition: the remediation states cannot occur (nothing
                // remediates). If one is ever seen anyway (edition switch over
                // old data), fall back to the plain checked badge rather than
                // offering paid-flow actions.
                if (free && (state === 'remediated' || state === 'remediated_ready')) {
                    state = 'checked';
                }
                switch (state) {
                    case 'checking':
                    case 'remediating':
                        // Polling is gated by check OR remediate (any-of) — a
                        // remediate-only role must be able to follow its own
                        // paid run to completion. Users with neither capability
                        // get a static in-progress badge instead of a
                        // 403-spamming loop (the state refreshes on reload).
                        if (can.check || can.remediate) {
                            $status.html('<span class="eqw-spin"></span> ' + (state === 'checking' ? i18n.checking : i18n.remediating));
                            startPolling($row, state === 'remediating' ? 'remediate' : 'check');
                        } else {
                            $status.html('<span class="eqw-badge">' + i18n.inProgress + '</span>');
                        }
                        break;
                    case 'checked':
                        if (free) {
                            // Free checker: a successfully checked file is
                            // simply "Checked" (success style) — no warning
                            // badge about remediation, no Details/Remediate.
                            // The score cell already links to the full report.
                            $status.html('<span class="eqw-badge eqw-ok">' + i18n.checkedBadge + '</span>');
                            break;
                        }
                        $status.html('<span class="eqw-badge eqw-warn">' + i18n.notRemediated + '</span>');
                        $actions.append(btn('report', i18n.details));
                        if (can.remediate) {
                            $actions.append(' ').append(btn('remediate', i18n.remediate, true));
                        }
                        break;
                    case 'remediated_ready':
                        $status.html('<span class="eqw-badge eqw-warn">' + i18n.ready + '</span>');
                        var $readyRow = $('<div class="eqw-actions-row"/>');
                        if (can.replace) {
                            $readyRow.append(btn('apply-replace', i18n.applyReplace, true)).append(' ')
                                .append(btn('apply-new', i18n.applyNew)).append(' ');
                        }
                        $readyRow.append(btn('download', i18n.applyDownload));
                        $actions.append($('<div class="eqw-choice"/>')
                            .append($('<p/>').text(i18n.remediateReady))
                            .append($readyRow));
                        break;
                    case 'remediated':
                        $status.html('<span class="eqw-badge eqw-ok">✓ ' + i18n.accessible + '</span>');
                        $actions.append(btn('report', i18n.details));
                        if (can.replace) {
                            // No hasbackup gate: restore.php has a no-backup
                            // escape (clears state after save-as-new).
                            $actions.append(' ').append(btn('restore', i18n.restore));
                        }
                        if ($row.attr('data-new-url')) {
                            $actions.append(' ').append(
                                $('<a class="button" target="_blank" rel="noopener"></a>')
                                    .attr('href', $row.attr('data-new-url')).text(i18n.openCopy).append(EXT_SR).append(ICONS.external));
                        }
                        break;
                    default:
                        $status.html('<span class="eqw-badge eqw-plain">' + i18n.notChecked + '</span>');
                        if (can.check) {
                            $actions.append(btn('check', i18n.check, true)).append(' ');
                        }
                        if (!free && can.remediate) {
                            $actions.append(btn('remediate', i18n.remediate));
                        }
                }
                // Re-evaluate the serial check lock after every row (re-)render.
                applyCheckLock();
            }

            function setState($row, state, score, hasBackup, scoreAfter) {
                $row.data('state', state).attr('data-state', state);
                if (score !== undefined && score !== null) {
                    $row.data('score', score).attr('data-score', score);
                }
                if (scoreAfter !== undefined && scoreAfter !== null) {
                    $row.data('score-after', scoreAfter).attr('data-score-after', scoreAfter);
                }
                if (hasBackup !== undefined) {
                    $row.data('has-backup', hasBackup ? 1 : 0);
                }
                renderRow($row);
            }

            function updateFileMeta($row, data) {
                if (data.title) {
                    var $link = $row.find('.eqw-col-file > a, .eqw-col-file > span');
                    $link.contents().filter(function() {
                        return this.nodeType === 3;
                    }).remove();
                    $link.append(document.createTextNode(' ' + data.title));
                    var filename = $row.find('.eqw-filename').text() || '';
                    $row.attr('data-search', (data.title + ' ' + filename).toLowerCase());
                }
                if (data.lang !== undefined) {
                    $row.find('.eqw-lang-cell').text(data.lang || '—');
                }
                if (data.new_url) {
                    $row.attr('data-new-url', data.new_url);
                }
            }

            function showError($row, message) {
                $row.find('.eqw-status-cell').html('<span class="eqw-badge eqw-bad">' + i18n.error + '</span> <small></small>')
                    .find('small').text(message || '');
            }

            /* -------------------- Polling -------------------- */

            function startPolling($row, mode) {
                if ($row.data('polling')) {
                    return;
                }
                $row.data('polling', true);
                var failures = 0;

                function tick() {
                    ajax('poll', {fileid: $row.data('id'), mode: mode})
                        .done(function(res) {
                            if (!res.success) {
                                failures++;
                                if (failures < 4) {
                                    setTimeout(tick, POLL_INTERVAL * 2);
                                    return;
                                }
                                $row.data('polling', false);
                                showError($row, res.data && res.data.message);
                                return;
                            }
                            failures = 0;
                            if (res.data.state === 'processing') {
                                setTimeout(tick, POLL_INTERVAL);
                                return;
                            }
                            $row.data('polling', false);
                            var score = res.data.summary ? res.data.summary.score : undefined;
                            var after = res.data.summary_after ? res.data.summary_after.score : undefined;
                            if (res.data.report_url) {
                                $row.attr('data-report-url', res.data.report_url);
                            }
                            if (res.data.report_url_after) {
                                $row.attr('data-report-url-after', res.data.report_url_after);
                            }
                            updateFileMeta($row, res.data);
                            // Landing on 'remediated' straight from a poll
                            // means PDF auto-replace (backup kept) — except
                            // office rows, whose auto-save never touches the
                            // original (no backup).
                            var polledBackup = (res.data.state === 'remediated' &&
                                ($row.attr('data-doctype') || 'pdf') === 'pdf') ? true : undefined;
                            setState($row, res.data.state, score, polledBackup, after);
                        })
                        .fail(function(err) {
                            failures++;
                            if (failures < 4) {
                                setTimeout(tick, POLL_INTERVAL * 2);
                                return;
                            }
                            $row.data('polling', false);
                            showError($row, (err && err.message) ? err.message : i18n.error);
                        });
                }
                setTimeout(tick, POLL_INTERVAL);
            }

            /* -------------------- Report modal -------------------- */

            function openModal(html) {
                $('#eqw-modal .eqw-modal-content').html(html);
                $('#eqw-modal').prop('hidden', false);
            }

            function summaryLine(label, report) {
                var issues = report.totalIssues || {};
                // An after-score of null is merely unknown; coercing it to 0
                // would paint a red 0/100 badge on a document nobody scored.
                var score = noScore(report.remediatedScore) ? report.checkerScore : report.remediatedScore;
                var badge = noScore(score) ? '<span class="eqw-empty">—</span>' : scoreBadge(score);
                var passed = Number(issues.passed) || 0;
                var failed = Number(issues.failed) || 0;
                var warn = Number(issues.warning) || 0;
                return '<p class="eqw-report-summary"><span class="eqw-ba-label">' + label + '</span> ' + badge +
                    ' <span class="eqw-badge eqw-ok">' + i18n.passed + ': ' + passed + '</span>' +
                    ' <span class="eqw-badge eqw-bad">' + i18n.failed + ': ' + failed + '</span>' +
                    ' <span class="eqw-badge eqw-warn">' + i18n.warnings + ': ' + warn + '</span></p>';
            }

            function issuesTable(report) {
                var rows = (report.checkerReport || []).filter(function(r) {
                    return r.error > 0 || r.warning > 0;
                });
                if (!rows.length) {
                    return '<p>' + i18n.noIssues + '</p>';
                }
                var html = '<table class="eqw-report-table"><thead><tr>' +
                    '<th scope="col">' + i18n.reportColStatus + '</th>' +
                    '<th scope="col">' + i18n.reportColIssue + '</th></tr></thead><tbody>';
                rows.sort(function(a, b) {
                    return ((Number(b.error) || 0) - (Number(a.error) || 0)) ||
                        ((Number(b.severity) || 0) - (Number(a.severity) || 0));
                });
                rows.forEach(function(r) {
                    var errCount = Number(r.error) || 0;
                    var badge = errCount > 0
                        ? '<span class="eqw-badge eqw-bad">' + i18n.failed + (errCount > 1 ? ' ×' + errCount : '') + '</span>'
                        : '<span class="eqw-badge eqw-warn">' + i18n.warnings + '</span>';
                    html += '<tr><td class="eqw-report-badge">' + badge + '</td><td><strong>' +
                        $('<i>').text(r.name).html() + '</strong>' +
                        (r.pdf_ua_ref ? ' <code>' + $('<i>').text(r.pdf_ua_ref).html() + '</code>' : '') +
                        '<br><small>' + $('<i>').text(r.explanation || r.description || '').html() + '</small></td></tr>';
                });
                return html + '</tbody></table>';
            }

            function reportHtml(before, after) {
                var html = '<h2>' + i18n.reportTitle + '</h2>';
                if (before) {
                    html += summaryLine(i18n.before, before);
                }
                if (after) {
                    html += summaryLine(i18n.after, after);
                    html += issuesTable(after);
                } else if (before) {
                    html += issuesTable(before);
                }
                return html;
            }

            // The three-score strip + "why this rank" for the report modal. Usage
            // is honest about platform reality: Moodle has no per-file view
            // counter, so it shows "not tracked" instead of a fake number.
            function clsStrip($tr) {
                if (free) {
                    // Free edition has no classification: the report modal
                    // shows the scores only (no rank strip, no "why").
                    return '';
                }
                var prio = $tr.attr('data-prio') || '';
                var rules = $tr.attr('data-cls-rules') || '';
                var acc = $tr.attr('data-score-after') || $tr.attr('data-score') || '';
                var catLabel = (($tr.find('.eqw-cat-cell .eqw-tagchip').clone()
                    .find('[aria-hidden]').remove().end().text()) || '').trim();
                var prioColor = prio !== '' && prio >= 85 ? '#a12727' : (prio >= 60 ? '#9a4b00' : '#787c82');
                return '<div class="eqw-scores3">' +
                    '<div class="eqw-sc3"><small>' + i18n.clsAccessibility + '</small><b>' +
                        (acc === '' ? '—' : acc + '/100') + '</b><span>' + i18n.clsMeasured + '</span></div>' +
                    '<div class="eqw-sc3"><small>' + i18n.clsLegal + '</small><b style="color:' + prioColor + '">' +
                        (prio === '' ? '—' : prio + '/100') + '</b><span>' +
                        $('<i>').text(catLabel).html() + ' — ' + i18n.clsLegalSub + '</span></div>' +
                    '<div class="eqw-sc3"><small>' + i18n.clsUsage + '</small><b>—</b><span>' + i18n.clsUsageNA + '</span></div>' +
                '</div>' +
                (rules ? '<div class="eqw-whyrank"><b>' + i18n.clsWhy + '</b><p>' +
                    $('<i>').text(rules).html() + '</p></div>' : '');
            }

            /* -------------------- Serial check lock -------------------- */

            // SERIAL CHECK LOCK: one
            // MANUAL check at a time. While a check is submitting or running, every
            // OTHER row's check button is disabled with a tooltip. The BULK check
            // button is exempt (deliberate multi-send). Two signals cover the whole
            // window: rows the server already shows as 'checking', and rows whose
            // check submission is still in flight (closes the click-race gap before
            // the row state catches up).
            var checkSubmitting = {};
            function anyCheckActive() {
                return $('.eqw-files tr[data-state="checking"]').length > 0 ||
                    Object.keys(checkSubmitting).length > 0;
            }
            function applyCheckLock() {
                var $btns = $('.eqw-files button[data-action="check"]');
                if (anyCheckActive()) {
                    // Only mark buttons WE lock (data-checklock), so unlocking never
                    // re-enables a button disabled for another reason.
                    $btns.not('[data-checklock]').attr('data-checklock', '1')
                        .prop('disabled', true).attr('title', i18n.checkLockTitle);
                } else {
                    $btns.filter('[data-checklock]').removeAttr('data-checklock')
                        .prop('disabled', false).removeAttr('title');
                }
            }

            /* -------------------- Actions -------------------- */

            function startProcess($row, action) {
                var state = $row.data('state');
                // Never restart work in progress, never clobber a finished
                // remediation waiting for the user's apply decision, and never
                // re-process an already-remediated file. Matches the server guards.
                if (state === 'checking' || state === 'remediating' ||
                    state === 'remediated_ready' || state === 'remediated') {
                    return;
                }
                // Serial check lock: record this manual check as in-flight so a
                // second one is blocked (and every other row's check button
                // disabled) until it settles.
                var rowId = String($row.data('id'));
                if (action === 'check') {
                    checkSubmitting[rowId] = true;
                    applyCheckLock();
                }
                $row.find('.eqw-actions-cell button').prop('disabled', true);
                ajax(action, {fileid: $row.data('id')})
                    .done(function(res) {
                        if (action === 'check') {
                            delete checkSubmitting[rowId];
                        }
                        if (!res.success) {
                            renderRow($row); // Re-enable buttons first (renderRow re-applies the lock)…
                            showError($row, res.data && res.data.message); // …then keep the error visible.
                            return;
                        }
                        setState($row, res.data.state);
                    })
                    .fail(function(err) {
                        if (action === 'check') {
                            delete checkSubmitting[rowId];
                        }
                        renderRow($row);
                        showError($row, (err && err.message) ? err.message : i18n.error);
                    });
            }

            function openNeedKeyModal() {
                if (free) {
                    // Free edition renders no remediation entry points, so this
                    // modal is unreachable — hard guard regardless (signupUrl /
                    // settingsUrl are empty in free; the upsell has no target).
                    return;
                }
                if (!cfg.settingsUrl) {
                    // No Settings access (course page) — the key is site-wide,
                    // so point the user at a site administrator instead.
                    openModal('<h2>' + i18n.needKeyTitle + '</h2><p>' + i18n.needKeyBodyNoAdmin + '</p>');
                    return;
                }
                var html = '<h2>' + i18n.needKeyTitle + '</h2><p>' + i18n.needKeyBody + '</p>' +
                    '<p class="eqw-report-links">' +
                    '<a class="button button-primary" href="' + cfg.signupUrl + '" target="_blank" rel="noopener">' + i18n.buyCredits + EXT_SR + ICONS.external + '</a>' +
                    ' <a class="button" href="' + cfg.settingsUrl + '">' + i18n.openSettings + '</a>';
                openModal(html + '</p>');
            }

            /* -------------------- Search / filter / sort / pagination -------------------- */

            var PER_PAGE = 10;
            var listPage = 1;
            // Document-type pill filter ('' = All) — combined with search + status.
            var typeFilter = '';

            function rowMatches($tr, query, filter) {
                var state = String($tr.attr('data-state') || 'none');
                if (typeFilter && String($tr.attr('data-doctype') || 'pdf') !== typeFilter) {
                    return false;
                }
                if (query && String($tr.attr('data-search') || '').indexOf(query) === -1) {
                    return false;
                }
                // Classification filter (the category panel routes through this too).
                if (filter && filter.indexOf('cls_') === 0) {
                    return String($tr.attr('data-cat') || '') === filter.slice(4);
                }
                switch (filter) {
                    case 'unchecked': return state === 'none';
                    case 'checked': return state === 'checked';
                    case 'in_progress': return state === 'checking' || state === 'remediating';
                    case 'ready': return state === 'remediated_ready';
                    case 'remediated': return state === 'remediated';
                    case 'not_remediated': return state !== 'remediated';
                    case 'replaced': return String($tr.data('has-backup')) === '1';
                    case 'not_replaced': return String($tr.data('has-backup')) !== '1';
                }
                return true;
            }

            function applyListView() {
                var $tbody = $('.eqw-files tbody');
                if (!$tbody.length) {
                    return;
                }
                var query = ($('#eqw-q').val() || '').toLowerCase();
                var filter = $('#eqw-filter').val() || '';
                var sort = $('#eqw-sort').val() || 'newest';
                var $all = $tbody.find('tr');

                var visible = $all.get().filter(function(tr) {
                    return rowMatches($(tr), query, filter);
                });

                if (sort === 'prio_desc') {
                    visible.sort(function(a, b) {
                        return (parseInt($(b).attr('data-prio'), 10) || 0) -
                            (parseInt($(a).attr('data-prio'), 10) || 0);
                    });
                    visible.forEach(function(tr) {
                        $tbody.append(tr);
                    });
                } else if (sort === 'score_asc' || sort === 'score_desc') {
                    visible.sort(function(a, b) {
                        var sa = $(a).attr('data-score'), sb = $(b).attr('data-score');
                        var na = sa === '' || sa === undefined, nb = sb === '' || sb === undefined;
                        if (na || nb) {
                            return (na ? 1 : 0) - (nb ? 1 : 0);
                        }
                        return sort === 'score_asc' ? sa - sb : sb - sa;
                    });
                    visible.forEach(function(tr) {
                        $tbody.append(tr);
                    });
                } else {
                    visible.sort(function(a, b) {
                        return $(a).data('order') - $(b).data('order');
                    });
                    visible.forEach(function(tr) {
                        $tbody.append(tr);
                    });
                }

                var pages = Math.max(1, Math.ceil(visible.length / PER_PAGE));
                if (listPage > pages) {
                    listPage = pages;
                }
                $all.hide();
                visible.slice((listPage - 1) * PER_PAGE, listPage * PER_PAGE).forEach(function(tr) {
                    $(tr).show();
                });

                $('#eqw-no-match').prop('hidden', visible.length > 0);

                var $pager = $('#eqw-pager').empty();
                if (pages > 1) {
                    $pager.append($('<span class="eqw-pager-count"/>').text(visible.length + ' ' + i18n.items));
                    var mk = function(label, page, disabled, current) {
                        return $('<button type="button" class="eqw-pbtn"/>')
                            .text(label).prop('disabled', !!disabled)
                            .toggleClass('eqw-pbtn-current', !!current).attr('data-page', page);
                    };
                    $pager.append(mk('‹', listPage - 1, listPage === 1));
                    for (var i = 1; i <= pages; i++) {
                        $pager.append(mk(i, i, false, i === listPage));
                    }
                    $pager.append(mk('›', listPage + 1, listPage === pages));
                }
            }

            /* -------------------- Wire-up -------------------- */

            if (!$('.eqw-wrap').length) {
                return;
            }

            function applyTheme(theme) {
                var dark = theme === 'dark';
                $('.eqw-wrap, #eqw-modal').toggleClass('eqw-dark', dark);
                $('#eqw-theme-toggle').attr('aria-pressed', dark ? 'true' : 'false');
            }
            try {
                applyTheme(window.localStorage.getItem('eqwPdfTheme'));
            } catch (e) {
                applyTheme(null);
            }
            $('#eqw-theme-toggle').on('click', function() {
                var next = $('.eqw-wrap').hasClass('eqw-dark') ? 'light' : 'dark';
                applyTheme(next);
                try {
                    window.localStorage.setItem('eqwPdfTheme', next);
                } catch (e) {
                    return;
                }
            });

            // Behavioural selectors are scoped to .eqw-files (THE documents
            // table) — decorative tables share eqw-table for styling only and
            // must never be touched by renderRow/selection logic.
            $('.eqw-files tbody tr').each(function(idx) {
                $(this).data('order', idx);
                renderRow($(this));
            });
            applyListView();

            function selectedRows() {
                return $('.eqw-files tbody tr').filter(function() {
                    return $(this).find('.eqw-row-cb').prop('checked');
                });
            }
            function refreshBulkState() {
                var count = selectedRows().length;
                $('#eqw-selected-count').text(count ? count + ' ' + i18n.selected : '');
                $('#eqw-bulk-check, #eqw-bulk-remediate').prop('disabled', !count);
                $('#eqw-bulk-tag, #eqw-bulk-ignore').prop('disabled', !count);
            }

            function setBulkNotice(msg) {
                $('#eqw-bulk-notice').text(msg || '').prop('hidden', !msg);
            }

            function clearSelection() {
                $('#eqw-check-all').prop('checked', false);
                $('.eqw-table .eqw-row-cb').prop('checked', false);
                refreshBulkState();
                // A notice about a selection that is gone would be a lie.
                setBulkNotice('');
            }

            $('#eqw-q').on('input', function() {
                listPage = 1; clearSelection(); applyListView();
            });
            $('.eqw-typepills').on('click', '.eqw-pill', function() {
                var $pill = $(this);
                typeFilter = $pill.attr('data-doctype') || '';
                $('.eqw-typepills .eqw-pill').removeClass('eqw-pill-active').attr('aria-pressed', 'false');
                $pill.addClass('eqw-pill-active').attr('aria-pressed', 'true');
                listPage = 1;
                clearSelection();
                applyListView();
            });
            $('#eqw-filter').on('change', function() {
                listPage = 1; clearSelection(); applyListView(); syncCatPanel();
            });
            $('#eqw-sort').on('change', function() {
                listPage = 1; clearSelection(); applyListView();
            });
            $('#eqw-pager').on('click', '.eqw-pbtn', function() {
                listPage = parseInt($(this).attr('data-page'), 10);
                clearSelection(); applyListView();
            });

            $('#eqw-check-all').on('change', function() {
                var checked = this.checked;
                $('.eqw-table .eqw-row-cb').prop('checked', false);
                if (checked) {
                    $('.eqw-files tbody tr:visible .eqw-row-cb').prop('checked', true);
                }
                refreshBulkState();
            });
            $('.eqw-files').on('change', '.eqw-row-cb', refreshBulkState);

            // A row may be sent only from a state the server would accept: a check
            // needs a never-checked row, a remediation also takes an already-checked
            // one. Work in progress, a remediation waiting for its apply decision and
            // an already-remediated file are all refused. Matches the server guards.
            function bulkEligible($row, action) {
                var state = String($row.attr('data-state') || 'none');
                if (action === 'check') {
                    return state === 'none';
                }
                return state === 'none' || state === 'checked';
            }

            function runBulk(action) {
                if (free) {
                    // Free edition: PHP renders no bulk bar and no checkboxes,
                    // so nothing can invoke this — no-op guard regardless.
                    return;
                }
                if (action === 'remediate' && !cfg.hasApiKey) {
                    openNeedKeyModal();
                    return;
                }
                var $rows = selectedRows();
                // The checker is PDF-only, so office rows are not eligible for a
                // bulk check. They are counted, not dropped silently: a vanishing
                // row looks like a broken button.
                var skippedOffice = 0;
                if (action === 'check') {
                    var $pdfs = $rows.filter(function() {
                        return ($(this).attr('data-doctype') || 'pdf') === 'pdf';
                    });
                    skippedOffice = $rows.length - $pdfs.length;
                    $rows = $pdfs;
                }
                // Drop already-processed rows BEFORE sending. The server refuses
                // them anyway, but only after painting an error badge on a row
                // whose only sin is being finished already.
                var $ready = $rows.filter(function() {
                    return bulkEligible($(this), action);
                });
                var skippedDone = $rows.length - $ready.length;
                $rows = $ready;

                var parts = [];
                if ($rows.length) {
                    parts.push($rows.length + ' ' + ($rows.length === 1 ? i18n.bulkSentOne : i18n.bulkSentMany));
                }
                if (skippedDone) {
                    parts.push(skippedDone + ' ' +
                        (action === 'check' ? i18n.bulkSkippedChecked : i18n.bulkSkippedProcessed));
                }
                if (skippedOffice) {
                    parts.push(skippedOffice + ' ' + i18n.bulkSkippedOffice);
                }
                if (!$rows.length) {
                    clearSelection();
                    setBulkNotice(parts.length ?
                        i18n.bulkNothingSent + ' ' + parts.join(' ') : i18n.bulkNothingToSend);
                    return;
                }
                // A bulk remediation converts office documents to PDFs exactly
                // as the single-row action does, and spends the same credits, so
                // it asks the same question once rather than not at all.
                if (action === 'remediate') {
                    var hasOffice = $rows.get().some(function(tr) {
                        return ($(tr).attr('data-doctype') || 'pdf') !== 'pdf';
                    });
                    if (hasOffice && !window.confirm(i18n.officeConfirm)) {
                        return;
                    }
                }
                $rows.each(function(idx) {
                    var $row = $(this);
                    setTimeout(function() {
                        startProcess($row, action);
                    }, idx * 800);
                });
                clearSelection();
                // clearSelection() resets the notice, so the outcome is set after it.
                setBulkNotice(parts.join(' '));
            }
            $('#eqw-bulk-check').on('click', function() {
                runBulk('check');
            });
            $('#eqw-bulk-remediate').on('click', function() {
                runBulk('remediate');
            });

            $('.eqw-files').on('click', 'button[data-action]', function() {
                var $btn = $(this);
                var $row = $btn.closest('tr');
                var id = $row.data('id');
                var action = $btn.data('action');

                if (action === 'remediate' && !cfg.hasApiKey) {
                    openNeedKeyModal();
                    return;
                }
                // Office documents are converted to a PDF by the remediation —
                // make that explicit before spending credits. PDF rows keep
                // the existing no-confirm flow.
                if (action === 'remediate' && ($row.attr('data-doctype') || 'pdf') !== 'pdf' &&
                        !window.confirm(i18n.officeConfirm)) {
                    return;
                }
                if (action === 'check' || action === 'remediate') {
                    // Serial check lock: block a manual check that races in before
                    // the other rows' buttons were disabled. This row's own running
                    // check is caught by startProcess's state guard; bulk sends
                    // bypass this handler entirely.
                    if (action === 'check' && anyCheckActive() && $row.data('state') !== 'checking') {
                        return;
                    }
                    startProcess($row, action);
                    return;
                }
                if (action === 'apply-replace' || action === 'apply-new') {
                    if (action === 'apply-replace' && !window.confirm(i18n.confirmReplace)) {
                        return;
                    }
                    $row.find('.eqw-actions-cell button').prop('disabled', true);
                    ajax('apply', {fileid: id, how: action === 'apply-replace' ? 'replace' : 'new'})
                        .done(function(res) {
                            if (!res.success) {
                                renderRow($row);
                                showError($row, res.data && res.data.message);
                                return;
                            }
                            updateFileMeta($row, res.data);
                            setState($row, res.data.state, undefined, action === 'apply-replace');
                            if (res.data.message) {
                                openModal('<p>' + $('<i>').text(res.data.message).html() + '</p>');
                            }
                        })
                        .fail(function(err) {
                            renderRow($row);
                            showError($row, (err && err.message) ? err.message : i18n.error);
                        });
                    return;
                }
                if (action === 'download') {
                    window.location = cfg.downloadBase + '?fileid=' + id + '&sesskey=' + encodeURIComponent(cfg.sesskey);
                    return;
                }
                if (action === 'restore') {
                    if (!window.confirm(i18n.confirmRestore)) {
                        return;
                    }
                    ajax('restore', {fileid: id})
                        .done(function(res) {
                            if (res.success) {
                                updateFileMeta($row, res.data);
                                $row.attr('data-score-after', '').data('score-after', '');
                                $row.attr('data-report-url-after', '');
                                // The server dropped the accessible-copy pointer
                                // too. Without this the row keeps offering "Open
                                // accessible copy" for the discarded run, and a
                                // later remediate-then-replace shows the OLD copy.
                                $row.attr('data-new-url', '');
                                setState($row, res.data.state, undefined, false);
                            } else {
                                showError($row, res.data && res.data.message);
                            }
                        })
                        .fail(function(err) {
                            showError($row, (err && err.message) ? err.message : i18n.error);
                        });
                    return;
                }
                if (action === 'report') {
                    ajax('report', {fileid: id})
                        .done(function(res) {
                            if (res.success) {
                                var links = '';
                                var u = $row.attr('data-report-url');
                                var ua = $row.attr('data-report-url-after');
                                if (u) {
                                    links += '<a class="button eqw-viewbtn" href="' + u + '" target="_blank" rel="noopener">' +
                                        i18n.viewReport + ' (' + i18n.before + ')' + EXT_SR + ICONS.external + '</a> ';
                                }
                                if (ua) {
                                    links += '<a class="button eqw-viewbtn" href="' + ua + '" target="_blank" rel="noopener">' +
                                        i18n.viewReport + ' (' + i18n.after + ')' + EXT_SR + ICONS.external + '</a>';
                                }
                                openModal(reportHtml(res.data.report, res.data.report_after) +
                                    clsStrip($row) +
                                    (links ? '<p class="eqw-report-links">' + links + '</p>' : ''));
                            } else {
                                openModal('<p>' + $('<i>').text(res.data && res.data.message || i18n.error).html() + '</p>');
                            }
                        })
                        .fail(function(err) {
                            openModal('<p>' + $('<i>').text((err && err.message) ? err.message : i18n.error).html() + '</p>');
                        });
                }
            });

            $('#eqw-modal').on('click', function(e) {
                if (e.target === this || $(e.target).is('.eqw-modal-close')) {
                    $(this).prop('hidden', true);
                }
            });
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    $('#eqw-modal').prop('hidden', true);
                }
            });

            /* -------------------- Classification (triage) -------------------- */

            // Keep the category panel's pressed/dimmed state in lockstep with the
            // filter, wherever it was changed from (panel click OR the select).
            function syncCatPanel() {
                var val = $('#eqw-filter').val() || '';
                var filtered = val.indexOf('cls_') === 0;
                $('.eqw-catpanel .eqw-catrow').each(function() {
                    var on = val === 'cls_' + $(this).attr('data-cat');
                    $(this).toggleClass('eqw-catrow-active', on).attr('aria-pressed', on ? 'true' : 'false')
                        .toggleClass('eqw-catrow-dim', filtered && !on);
                });
            }
            syncCatPanel();

            // Everything below exists only in the FULL edition — the free
            // checker has no classification (PHP renders none of these
            // elements, and the handlers must not be wired at all).
            if (!free) {
                $('.eqw-catpanel').on('click', '.eqw-catrow', function() {
                    var val = 'cls_' + $(this).attr('data-cat');
                    var $f = $('#eqw-filter');
                    $f.val($f.val() === val ? '' : val).trigger('change');
                });

                // Outcome messages must survive the reload that follows every
                // classification action — otherwise a successful action looks like
                // nothing happened. sessionStorage carries the message across.
                function reloadWithNotice(msg) {
                    try {
                        window.sessionStorage.setItem('eqwClsNotice', msg);
                    } catch (e) {
                        // Private mode — reload without the notice.
                        return window.location.reload();
                    }
                    window.location.reload();
                }
                try {
                    var pending = window.sessionStorage.getItem('eqwClsNotice');
                    if (pending) {
                        window.sessionStorage.removeItem('eqwClsNotice');
                        var $notice = $('#eqw-bulk-notice');
                        if (!$notice.length) {
                            $notice = $('<div class="eqw-bulk-notice notice notice-info" role="status"></div>')
                                .prependTo($('.eqw-cls').length ? '.eqw-cls' : '.eqw-wrap');
                        }
                        $notice.text(pending).prop('hidden', false);
                    }
                } catch (e) {
                    // sessionStorage unavailable — nothing to restore.
                }

                $('.eqw-catpanel').on('click', '#eqw-cls-unignore', function(e) {
                    e.stopPropagation();
                    var $btn = $(this).prop('disabled', true);
                    ajax('cls_unignore', {}).done(function(res) {
                        if (res.success) {
                            reloadWithNotice((res.data.count || 0) + ' ' + i18n.clsRestored);
                        } else {
                            $btn.prop('disabled', false);
                            window.alert(res.data && res.data.message || i18n.error);
                        }
                    }).fail(function(err) {
                        $btn.prop('disabled', false);
                        window.alert((err && err.message) ? err.message : i18n.error);
                    });
                });

                function selectedFids() {
                    return selectedRows().get().map(function(tr) {
                        return $(tr).data('id');
                    });
                }

                // The change-classification modal serves both the bulk button and a
                // single row's category chip. "Back to automatic" clears the per-file
                // tag so the location tag / automatic rules apply again.
                function openClsChoiceModal(fids) {
                    if (!fids.length) {
                        return;
                    }
                    // Category buttons are built from the panel rows — one source of
                    // truth for labels and colors (PHP renders both).
                    var buttons = '';
                    $('.eqw-catpanel .eqw-catrow').each(function() {
                        var cat = $(this).attr('data-cat');
                        // String.prototype.trim, NOT $.trim — jQuery 4 removed it.
                        var label = ($(this).find('.eqw-catname').text() || '').trim();
                        var sw = $(this).find('.eqw-catsw').attr('style') || '';
                        buttons += '<button type="button" class="button eqw-cls-choice" data-cat="' + cat + '">' +
                            '<span class="eqw-catsw" style="' + sw.replace(/"/g, '&quot;') + '"></span> ' +
                            $('<i>').text(label).html() + '</button>';
                    });
                    buttons += '<button type="button" class="button eqw-cls-choice" data-cat="">🤖 ' + i18n.clsAuto + '</button>';
                    openModal('<h2>' + i18n.clsTitle + '</h2><p>' + i18n.clsBody + '</p>' +
                        '<div class="eqw-cls-choices">' + buttons + '</div>');
                    $('#eqw-modal .eqw-cls-choice').on('click', function() {
                        var cat = $(this).attr('data-cat');
                        $('#eqw-modal .eqw-cls-choice').prop('disabled', true);
                        var call = cat ?
                            ajax('cls_bulk_tag', {fids: fids, cat: cat}) :
                            ajax('cls_bulk_untag', {fids: fids});
                        call.done(function(res) {
                            if (res.success) {
                                reloadWithNotice(fids.length + ' ' + (cat ? i18n.clsTagged : i18n.clsAutoDone));
                            } else {
                                $('#eqw-modal .eqw-cls-choice').prop('disabled', false);
                                window.alert(res.data && res.data.message || i18n.error);
                            }
                        }).fail(function(err) {
                            $('#eqw-modal .eqw-cls-choice').prop('disabled', false);
                            window.alert((err && err.message) ? err.message : i18n.error);
                        });
                    });
                }

                $('#eqw-bulk-tag').on('click', function() {
                    openClsChoiceModal(selectedFids());
                });

                // A row's category chip IS the control: managers click it to
                // reclassify that one file directly (the bulk path stays for many).
                if (can.manage) {
                    $('.eqw-files').on('click', '.eqw-tagchip', function() {
                        openClsChoiceModal([$(this).closest('tr').data('id')]);
                    });
                    $('.eqw-files .eqw-tagchip').addClass('eqw-tagchip-btn')
                        .attr('role', 'button').attr('tabindex', '0')
                        .attr('title', function(i, t) {
                            return (t ? t + ' — ' : '') + i18n.clsChipHint;
                        });
                }

                $('#eqw-bulk-ignore').on('click', function() {
                    var fids = selectedFids();
                    if (!fids.length || !window.confirm(i18n.clsIgnoreConfirm)) {
                        return;
                    }
                    ajax('cls_ignore', {fids: fids}).done(function(res) {
                        if (res.success) {
                            reloadWithNotice(fids.length + ' ' + i18n.clsIgnored);
                        } else {
                            window.alert(res.data && res.data.message || i18n.error);
                        }
                    }).fail(function(err) {
                        window.alert((err && err.message) ? err.message : i18n.error);
                    });
                });

                /* Classification screen (locations) */

                function clsLocationCall(actionName, $rowEl, extra) {
                    var data = $.extend({prefix: $rowEl.attr('data-prefix')}, extra || {});
                    $rowEl.find('button, select').prop('disabled', true);
                    ajax(actionName, data).done(function(res) {
                        if (res.success) {
                            reloadWithNotice(actionName === 'cls_clear_location' ? i18n.clsLocCleared : i18n.clsLocTagged);
                        } else {
                            $rowEl.find('button, select').prop('disabled', false);
                            window.alert(res.data && res.data.message || i18n.error);
                        }
                    }).fail(function(err) {
                        $rowEl.find('button, select').prop('disabled', false);
                        window.alert((err && err.message) ? err.message : i18n.error);
                    });
                }
                $('.eqw-cls-list').on('change', '.eqw-cls-select', function() {
                    var cat = $(this).val();
                    if (cat) {
                        clsLocationCall('cls_tag_location', $(this).closest('.eqw-clsrow'), {cat: cat});
                    }
                });
                $('.eqw-cls-list').on('click', '.eqw-cls-accept', function() {
                    clsLocationCall('cls_tag_location', $(this).closest('.eqw-clsrow'), {cat: $(this).attr('data-cat')});
                });
                $('.eqw-cls-list').on('click', '.eqw-cls-clear', function() {
                    clsLocationCall('cls_clear_location', $(this).closest('.eqw-clsrow'));
                });
                // Auto-archive age threshold (Classification screen).
                $('#eqw-cls-archive-years').on('change', function() {
                    var $sel = $(this).prop('disabled', true);
                    ajax('cls_set_archive_years', {years: parseInt($sel.val(), 10) || 0}).done(function(res) {
                        if (res.success) {
                            reloadWithNotice(i18n.clsYearsSaved);
                        } else {
                            $sel.prop('disabled', false);
                            window.alert(res.data && res.data.message || i18n.error);
                        }
                    }).fail(function(err) {
                        $sel.prop('disabled', false);
                        window.alert((err && err.message) ? err.message : i18n.error);
                    });
                });
            }

            /* -------------------- Settings tab -------------------- */

            // The day count only means anything while removal is enabled.
            $('#eqw-ad-autoremove-enabled').on('change', function() {
                $('#eqw-ad-autoremove').prop('disabled', !$(this).is(':checked'));
            });
            $('#eqw-save-settings').on('click', function() {
                var $msg = $('#eqw-settings-msg').text('…');
                ajax('save_settings', {
                    apikey: $('#eqw-api-key').val(),
                    auditdefaults: JSON.stringify({
                        add_signature: $('#eqw-ad-signature').is(':checked'),
                        fix_contrast: $('#eqw-ad-contrast').is(':checked'),
                        delete_after_audit: $('#eqw-ad-autoremove-enabled').is(':checked'),
                        auto_remove: parseInt($('#eqw-ad-autoremove').val(), 10) || 30,
                        auto_replace: $('#eqw-ad-autoreplace').is(':checked')
                    })
                }).done(function(res) {
                    $msg.text(res.data && res.data.message || '').toggleClass('eqw-msg-error', !res.success);
                }).fail(function(err) {
                    $msg.text((err && err.message) ? err.message : i18n.error).addClass('eqw-msg-error');
                });
            });
            $('#eqw-remove-key').on('click', function() {
                if (!window.confirm(i18n.removeKeyConfirm)) {
                    return;
                }
                ajax('save_settings', {apikey: ''}).always(function() {
                    window.location.reload();
                });
            });
            $('#eqw-register').on('click', function() {
                var $btn = $(this).prop('disabled', true);
                ajax('register_site', {}).done(function(res) {
                    if (res.success) {
                        window.location.reload();
                    } else {
                        $btn.prop('disabled', false);
                        window.alert(res.data && res.data.message || i18n.error);
                    }
                }).fail(function() {
                    $btn.prop('disabled', false);
                    window.alert(i18n.error);
                });
            });
            if ($('#eqw-balance').length) {
                ajax('get_balance', {}).done(function(res) {
                    $('#eqw-balance').text(res.success ? res.data.balance : (res.data && res.data.message || '—'));
                }).fail(function() {
                    $('#eqw-balance').text('—');
                });
            }
        }
    };
});
