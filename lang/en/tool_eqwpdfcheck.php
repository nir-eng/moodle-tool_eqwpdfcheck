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
 * Strings for tool_eqwpdfcheck. English only (matches the product UI rule).
 *
 * @package    tool_eqwpdfcheck
 * @copyright  2026 EqualWeb
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'EqualWeb PDF Checker';
$string['eqwpdfcheck:manage'] = 'Manage EqualWeb PDF accessibility checks';
$string['eqwpdfcheck:view'] = 'View PDF accessibility status and reports in a course';
$string['eqwpdfcheck:check'] = 'Run free PDF accessibility checks (uses the site\'s shared daily quota)';
$string['privacy:metadata'] = 'The EqualWeb PDF Checker sends documents to the EqualWeb accessibility service for checking, and records which user started each check.';
$string['privacy:metadata:equalweb:document'] = 'The document file uploaded for accessibility checking.';
$string['privacy:metadata:equalweb:filename'] = 'The file name of the document, sent to the service with its content.';
$string['privacy:metadata:tool_eqwpdfcheck_state'] = 'Per-document accessibility state, including which users started checks.';
$string['privacy:metadata:tool_eqwpdfcheck_state:userid'] = 'The user who uploaded the document (taken from the Moodle file record).';
$string['privacy:metadata:tool_eqwpdfcheck_state:checkedby'] = 'The user who started the most recent free accessibility check.';
$string['privacy:metadata:tool_eqwpdfcheck_state:remediatedby'] = 'The user who last acted on the file.';
$string['privacy:metadata:tool_eqwpdfcheck_state:ignored_by'] = 'The name of the administrator who hid (ignored) the document from the inventory.';


// Course page + permissions.
$string['coursenav'] = 'PDF Checker';
$string['coursepagetitle'] = 'PDF Checker';
$string['quotanote'] = 'Free checks share a site-wide daily quota.';
$string['dupnote'] = 'Same content as {$a} other file(s); each copy is checked separately.';

// Task.
$string['task_discover'] = 'Discover PDF files for accessibility';

// Page chrome / tabs.
$string['tab_files'] = 'Documents';
$string['tab_analytics'] = 'Analytics';
$string['tab_ai'] = 'PDF AI Remediation';
$string['tab_start'] = 'Start Auto Remediation';
$string['tab_settings'] = 'Settings';
$string['toolslink'] = 'EqualWeb PDF Tools';
$string['support'] = 'Support';
$string['toggletheme'] = 'Toggle dark theme';

// PDF AI Remediation + Start Auto Remediation tab headings (the long body
// copy for these tabs now lives further down, in the server-rendered UI block).
$string['ai_heading'] = 'PDF AI Remediation';

// Errors / notices.
$string['nopdffiles'] = 'No supported documents were found on this site.';
$string['nomatch'] = 'No documents match the current search or filter.';

// JS i18n (mirrors the WP/Drupal i18n map).
$string['js_checking'] = 'Checking…';
$string['js_remediating'] = 'Remediating…';
$string['js_inprogress'] = 'In progress…';
$string['js_error'] = 'Error';
$string['js_check'] = 'PDF Checker';
$string['js_passed'] = 'Passed';
$string['js_failed'] = 'Failed';
$string['js_warnings'] = 'Warnings';
$string['js_score'] = 'Accessibility score';
$string['js_reporttitle'] = 'Accessibility report';
$string['js_noissues'] = 'No failed checks. Great!';
$string['js_before'] = 'Before';
$string['js_after'] = 'After';
$string['js_viewreport'] = 'View report';
$string['js_externallink'] = 'external link';
$string['js_reportcolstatus'] = 'Status';
$string['js_reportcolissue'] = 'Issue description';
$string['js_notchecked'] = 'Not checked';
$string['js_items'] = 'items';

// Office documents (v2.4.0) - hyphens only, no em-dashes in new text.

// Bulk outcome notice (v2.7.2). The count is prefixed by the JS exactly as it is
// for js_selected, so each string begins at the word after the number.

// Classification & triage (v2.8.0). Mirrors the Drupal reference i18n strings.
// The count-suffix notices (js_cls_tagged etc.) begin at the word after the
// number, exactly like js_selected.

// =====================================================================
// Server-rendered UI (classes/local/page_renderer.php + classification.php).
// i18n completion 2026-07. Static developer content: no s()/escaping needed
// at the call site. Strings carrying an {$a}/{$a->x} placeholder have their
// substituted data escaped by the caller (or the data is an integer).
// =====================================================================

// Page chrome: header, tabs, sub-nav, modal, version footer, update banner.
$string['logoalt'] = 'EqualWeb';
$string['nav_compliance'] = 'Compliance ▾';
$string['compliance'] = 'Compliance';
$string['nav_classification'] = 'Classification';
$string['tab_permissions'] = 'Permissions';
$string['close'] = 'Close';
$string['versionfoot'] = 'EqualWeb PDF Accessibility v{$a}';
$string['updateavailable'] = 'Update available:';
$string['updatebody'] = 'EqualWeb PDF Accessibility {$a->version} is out (you are on {$a->current}).';
$string['updatedownload'] = 'Download the update';

// Documents tab: empty states, type pills, toolbar, filters, sort, bulk bar.
$string['nopdffiles_course'] = 'No supported documents were found in this course.';
$string['allignored'] = 'All documents on this site are currently ignored. Restore them from the panel above.';
$string['allignored_course'] = 'All documents in this course are currently ignored. Restore them from the panel above.';
$string['filterbytype'] = 'Filter by document type';
$string['pill_all'] = 'All';
$string['pill_pdf'] = 'PDF';
$string['pill_word'] = 'Word';
$string['pill_ppt'] = 'PowerPoint';
$string['pill_xls'] = 'Excel';
$string['totals'] = '<strong>{$a->docs}</strong> documents · <strong>{$a->pages}</strong> pages in total';
$string['searchph'] = 'Search documents…';
$string['searcharia'] = 'Search documents';
$string['filteraria'] = 'Filter';
$string['filter_all'] = 'All files';
$string['filtergroup_status'] = 'Status';
$string['filter_unchecked'] = 'Not checked';
$string['filter_checked'] = 'Checked';
$string['filter_inprogress'] = 'In progress';
$string['filtergroup_classification'] = 'Classification';
$string['sortaria'] = 'Sort';
$string['sort_newest'] = 'Newest first';
$string['sort_score_asc'] = 'Score: low → high';
$string['sort_score_desc'] = 'Score: high → low';
$string['bulk_check'] = 'PDF Checker (selected)';
$string['bulk_remediate'] = 'Auto Remediation (selected)';
$string['bulk_tag'] = 'Change classification';
$string['bulk_ignore'] = 'Ignore';

// Documents table: column headers + row checkbox labels.
$string['selectall'] = 'Select all files';
$string['selectfile'] = 'Select file';
$string['col_file'] = 'File';
$string['col_size'] = 'Size';
$string['col_pages'] = 'Pages';
$string['col_lang'] = 'Language';
$string['col_uploaded'] = 'Uploaded';
$string['col_status'] = 'Status';
$string['col_actions'] = 'Actions';

// Classification category labels + fix-first flags (classification::CATS).
$string['cat_service'] = 'Required for a service';
$string['cat_public'] = 'Active course material';
$string['cat_internal'] = 'Internal only';
$string['cat_archive'] = 'Archive';
$string['catflag_service'] = 'Fix first';
$string['catflag_public'] = 'Fix first';
$string['catflag_internal'] = 'In scope if used';
$string['catflag_archive'] = 'Possibly exempt — confirm';

// Category panel above the file table.

// Classification screen: explainer, coverage meter, auto-archive, footnote.

// Classification screen: one location (course) row.
$string['loc_documents'] = '{$a} document(s)';
$string['loc_ignored'] = '{$a} ignored';
$string['loc_taggedby'] = 'Tagged by {$a->by} on {$a->date} — overrides auto rules';
$string['loc_taggedby_nodate'] = 'Tagged by {$a} — overrides auto rules';
$string['loc_removetag'] = 'Remove tag';
$string['loc_untagged'] = '⚪ Untagged — automatic rules apply';
$string['loc_suggprefix'] = 'Suggestion:';
$string['loc_accept'] = '✓ Accept';
$string['loc_tagaria'] = 'Tag {$a}';
$string['loc_choosetag'] = 'Choose a tag…';

// Classification engine (classification.php): location labels, suggestions,
// and the human-readable "why this rank" rule sentences (computed per
// request for display; NOT the persisted cls_rules audit trail).
$string['cls_anadmin'] = 'an administrator';
$string['loc_sitefiles'] = 'Site files (not in a course)';
$string['loc_coursefallback'] = 'Course {$a}';
$string['sugg_service'] = '{$a} filename(s) look like forms or applications';
$string['sugg_archive'] = 'every file here already matches the archive rules';
$string['cls_rule_taggedadmin'] = 'Tagged by an administrator';
$string['cls_rule_inherited'] = 'Inherited from {$a->location} — tagged by {$a->by} on {$a->date} (overrides auto rules)';
$string['cls_rule_inherited_nodate'] = 'Inherited from {$a->location} — tagged by {$a->by} (overrides auto rules)';
$string['cls_rule_hidden'] = 'In a hidden course or module';
$string['cls_rule_notattached'] = 'Not attached to a visible activity';
$string['cls_rule_archpath'] = 'Auto rule: the file path or area looks like an archive';
$string['cls_rule_old_one'] = 'Auto rule: uploaded over {$a} year ago (threshold is configurable in Classification)';
$string['cls_rule_old_many'] = 'Auto rule: uploaded over {$a} years ago (threshold is configurable in Classification)';
$string['cls_rule_active'] = 'In a visible activity in a visible course';

// Analytics tab: KPI tiles, chart titles, axis labels, notes.

// PDF AI Remediation tab (marketing/body copy; inline HTML is intentional).
$string['badge_new'] = 'New';
$string['ai_ftok_pdf_sub'] = 'incl. scanned';
$string['ai_ftok_doc'] = 'DOC / DOCX';
$string['ai_ftok_ppt'] = 'PPT / PPTX';
$string['ai_ftok_xls'] = 'XLS / XLSX';
$string['ai_accessiblepdf'] = 'An accessible PDF';
$string['ai_pdfsub'] = 'tagged · PDF/UA conformant · ready to publish';
$string['ai_limit_size_val'] = '100 MB';
$string['ai_limit_size_lbl'] = 'max file size';
$string['ai_limit_pages_val'] = '200 pages';
$string['ai_limit_pages_lbl'] = 'max per document';
$string['ai_meetsstandards'] = 'Meets accessibility standards';
$string['ai_step1'] = 'Send';
$string['ai_step1_sub'] = 'Any supported document up to 100 MB and 200 pages.';
$string['ai_step2'] = 'AI analyzes &amp; fixes';
$string['ai_step2_sub'] = 'OCR, tagging, alt text, reading order, tables, language &amp; title.';
$string['ai_step3'] = 'You review';
$string['ai_step3_sub'] = 'A checker report lists every fix applied and anything left to verify.';
$string['ai_step4'] = 'Get a PDF back';
$string['ai_step4_sub'] = 'Always a PDF, stamped accessible - replace the original, save as new, or download.';

// Start Auto Remediation tab (marketing/body copy; inline HTML is intentional).

// Permissions guide tab (admin only). {$a...} placeholders carry escaped URLs.

// Settings tab.

// AJAX error envelopes surfaced in the UI (classes/external/cls_*.php).
$string['err_unknowncat'] = 'Unknown category.';
$string['err_unknownloc'] = 'Unknown location.';
$string['err_novaliddocs'] = 'No valid documents selected.';
$string['err_yearsrange'] = 'Years must be between 0 (off) and 15.';

// AJAX error envelopes surfaced in the UI (remaining external functions +
// client.php). Plain strings placed into the JSON {success:false} envelope -
// no s()/escaping. Shared across files where the English is identical.
$string['err_invaliddoc'] = 'Invalid document.';
$string['err_busy'] = 'This file is busy right now. Try again in a moment.';
$string['err_statechanged'] = 'The file state changed while uploading. Reload the page and try again.';
// apply.php
// check.php
$string['err_checkpdfonly'] = 'Checking is available for PDF files only.';
$string['err_checkstate'] = 'This file cannot be checked in its current state. Wait for the check that is running to finish, then reload the page.';
// poll.php
$string['err_checkaccountgone'] = 'The EqualWeb check service could not be reached for this run. The file was reset. Run the check again.';
$string['err_noprocess'] = 'No process in progress for this file.';
$string['err_docgone_check'] = 'The document is no longer on the EqualWeb service. The file was reset. Run the check again.';
// remediate.php
// report.php
$string['err_noreport'] = 'No report stored for this file. Run a check first.';
// client.php (returned error strings surfaced via err() in the externals)
$string['err_checkproxygone'] = 'This check is running on the shared EqualWeb check service, and the site is no longer registered with it. Reload this page to re-register, then run the check again.';
$string['err_notregistered'] = 'The site is not registered with the EqualWeb free check service yet. Registration runs automatically and needs this site to be reachable from the internet. Reload this page in a few minutes to try again.';
$string['err_testmode'] = 'EqualWeb accepted the file but did not start processing it. Try again later, or contact EqualWeb if it keeps happening.';
$string['err_docgone'] = 'Document not found on the EqualWeb service.';
// client.php upload_file (message follows the machine 'ERR:' prefix, which stays literal).
$string['err_uploadnotfound'] = 'File not found on disk.';
$string['err_uploadunreadable'] = 'The file could not be read from disk, or it is empty.';
// filemanager.php error returns surfaced via err() (static, type-checked by callers).
// NB: 'No backup found for this file.' is intentionally NOT here - restore.php
// branches on its text (strpos 'No backup'), so it must stay a literal.
$string['err_nooriginal'] = 'Original file not found.';
$string['err_fileprocessing'] = 'This file is already being processed. Please wait a moment and try again.';
$string['err_overwritefail'] = 'Could not overwrite the original file.';

// AJAX success messages (user-facing toasts) placed into the JSON {success:true}
// envelope - plain strings, no escaping.

// Checker badge + the serial check lock.
$string['checker_headertitle'] = 'EqualWeb PDF Checker';
$string['err_freeedition'] = 'Not available in EqualWeb PDF Checker.';
$string['js_checkedbadge'] = 'Checked';
$string['js_checklocktitle'] = 'A check is already running. Wait for it to finish.';
// Free-edition AI tab: informational copy about the full remediation product
// (Shopify FreeAiPage parity). NO prices, NO credits, NO purchase CTAs — one
// plain login.equalweb.com link only. Inline HTML is intentional.
$string['ai_free_badge'] = 'EqualWeb service';
$string['ai_free_whatdoes'] = '<strong>What it does:</strong> EqualWeb PDF AI Remediation automatically fixes the accessibility issues this checker finds in your documents. It reads each document the way a human editor would: it rebuilds headings and reading order, tags lists, links, references and tables, writes real alternate text for every image, and sets the document language and title. Scanned files are OCR\'d first, so even a scan of a printed page comes back as searchable, accessible text.';
$string['ai_free_result'] = 'The result is a fully tagged, PDF/UA conformant PDF that keeps the original structure, layout and visual design. Every remediated file comes back <strong>accessibility-stamped, with a detailed checker report</strong> of the fixes applied and anything that still needs manual attention.';
$string['ai_free_separate'] = 'AI Remediation is available in the full <strong>EqualWeb PDF Accessibility</strong> plugin - a separate EqualWeb product. This checker keeps scoring every document on your site either way.';
$string['ai_free_learnmore'] = 'Learn more at login.equalweb.com';
$string['ai_free_whatsend'] = 'What the full plugin accepts';
$string['ai_free_youget'] = 'Whatever goes in, an accessible PDF comes back';
$string['ai_free_convfoot'] = 'Available in the full EqualWeb PDF Accessibility plugin. Processing is secured and ISO 27001-certified, and the original file is never changed without your approval.';

// Free-check registration status (both editions): the notice above the page,
// the row messages and the system status check. Plain text, no apostrophes.
$string['reg_title_ok'] = 'Free checks are set up';
$string['reg_title_pending'] = 'Free checks are being set up';
$string['reg_title_failed'] = 'Free checks are not set up yet';
$string['reg_ok'] = 'This site is registered for free accessibility checks.';
$string['reg_pending'] = 'This site registers with the EqualWeb check service automatically. It takes up to 30 seconds.';
$string['reg_moved'] = 'This site was registered for free checks at {$a}. Its address has changed, so it has to register again.';
$string['reg_whattodo'] = 'What to do:';
$string['reg_verifyaddress'] = 'Verification address:';
$string['reg_serviceaddress'] = 'EqualWeb service address:';
$string['reg_lastattempt'] = 'Last attempt: {$a}.';
$string['reg_retry_auto'] = 'The next automatic attempt runs when an administrator opens this page after {$a}.';
$string['reg_retry_manual'] = 'There is no automatic attempt for this reason. Click Try again once it is fixed.';
$string['reg_tryagain'] = 'Try again';
$string['reg_busy'] = 'Registering this site with the EqualWeb check service. This can take up to 30 seconds.';
$string['reg_busy_other'] = 'A registration for this site is already running. Wait a minute, then reload this page.';
$string['reg_success'] = 'This site is now registered for free accessibility checks.';
$string['reg_support'] = 'Contact EqualWeb support';
$string['reg_newtab'] = 'opens in a new tab';
$string['reg_details'] = 'Technical details';
$string['reg_details_help'] = 'Include these lines when you contact EqualWeb support.';
$string['reg_row_admin'] = 'Free checks are not set up on this site yet. The notice at the top of the page explains why and what to do.';
$string['reg_row_user'] = 'Free checks are not set up on this site yet. Ask a site administrator to open the EqualWeb PDF Checker page.';
$string['reg_row_reload'] = 'Reload this page: the notice at the top of the page explains what to do.';
$string['reg_course_admin'] = 'Free checks are not set up on this site yet.';
$string['reg_course_adminlink'] = 'See why and what to do';
$string['reg_checkname'] = 'Free check registration ({$a})';
$string['reg_checkaction'] = 'Open {$a}';
$string['reg_checkpending'] = 'This site is not registered for free checks yet. It registers when an administrator opens the plugin page.';
// Why a registration attempt failed and what to do about it, one pair per answer code.
$string['reg_why_rate_limited'] = 'Too many registration attempts came from this server network today.';
$string['reg_todo_rate_limited'] = 'Wait until {$a} (00:00 UTC), then click Try again. More clicks do not help. On shared hosting, the other sites on the same server count toward the same limit.';
$string['reg_why_bad_request'] = 'The registration request reached EqualWeb incomplete.';
$string['reg_todo_bad_request'] = 'A proxy or security filter on the server network probably changed the request. Click Try again. If it happens again, contact EqualWeb support with the technical details below.';
$string['reg_why_not_https'] = 'Free checks need this site to use an https:// address, but the site address is {$a}.';
$string['reg_todo_not_https'] = 'Set wwwroot in config.php to the https:// address of the site, with a valid certificate, then click Try again.';
$string['reg_why_host_mismatch'] = 'The verification address is on a different domain or port than the site address.';
$string['reg_todo_host_mismatch'] = 'Make sure wwwroot in config.php is the public https:// address of the site, without an internal name, an IP address or a port number. A proxy or CDN that rewrites addresses can cause this. Then click Try again.';
$string['reg_why_verify_redirect'] = 'Your site redirected the EqualWeb check instead of answering it, usually to a login (single sign-on) page, a maintenance page or another address.';
$string['reg_todo_verify_redirect'] = 'Open the verification address below in a private browser window. It must answer directly with a short JSON message. Exclude this one address from single sign-on, forced login, maintenance mode, and any http-to-https, www or trailing-slash rules. Then click Try again.';
$string['reg_why_verify_auth'] = 'Your site asked the EqualWeb check to log in.';
$string['reg_todo_verify_auth'] = 'The site is password-protected (a staging site, or web server or Windows authentication). Allow anonymous access to the verification address below. It is needed only while registering. A site that is not public on the internet cannot use free checks. Then click Try again.';
$string['reg_why_verify_blocked'] = 'A firewall or security service in front of your site blocked the EqualWeb check.';
$string['reg_todo_verify_blocked'] = 'Allow anonymous HTTPS GET requests to the verification address below in your firewall, WAF, CDN or security service. The check comes from Cloudflare and has no fixed IP address, so allow it by path and by the user agent EqualWeb-SiteVerify. Then click Try again.';
$string['reg_why_verify_not_found'] = 'EqualWeb reached your domain, but the verification address does not exist there.';
$string['reg_todo_verify_not_found'] = 'Check that this plugin is installed on every web server of the site, that wwwroot includes any subdirectory, and that the domain points at this server. Open the verification address below from outside your network: it should answer with a short JSON message. Then click Try again.';
$string['reg_why_verify_nonce_missing'] = 'EqualWeb reached the plugin on your site, but the plugin did not find the one-time code it had just created.';
$string['reg_todo_verify_nonce_missing'] = 'A page cache or CDN probably answered with an old copy, two registrations overlapped, or the site runs on several servers that do not share one cache and database. Exclude the verification address below from caching, wait a minute, then click Try again.';
$string['reg_why_verify_site_error'] = 'Your site returned a server error to the EqualWeb check.';
$string['reg_todo_verify_site_error'] = 'If the site is in maintenance mode, turn it off or let the verification address below through. Otherwise check the web server and PHP error logs for this address. Then click Try again.';
$string['reg_why_verify_tls'] = 'EqualWeb could not open a secure connection to your site, because its certificate is not valid from the internet.';
$string['reg_todo_verify_tls'] = 'Install a certificate from a public certificate authority, with its full chain, for this address. An internal or self-signed certificate does not work. If the site is behind Cloudflare, check its SSL mode and origin certificate. Then click Try again.';
$string['reg_why_verify_unreachable'] = 'EqualWeb could not reach your site from the internet.';
$string['reg_todo_verify_unreachable'] = 'Free checks need the site to be publicly reachable over HTTPS. An intranet, VPN-only or private staging site cannot register. Open {$a} from a phone on mobile data. If it opens, click Try again. If that fails, contact EqualWeb support with the technical details below.';
$string['reg_why_verify_timeout'] = 'Your site did not answer the EqualWeb check within 15 seconds.';
$string['reg_todo_verify_timeout'] = 'A firewall may be dropping requests from the internet without answering, or the server could not answer a second request while it was busy with the registration itself (a server with a single PHP worker). Allow anonymous access to the verification address below, then click Try again.';
$string['reg_why_verify_not_json'] = 'Your site answered the EqualWeb check with a web page instead of the expected short JSON message.';
$string['reg_todo_verify_not_json'] = 'This is usually a login or single sign-on page, a security challenge or a maintenance page shown in place of the verification address below. Open the address in a private window and make it answer directly, then click Try again.';
$string['reg_why_verify_debug_output'] = 'Your site printed extra text, such as PHP warnings, in front of its answer to the EqualWeb check.';
$string['reg_todo_verify_debug_output'] = 'Turn off on-screen debug messages (Site administration > Development > Debugging > Display debug messages) and on-screen PHP errors, then click Try again.';
$string['reg_why_verify_nonce_mismatch'] = 'Your site answered the EqualWeb check with an outdated one-time code.';
$string['reg_todo_verify_nonce_mismatch'] = 'A page or CDN cache probably kept an earlier answer, or two registrations ran at the same time. Exclude the verification address below from caching, wait a minute, then click Try again.';
$string['reg_why_verify_http'] = 'Your site answered the EqualWeb check with an unexpected status.';
$string['reg_todo_verify_http'] = 'Open the verification address below in a private window: it should answer with a short JSON message. Then click Try again. If it happens again, contact EqualWeb support with the technical details below.';
$string['reg_why_service_error'] = 'The EqualWeb service had a temporary problem. Your site is not the cause.';
$string['reg_todo_service_error'] = 'Click Try again in a few minutes. Automatic attempts back off. If it continues for more than a day, contact EqualWeb support with the technical details below.';
$string['reg_why_token_invalid'] = 'This site registration for free checks is no longer valid.';
$string['reg_todo_token_invalid'] = 'Click Try again to register this site again. If this keeps happening, another installation on the same domain may be registering over this one. Contact EqualWeb support.';
$string['reg_why_daily_limit_reached'] = 'This site has used its free checks for today.';
$string['reg_todo_daily_limit_reached'] = 'Free checks are available again at {$a}. The limit is shared by everyone who checks files on this site.';
$string['reg_why_outbound_blocked'] = 'This server could not connect to the EqualWeb service at {$a}.';
$string['reg_todo_outbound_blocked'] = 'Ask your hosting or IT team to allow outgoing HTTPS (port 443) from the web server to the EqualWeb service address below. If the server must use a proxy, set it in Site administration > Server > HTTP. For certificate errors, update the CA bundle of the server. Then click Try again.';
$string['reg_todo_outbound_blocked_policy'] = 'Moodle itself blocked the request. Remove the EqualWeb service address below from the cURL blocked hosts list and make sure port 443 is in the cURL allowed ports list (Site administration > General > Security > HTTP security). Then click Try again.';
$string['reg_why_unknown'] = 'Registration for free checks did not complete.';
$string['reg_why_unknown_error'] = 'Registration for free checks did not complete ({$a}).';
$string['reg_todo_unknown'] = 'Click Try again. If it fails again, contact EqualWeb support and include the technical details below.';
