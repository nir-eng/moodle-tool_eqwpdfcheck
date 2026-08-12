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
 * Builds the .eqw-* admin markup (ported from the WordPress/Drupal admin screen),
 * reading rows from the indexed state table.
 *
 * @package    tool_eqwpdfcheck
 * @copyright  2026 EqualWeb
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page_renderer {
    /** Max rows rendered (client-side paginates within this; large sites: v2 server paging). */
    const MAX_ROWS = 2000;

    /** EqualWeb account registration URL (also exposed to JS as signupUrl). */
    const SIGNUP_URL = 'https://login.equalweb.com/registration';

    /** Plain informational link for the free checker's AI tab — deliberately NOT SIGNUP_URL. */
    const LEARN_MORE_URL = 'https://login.equalweb.com';

    /**
     * Supported document mime types -> doctype key.
     *
     * The single source of truth for what the plugin manages: discovery,
     * the file list, analytics and the AJAX loader all accept exactly these
     * mimes. Whatever goes in, what comes back from remediation is ALWAYS
     * a PDF. (Mirrors Drupal AdminController::DOC_MIMES.)
     */
    const DOC_MIMES = [
        'application/pdf' => 'pdf',
        'application/msword' => 'word',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'word',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'ppt',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xls',
    ];

    /**
     * Doctype key ('pdf'|'word'|'ppt'|'xls') for a mime type.
     *
     * @param string $mime
     * @return string
     */
    public static function doctype(string $mime): string {
        return self::DOC_MIMES[$mime] ?? 'pdf';
    }

    /**
     * Inline SVG UI icon ('external' | 'download' | 'moon'), ~12px, decorative.
     *
     * stroke="currentColor" makes the glyph inherit the surrounding text color,
     * so it recolors with links/buttons in both themes. amd/src/admin.js keeps
     * an identical ICONS map — PHP-rendered and JS-rendered links MUST look the
     * same, so any change here must be mirrored there (and in amd/build).
     */
    private static function icon(string $name): string {
        $paths = [
            // A small box with an arrow leaving its top-right corner.
            'external' => '<path d="M14 4h6v6"/><path d="M20 4l-9 9"/>'
                . '<path d="M9 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-3"/>',
            // An arrow pointing down into a tray.
            'download' => '<path d="M12 4v11"/><path d="M6 10l6 6 6-6"/><path d="M4 20h16"/>',
            // Crescent moon (theme toggle).
            'moon' => '<path d="M20 13A8 8 0 1 1 11 4a6.5 6.5 0 0 0 9 9z"/>',
        ];
        if (!isset($paths[$name])) {
            return '';
        }
        $strokewidth = $name === 'moon' ? '2' : '2.2';
        return '<svg class="eqw-i" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' stroke-width="' . $strokewidth . '" stroke-linecap="round" stroke-linejoin="round"'
            . ' aria-hidden="true" focusable="false">' . $paths[$name] . '</svg>';
    }

    /**
     * Inline SVG file-type icon: a document glyph (rounded rectangle with a
     * folded top-right corner) filled with the type color, bold white type
     * letters centered on it. Emitted inline per row/pill — no external
     * assets, the page stays self-contained. The fills are fixed brand-ish
     * colors and the letters stay white, so the icon is light+dark safe.
     */
    protected static function type_icon(string $doctype, bool $decorative = false): string {
        $colors = ['pdf' => '#c2372f', 'word' => '#2b5797', 'ppt' => '#c2510f', 'xls' => '#1e7145'];
        $letters = ['pdf' => 'PDF', 'word' => 'DOC', 'ppt' => 'PPT', 'xls' => 'XLS'];
        $color = $colors[$doctype] ?? '#5b6472';
        $label = $letters[$doctype] ?? 'DOC';
        $a11y = $decorative ? ' aria-hidden="true"' : ' role="img" aria-label="' . $label . '"';
        return '<svg class="eqw-ficon eqw-ficon-' . $doctype . '" width="26" height="32" viewBox="0 0 26 32"'
            . $a11y . ' focusable="false">'
            . '<path d="M3 0h14l9 9v20a3 3 0 0 1-3 3H3a3 3 0 0 1-3-3V3a3 3 0 0 1 3-3z" fill="' . $color . '"/>'
            . '<path d="M17 0l9 9h-7a2 2 0 0 1-2-2V0z" fill="#fff" fill-opacity="0.35"/>'
            . '<text x="13" y="24.5" text-anchor="middle" font-family="Arial, Helvetica, sans-serif"'
            . ' font-size="8" font-weight="700" letter-spacing="0.3" fill="#fff">' . $label . '</text>'
            . '</svg>';
    }

    /**
     * Render a tab of the admin (or course) screen as an HTML string.
     *
     * @param string $tab
     * @param int|null $courseid Course scope, or null for the site-wide admin page.
     * @param array $caps Capability flags: manage, check, remediate, replace.
     * @return string
     */
    public static function render(
        string $tab,
        ?int $courseid = null,
        array $caps = ['manage' => true, 'check' => true, 'remediate' => true, 'replace' => true]
    ): string {
        // Safety net behind the entry scripts' free-edition tab whitelist: the
        // checker has no Compliance/Start/Settings/Permissions screens, so any
        // such tab renders the Documents list instead.
        if (edition::is_free() && in_array($tab, ['settings', 'analytics', 'classification', 'start', 'permissions'], true)) {
            $tab = 'files';
        }
        $haskey = client::get_personal_key() !== '';
        $html = self::header($tab, $courseid);
        if ($courseid === null && !empty($caps['manage'])) {
            $html .= self::update_banner();
        }
        if ($tab === 'settings') {
            $html .= self::settings_tab();
        } else if ($tab === 'analytics') {
            // The 'Compliance' tab groups Analytics + Classification behind a sub-nav.
            $html .= self::compliance_nav('analytics') . self::analytics_tab();
        } else if ($tab === 'classification') {
            $html .= self::compliance_nav('classification') . self::classification_tab();
        } else if ($tab === 'ai') {
            $html .= self::ai_tab();
        } else if ($tab === 'start') {
            $html .= self::start_tab();
        } else if ($tab === 'permissions') {
            $html .= self::permissions_tab();
        } else {
            $html .= self::files_tab($haskey, $courseid, $caps);
        }
        $version = (string) (\core_plugin_manager::instance()->get_plugin_info('tool_eqwpdfcheck')->release ?? '');
        // The checker footer is branded from its own product name; the full
        // edition keeps the versionfoot lang-string mechanics unchanged.
        $foot = edition::is_free()
            ? get_string('checker_headertitle', 'tool_eqwpdfcheck') . ' v' . $version
            : get_string('versionfoot', 'tool_eqwpdfcheck', $version);
        $html .= '<p class="eqw-version-foot">' . self::e($foot) . '</p>';
        $html .= '</div>';
        $html .= '<div id="eqw-modal" class="eqw-modal" hidden><div class="eqw-modal-box">'
            . '<button type="button" class="eqw-modal-close" aria-label="'
            . get_string('close', 'tool_eqwpdfcheck') . '">&times;</button>'
            . '<div class="eqw-modal-content"></div></div></div>';
        return $html;
    }

    /**
     * Escape a value for safe HTML text/attribute output (Moodle s()).
     *
     * @param mixed $text
     * @return string
     */
    protected static function e($text): string {
        return s((string) $text);
    }

    /**
     * "Update available" banner for site admins (admin page only).
     *
     * Polls the shared Worker's update.json (?platform=moodle) at most once a
     * day, caches the answer in plugin config, and never breaks the page: a
     * slow or failing check is cached as "no update" and retried the next
     * day. The banner links to the ZIP the release pipeline publishes.
     */
    protected static function update_banner(): string {
        if (edition::is_free()) {
            // The free checker never polls the paid feed and never offers its ZIP.
            return '';
        }
        $cached = json_decode((string) get_config('tool_eqwpdfcheck', 'updatecheck'), true) ?: [];
        if (($cached['at'] ?? 0) < time() - 86400) {
            $cached = ['at' => time(), 'version' => '', 'package' => ''];
            try {
                $res = (new \core\http_client(['timeout' => 4]))->request(
                    'GET',
                    client::worker_url() . '/plugin/update.json?platform=moodle',
                    ['http_errors' => false]
                );
                $meta = json_decode((string) $res->getBody(), true);
                if ($res->getStatusCode() === 200 && !empty($meta['version'])) {
                    $cached['version'] = (string) $meta['version'];
                    $cached['package'] = (string) ($meta['package'] ?? '');
                }
            } catch (\Throwable $e) {
                debugging($e->getMessage(), DEBUG_DEVELOPER); // Cached as "no update" - retried tomorrow.
            }
            set_config('updatecheck', json_encode($cached), 'tool_eqwpdfcheck');
        }
        $current = (string) (\core_plugin_manager::instance()->get_plugin_info('tool_eqwpdfcheck')->release ?? '0');
        if (($cached['version'] ?? '') === '' || !version_compare($cached['version'], $current, '>')) {
            return '';
        }
        $link = !empty($cached['package'])
            ? ' <a href="' . self::e($cached['package']) . '" target="_blank" rel="noopener">'
                . get_string('updatedownload', 'tool_eqwpdfcheck') . self::icon('external') . '</a>'
            : '';
        return '<div class="eqw-update-banner"><strong>' . get_string('updateavailable', 'tool_eqwpdfcheck') . '</strong> '
            . self::e(get_string(
                'updatebody',
                'tool_eqwpdfcheck',
                (object) ['version' => $cached['version'], 'current' => $current]
            ))
            . $link . '</div>';
    }

    /**
     * URL of an admin-page tab.
     *
     * @param string $tab
     * @return string
     */
    protected static function taburl(string $tab): string {
        $params = $tab ? ['tab' => $tab] : [];
        return (new \moodle_url('/admin/tool/eqwpdfcheck/index.php', $params))->out(false);
    }

    /**
     * The page header: logo, title and the tab navigation.
     *
     * @param string $tab
     * @param int|null $courseid
     * @return string
     */
    protected static function header(string $tab, ?int $courseid = null): string {
        global $CFG;
        $isfree = edition::is_free();
        $logo = $CFG->wwwroot . '/admin/tool/eqwpdfcheck/pix/logo.png';
        $h = '<div class="eqw-wrap">';
        $h .= '<h1 class="eqw-h1"><img class="eqw-logo" src="' . self::e($logo) . '" alt="'
            . get_string('logoalt', 'tool_eqwpdfcheck') . '" height="24" onerror="this.style.display=\'none\'">'
            . '<span class="eqw-h1-rest">'
            . get_string($isfree ? 'checker_headertitle' : 'headertitle', 'tool_eqwpdfcheck') . '</span></h1>';
        $h .= '<nav class="nav-tab-wrapper eqw-tabs">';
        if ($courseid === null) {
            // Admin page only — the course page is files-only (no Compliance /
            // Settings at course scope). 'Compliance' groups Analytics +
            // Classification (a sub-nav renders inside); its nav entry lands on
            // Analytics and lights up for both. The free checker has exactly
            // two tabs: Documents + AI.
            $tabs = [['files', get_string('tab_files', 'tool_eqwpdfcheck'), ''],
                ['compliance', get_string('nav_compliance', 'tool_eqwpdfcheck'), 'analytics'],
                ['ai', get_string('tab_ai', 'tool_eqwpdfcheck'), 'ai'],
                ['start', get_string('tab_start', 'tool_eqwpdfcheck'), 'start'],
                ['settings', get_string('tab_settings', 'tool_eqwpdfcheck'), 'settings'],
                ['permissions', get_string('tab_permissions', 'tool_eqwpdfcheck'), 'permissions']];
            if ($isfree) {
                $tabs = [['files', get_string('tab_files', 'tool_eqwpdfcheck'), ''],
                    ['ai', get_string('tab_ai', 'tool_eqwpdfcheck'), 'ai']];
            }
            foreach ($tabs as [$key, $label, $q]) {
                $active = ($tab === $key || ($key === 'compliance' && in_array($tab, ['analytics', 'classification'], true)))
                    ? ' nav-tab-active' : '';
                $h .= '<a href="' . self::e(self::taburl($q)) . '" class="nav-tab' . $active . '">' . $label . '</a>';
            }
        }
        if (!$isfree) {
            // No marketing links in the checker — the theme toggle stays. The
            // external icon is decorative (aria-hidden); the .accesshide span
            // tells screen readers each link opens an external website.
            $h .= '<a href="https://www.equalweb.com/platform/pdf_tools/" target="_blank" rel="noopener"'
                . ' class="eqw-tools-link button">' . get_string('toolslink', 'tool_eqwpdfcheck')
                . '<span class="accesshide"> (external website)</span>' . self::icon('external') . '</a>';
            $h .= '<a href="https://www.equalweb.com/platform/pdf_tools/support.html" target="_blank" rel="noopener"'
                . ' class="eqw-tools-link button">' . get_string('support', 'tool_eqwpdfcheck')
                . '<span class="accesshide"> (external website)</span>' . self::icon('external') . '</a>';
        }
        $h .= '<button type="button" class="button eqw-theme-toggle" id="eqw-theme-toggle" aria-pressed="false">'
            . self::icon('moon') . get_string('toggletheme', 'tool_eqwpdfcheck') . '</button>';
        $h .= '</nav>';
        return $h;
    }

    /**
     * Ordered document state rows (capped), optionally scoped to one course.
     *
     * @param int|null $courseid Course scope, or null for site-wide.
     * @return array
     */
    protected static function pdf_rows(?int $courseid = null): array {
        global $DB;
        // Join {files} for the live mimetype (doctype rendering) — the join
        // also drops rows whose underlying file vanished since the last
        // discovery prune, so a dead row can never render.
        $where = '';
        $params = [];
        if ($courseid !== null) {
            $where = ' WHERE s.courseid = :courseid';
            $params['courseid'] = $courseid;
        }
        return $DB->get_records_sql('SELECT s.*, f.mimetype
                                       FROM {tool_eqwpdfcheck_state} s
                                       JOIN {files} f ON f.id = s.fileid' . $where . '
                                   ORDER BY s.filetimecreated DESC, s.id DESC', $params, 0, self::MAX_ROWS);
    }

    /**
     * All supported-document state rows, site-wide (no course scope). The
     * classification AJAX actions are system :manage, so they validate their
     * fids/locations against exactly this set.
     */
    public static function all_doc_rows(): array {
        return self::pdf_rows(null);
    }

    /**
     * Sub-nav inside the Compliance tab (Analytics | Classification).
     *
     * @param string $current
     * @return string
     */
    protected static function compliance_nav(string $current): string {
        $h = '<div class="eqw-subtabs" role="navigation" aria-label="' . get_string('compliance', 'tool_eqwpdfcheck') . '">';
        $h .= '<a class="eqw-subtab' . ($current === 'analytics' ? ' eqw-subtab-active' : '') . '" href="'
            . self::e(self::taburl('analytics')) . '">' . get_string('tab_analytics', 'tool_eqwpdfcheck') . '</a>';
        $h .= '<a class="eqw-subtab' . ($current === 'classification' ? ' eqw-subtab-active' : '') . '" href="'
            . self::e(self::taburl('classification')) . '">' . get_string('nav_classification', 'tool_eqwpdfcheck') . '</a>';
        return $h . '</div>';
    }

    /**
     * JS i18n strings for the admin AMD module (shared by index.php and course.php).
     *
     * @return array
     */
    public static function js_i18n(): array {
        // JS property names (camelCase) mapped to lang keys (js_<lower>).
        $map = [
            'checking' => 'checking', 'remediating' => 'remediating', 'error' => 'error', 'check' => 'check',
            'details' => 'details', 'remediate' => 'remediate',
            'needKeyTitle' => 'needkeytitle', 'needKeyBody' => 'needkeybody', 'buyCredits' => 'buycredits',
            'openSettings' => 'opensettings', 'applyReplace' => 'applyreplace', 'applyNew' => 'applynew',
            'applyDownload' => 'applydownload', 'restore' => 'restore', 'confirmReplace' => 'confirmreplace',
            'confirmRestore' => 'confirmrestore', 'remediateReady' => 'remediateready', 'passed' => 'passed',
            'failed' => 'failed', 'warnings' => 'warnings', 'score' => 'score', 'reportTitle' => 'reporttitle',
            'noIssues' => 'noissues', 'before' => 'before', 'after' => 'after',
            'removeKeyConfirm' => 'removekeyconfirm', 'viewReport' => 'viewreport',
            'externalLink' => 'externallink', 'reportColStatus' => 'reportcolstatus',
            'reportColIssue' => 'reportcolissue',
            'notChecked' => 'notchecked', 'notRemediated' => 'notremediated', 'accessible' => 'accessible',
            'ready' => 'ready', 'items' => 'items', 'selected' => 'selected', 'openCopy' => 'opencopy',
            'needKeyBodyNoAdmin' => 'needkeybodynoadmin', 'inProgress' => 'inprogress',
            'sendRemediation' => 'sendremediation', 'officeConfirm' => 'officeconfirm',
            'saveAsPdf' => 'saveaspdf', 'keyRequired' => 'keyrequired', 'officeReady' => 'officeready',
            'download' => 'download', 'openAccessiblePdf' => 'openaccessiblepdf', 'downloadPdf' => 'downloadpdf',
            'bulkSentOne' => 'bulksentone', 'bulkSentMany' => 'bulksentmany',
            'bulkSkippedChecked' => 'bulkskippedchecked', 'bulkSkippedProcessed' => 'bulkskippedprocessed',
            'bulkSkippedOffice' => 'bulkskippedoffice', 'bulkNothingSent' => 'bulknothingsent',
            'bulkNothingToSend' => 'bulknothingtosend',
            // Classification (triage).
            'clsTitle' => 'cls_title', 'clsBody' => 'cls_body', 'clsIgnoreConfirm' => 'cls_ignoreconfirm',
            'clsTagged' => 'cls_tagged', 'clsIgnored' => 'cls_ignored', 'clsRestored' => 'cls_restored',
            'clsAccessibility' => 'cls_accessibility', 'clsLegal' => 'cls_legal', 'clsUsage' => 'cls_usage',
            'clsLegalSub' => 'cls_legalsub', 'clsUsageNA' => 'cls_usagena', 'clsWhy' => 'cls_why',
            'clsMeasured' => 'cls_measured', 'clsAuto' => 'cls_auto', 'clsChipHint' => 'cls_chiphint',
            'clsAutoDone' => 'cls_autodone', 'clsLocTagged' => 'cls_loctagged', 'clsLocCleared' => 'cls_loccleared',
            'clsYearsSaved' => 'cls_yearssaved',
            // Free-edition badge + the serial check lock (all editions).
            'checkedBadge' => 'checkedbadge', 'checkLockTitle' => 'checklocktitle',
        ];
        if (edition::is_free()) {
            // The free checker build STRIPS every js_* lang string outside this
            // whitelist (all purchase/remediation language included), so the
            // free map may only name keys whose lang strings the free tree
            // keeps — and every key the free JS reads must be here (a missing
            // key renders as 'undefined'). get_string() never runs for the
            // rest, so a stripped string can never be requested.
            $freekeys = [
                // Checker row states, the Check button and the serial check
                // lock (renderRow free branches + toggleCheckButtons).
                // 'remediating'/'inProgress' cover stale in-flight rows after
                // a full->free edition switch and capability-less viewers on
                // the course page (the static in-progress badge).
                'checking', 'remediating', 'inProgress', 'error', 'check',
                'notChecked', 'checkedBadge', 'checkLockTitle',
                // Score cell + external report links (scoreBadge/scoreCell).
                // externalLink is the visually-hidden "(external link)" text on
                // every external link, including the free score-cell report link.
                'score', 'viewReport', 'externalLink',
                // Report modal — it renders in free (only the classification
                // strip clsStrip() is empty), so all its strings stay. The
                // issues table header uses reportColStatus/reportColIssue.
                'reportTitle', 'passed', 'failed', 'warnings', 'noIssues',
                'before', 'after', 'reportColStatus', 'reportColIssue',
                // List pagination counter.
                'items',
            ];
            $map = array_intersect_key($map, array_flip($freekeys));
        }
        $i18n = [];
        foreach ($map as $jsname => $langkey) {
            $i18n[$jsname] = get_string('js_' . $langkey, 'tool_eqwpdfcheck');
        }
        return $i18n;
    }

    /**
     * Human-readable file size (B/KB/MB/GB).
     *
     * @param int $bytes
     * @return string
     */
    protected static function format_size(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < 3) {
            $n /= 1024;
            $i++;
        }
        return ($i ? round($n, $n >= 10 ? 0 : 1) : $n) . ' ' . $units[$i];
    }

    /* ---------------------------------------------------------------- */
    /* PDF Files tab                                                     */
    /* ---------------------------------------------------------------- */

    /**
     * The Documents tab: type pills, category panel, toolbar and the file table.
     *
     * @param bool $haskey Whether a personal API key is configured.
     * @param int|null $courseid Course scope, or null for site-wide.
     * @param array $caps Capability flags for the current user.
     * @return string
     */
    protected static function files_tab(bool $haskey, ?int $courseid, array $caps): string {
        global $DB;
        $isfree = edition::is_free();
        $rows = self::pdf_rows($courseid);
        if (!$rows) {
            return '<p>' . get_string($courseid !== null ? 'nopdffiles_course' : 'nopdffiles', 'tool_eqwpdfcheck') . '</p>';
        }

        // Classify everything once (batched), then hide ignored rows from the
        // LIST only — the category panel still counts them and offers restore.
        // Classification actions themselves are :manage; the panel + columns are
        // read-only triage that every viewer sees.
        $canmanage = !empty($caps['manage']);
        $cls = classification::classify_all($rows);
        $ignoredcount = 0;
        foreach ($cls as $c) {
            if (!empty($c['ignored'])) {
                $ignoredcount++;
            }
        }
        $rows = array_filter($rows, function ($r) use ($cls) {
            return empty($cls[(int) $r->fileid]['ignored']);
        });
        if (!$rows) {
            return ($isfree ? '' : self::category_panel($cls, $ignoredcount, $canmanage))
                . '<p>' . get_string($courseid !== null ? 'allignored_course' : 'allignored', 'tool_eqwpdfcheck') . '</p>';
        }

        $totalpages = 0;
        $known = true;
        $typecounts = ['pdf' => 0, 'word' => 0, 'ppt' => 0, 'xls' => 0];
        foreach ($rows as $r) {
            $typecounts[self::doctype((string) $r->mimetype)]++;
            // The page_count() helper answers for every doctype: office documents have no
            // LOCAL page count, but they do have EqualWeb's once they have been
            // through the service. Anything still unknown turns the total into
            // an "n+".
            $pages = pdfstore::page_count((int) $r->fileid);
            if ($pages === null) {
                $known = false;
            } else {
                $totalpages += $pages;
            }
        }

        // Bulk actions + checkboxes only when the user can actually run one
        // (free checks need no API key; remediation does). The checker has no
        // bulk surface at all — its checks run one at a time (serial lock).
        $showcb = !$isfree && ($caps['check'] || ($caps['remediate'] && $haskey));

        // Duplicate-content occurrences (one query, scoped to the course on
        // the course page so no cross-course counts leak): each copy is
        // checked/remediated independently, so rows sharing a contenthash get
        // a hint that remediating one copy does not change the others.
        $dupscope = '';
        $dupparams = [];
        if ($courseid !== null) {
            $dupscope = ' AND courseid = :courseid';
            $dupparams['courseid'] = $courseid;
        }
        $dups = $DB->get_records_sql_menu('SELECT contenthash, COUNT(*) AS cnt
                                             FROM {tool_eqwpdfcheck_state}
                                            WHERE contenthash IS NOT NULL' . $dupscope . '
                                         GROUP BY contenthash
                                           HAVING COUNT(*) > 1', $dupparams);

        // Document-type filter pills (client-side filtering via data-doctype).
        $h = '<div class="eqw-typepills" role="group" aria-label="' . get_string('filterbytype', 'tool_eqwpdfcheck') . '">';
        $pills = ['' => get_string('pill_all', 'tool_eqwpdfcheck'), 'pdf' => get_string('pill_pdf', 'tool_eqwpdfcheck'),
            'word' => get_string('pill_word', 'tool_eqwpdfcheck'), 'ppt' => get_string('pill_ppt', 'tool_eqwpdfcheck'),
            'xls' => get_string('pill_xls', 'tool_eqwpdfcheck')];
        foreach ($pills as $type => $label) {
            $count = $type === '' ? count($rows) : $typecounts[$type];
            // The pill label already names the type — its icon is decorative.
            $icon = $type === '' ? '' : self::type_icon($type, true);
            $h .= '<button type="button" class="eqw-pill' . ($type === '' ? ' eqw-pill-active' : '')
                . '" data-doctype="' . $type . '" aria-pressed="' . ($type === '' ? 'true' : 'false') . '">'
                . $icon . $label . ' <span class="eqw-pill-count">' . $count . '</span></button>';
        }
        $h .= '</div>';

        if (!$isfree) {
            $h .= self::category_panel($cls, $ignoredcount, $canmanage);
        }

        $h .= '<div class="eqw-toolbar"><p class="eqw-totals">'
            . get_string(
                'totals',
                'tool_eqwpdfcheck',
                (object) ['docs' => count($rows), 'pages' => $totalpages . ($known ? '' : '+')]
            );
        if ($caps['remediate'] && !$isfree) {
            $h .= ' <span class="description">' . get_string('creditnote', 'tool_eqwpdfcheck') . '</span>';
        }
        if ($caps['check']) {
            $h .= ' <span class="description eqw-quota-note">' . self::e(get_string('quotanote', 'tool_eqwpdfcheck')) . '</span>';
        }
        $h .= '</p>';
        $h .= '<div class="eqw-filters">'
            . '<input type="search" id="eqw-q" placeholder="' . get_string('searchph', 'tool_eqwpdfcheck')
            . '" aria-label="' . get_string('searcharia', 'tool_eqwpdfcheck') . '">'
            . '<select id="eqw-filter" aria-label="' . get_string('filteraria', 'tool_eqwpdfcheck') . '">'
            . '<option value="">' . get_string('filter_all', 'tool_eqwpdfcheck') . '</option>'
            . '<optgroup label="' . get_string('filtergroup_status', 'tool_eqwpdfcheck') . '">'
            . '<option value="unchecked">' . get_string('filter_unchecked', 'tool_eqwpdfcheck') . '</option>'
            . '<option value="checked">' . get_string('filter_checked', 'tool_eqwpdfcheck') . '</option>'
            . '<option value="in_progress">' . get_string('filter_inprogress', 'tool_eqwpdfcheck') . '</option>';
        if (!$isfree) {
            // Remediation/replacement states and classification categories do
            // not exist in the checker — it filters none/checking/checked only.
            $h .= '<option value="ready">' . get_string('filter_ready', 'tool_eqwpdfcheck') . '</option>'
                . '<option value="remediated">' . get_string('filter_remediated', 'tool_eqwpdfcheck') . '</option>'
                . '<option value="not_remediated">' . get_string('filter_notremediated', 'tool_eqwpdfcheck')
                . '</option></optgroup>'
                . '<optgroup label="' . get_string('filtergroup_replacement', 'tool_eqwpdfcheck') . '">'
                . '<option value="replaced">' . get_string('filter_replaced', 'tool_eqwpdfcheck') . '</option>'
                . '<option value="not_replaced">' . get_string('filter_notreplaced', 'tool_eqwpdfcheck') . '</option></optgroup>'
                . '<optgroup label="' . get_string('filtergroup_classification', 'tool_eqwpdfcheck') . '">'
                . implode('', array_map(
                    function ($key) {
                        return '<option value="cls_' . $key . '">' . self::e(classification::cat_label($key)) . '</option>';
                    },
                    array_keys(classification::CATS)
                ))
                . '</optgroup>';
        } else {
            $h .= '</optgroup>';
        }
        $h .= '</select>'
            . '<select id="eqw-sort" aria-label="' . get_string('sortaria', 'tool_eqwpdfcheck') . '">'
            . '<option value="newest">' . get_string('sort_newest', 'tool_eqwpdfcheck') . '</option>';
        if (!$isfree) {
            $h .= '<option value="prio_desc">' . get_string('sort_prio_desc', 'tool_eqwpdfcheck') . '</option>';
        }
        $h .= '<option value="score_asc">' . get_string('sort_score_asc', 'tool_eqwpdfcheck') . '</option>'
            . '<option value="score_desc">' . get_string('sort_score_desc', 'tool_eqwpdfcheck') . '</option></select>'
            . '</div></div>';

        if ($showcb) {
            // Outcome of the last bulk click. Filled by the JS, above the table.
            $h .= '<div id="eqw-bulk-notice" class="eqw-bulk-notice notice notice-info" role="status" hidden></div>';
            $h .= '<div class="eqw-bulkbar"><span id="eqw-selected-count" class="eqw-selected-count"></span>';
            if ($caps['check']) {
                $h .= '<button type="button" class="button" id="eqw-bulk-check" disabled>'
                    . get_string('bulk_check', 'tool_eqwpdfcheck') . '</button>';
            }
            if ($caps['remediate']) {
                $h .= '<button type="button" class="button button-primary" id="eqw-bulk-remediate" disabled>'
                    . get_string('bulk_remediate', 'tool_eqwpdfcheck') . '</button>';
            }
            if ($canmanage) {
                $h .= '<button type="button" class="button" id="eqw-bulk-tag" disabled>'
                    . get_string('bulk_tag', 'tool_eqwpdfcheck') . '</button>';
                $h .= '<button type="button" class="button" id="eqw-bulk-ignore" disabled>'
                    . get_string('bulk_ignore', 'tool_eqwpdfcheck') . '</button>';
            }
            $h .= '</div>';
        }

        // Class eqw-files marks THE interactive documents table — all behavioural
        // JS selectors target it, so decorative tables (Analytics by-user,
        // guide tables) can keep eqw-table for styling without being mangled
        // by renderRow.
        $h .= '<table class="eqw-table eqw-files"><thead><tr>';
        if ($showcb) {
            $h .= '<th class="eqw-col-cb"><input type="checkbox" id="eqw-check-all" aria-label="'
                . get_string('selectall', 'tool_eqwpdfcheck') . '"></th>';
        }
        $h .= '<th>' . get_string('col_file', 'tool_eqwpdfcheck') . '</th><th class="eqw-col-size">'
            . get_string('col_size', 'tool_eqwpdfcheck') . '</th>'
            . '<th class="eqw-col-pages">' . get_string('col_pages', 'tool_eqwpdfcheck') . '</th>'
            . '<th class="eqw-col-lang">' . get_string('col_lang', 'tool_eqwpdfcheck') . '</th>'
            . '<th class="eqw-col-date">' . get_string('col_uploaded', 'tool_eqwpdfcheck') . '</th>';
        if (!$isfree) {
            $h .= '<th class="eqw-col-cat">' . get_string('col_category', 'tool_eqwpdfcheck') . '</th>'
                . '<th class="eqw-col-prio">' . get_string('col_priority', 'tool_eqwpdfcheck') . '</th>'
                . '<th class="eqw-col-score">' . get_string('col_scorebefore', 'tool_eqwpdfcheck') . '</th>'
                . '<th class="eqw-col-score">' . get_string('col_scoreafter', 'tool_eqwpdfcheck') . '</th>';
        } else {
            // The checker has ONE score per document — no before/after pair and
            // no category/priority triage columns.
            $h .= '<th class="eqw-col-score">' . get_string('js_score', 'tool_eqwpdfcheck') . '</th>';
        }
        $h .= '<th class="eqw-col-status">' . get_string('col_status', 'tool_eqwpdfcheck') . '</th>'
            . '<th class="eqw-col-actions">' . get_string('col_actions', 'tool_eqwpdfcheck') . '</th></tr></thead><tbody>';

        foreach ($rows as $r) {
            $dupcount = ($r->contenthash !== null && isset($dups[$r->contenthash])) ? (int) $dups[$r->contenthash] : 0;
            $h .= self::row($r, $showcb, $dupcount, $cls[(int) $r->fileid] ?? []);
        }
        $h .= '</tbody></table>';
        $h .= '<p id="eqw-no-match" hidden>' . self::e(get_string('nomatch', 'tool_eqwpdfcheck')) . '</p>';
        $h .= '<div class="eqw-pager" id="eqw-pager"></div>';
        return $h;
    }

    /**
     * One document row of the file table.
     *
     * @param \stdClass $r State row joined with the live mimetype.
     * @param bool $showcb Whether the selection checkbox column is shown.
     * @param int $dupcount Number of occurrences sharing this content hash.
     * @param array $cls Classification result for this file (from classify_all()).
     * @return string
     */
    protected static function row(\stdClass $r, bool $showcb, int $dupcount = 0, array $cls = []): string {
        $fileid = (int) $r->fileid;
        $doctype = self::doctype((string) $r->mimetype);
        $ispdf = $doctype === 'pdf';
        // Title and language come from PDF metadata — never run those
        // extractors on office binaries. The page count is different: office
        // rows get theirs from EqualWeb, so page_count() is asked for every
        // doctype and simply answers null until the count is known.
        $title = $ispdf ? pdfstore::doc_title($fileid) : '';
        $lang = $ispdf ? pdfstore::doc_lang($fileid) : '';
        $pages = pdfstore::page_count($fileid);
        $state = $r->state ?: 'none';
        $summary = $r->summary ? json_decode($r->summary, true) : null;
        $after = $r->summaryafter ? json_decode($r->summaryafter, true) : null;
        $score = isset($summary['score']) ? $summary['score'] : '';
        $scoreafter = isset($after['score']) ? $after['score'] : '';
        $reporturl = $r->docid ? client::report_url($r->docid) : '';
        $reporturlafter = $r->remdocid ? client::report_url($r->remdocid) : '';
        $newurl = ((int) $r->newfileid) ? filemanager::accessible_url($fileid) : '';
        $display = $title !== '' ? $title : $r->filename;
        $search = \core_text::strtolower($display . ' ' . $r->filename);

        $fileurl = '';
        if ($r->contextid && $r->component) {
            // The filepath is part of the address, not decoration: discovery
            // indexes files at any depth (a PDF in a Folder activity's /docs/
            // is routine) and a hardcoded '/' here renders a link that 404s.
            // Rows indexed before the column existed fall back to the root.
            $fileurl = \moodle_url::make_pluginfile_url(
                $r->contextid,
                $r->component,
                $r->filearea,
                $r->itemid,
                ($r->filepath ?: '/'),
                $r->filename
            )->out(false);
        }

        // Classification chip + priority. src 'user' (admin tag) shows 👤, auto
        // shows 🤖; the matched rules ride the chip title as the "why".
        $cat = $cls['cat'] ?? 'internal';
        $catmeta = classification::CATS[$cat] ?? classification::CATS['internal'];
        $catkey = isset(classification::CATS[$cat]) ? $cat : 'internal';
        $prio = (int) ($cls['prio'] ?? $catmeta['prio']);
        $clssrc = ($cls['src'] ?? 'auto') === 'user' ? 'user' : 'auto';
        $clsrules = implode(' · ', (array) ($cls['rules'] ?? []));

        $h = '<tr data-id="' . $fileid . '" data-doctype="' . $doctype . '" data-search="' . self::e($search) . '"'
            . ' data-state="' . self::e($state) . '"'
            . ' data-score="' . self::e($score) . '" data-score-after="' . self::e($scoreafter) . '"'
            . ' data-report-url="' . self::e($reporturl) . '" data-report-url-after="' . self::e($reporturlafter) . '"'
            . ' data-new-url="' . self::e($newurl) . '" data-has-backup="' . ((int) $r->hasbackup ? '1' : '0') . '"'
            . ' data-cat="' . self::e($cat) . '" data-prio="' . $prio . '" data-cls-src="' . $clssrc . '"'
            . ' data-cls-rules="' . self::e($clsrules) . '">';
        if ($showcb) {
            $h .= '<td class="eqw-cb-cell"><input type="checkbox" class="eqw-row-cb" aria-label="'
                . get_string('selectfile', 'tool_eqwpdfcheck') . '"></td>';
        }
        // The icon must stay a SIBLING of the link/span — js updateFileMeta()
        // targets '.eqw-col-file > a/span' and rewrites its text nodes in place.
        $link = $fileurl ? '<a href="' . self::e($fileurl) . '" target="_blank" rel="noopener">' . self::e($display) . '</a>'
            : '<span>' . self::e($display) . '</span>';
        $h .= '<td class="eqw-col-file">' . self::type_icon($doctype) . $link
            . '<div class="eqw-filename">' . self::e($r->filename) . '</div>';
        if ($dupcount > 1) {
            $h .= '<div class="eqw-filename eqw-dup-note">'
                . self::e(get_string('dupnote', 'tool_eqwpdfcheck', $dupcount - 1)) . '</div>';
        }
        $h .= '</td>';
        $h .= '<td>' . self::e(self::format_size((int) $r->filesize)) . '</td>';
        $h .= '<td class="eqw-num">' . ($pages === null ? '—' : (int) $pages) . '</td>';
        $h .= '<td class="eqw-num eqw-lang-cell">' . ($lang !== '' ? self::e($lang) : '—') . '</td>';
        $h .= '<td class="eqw-num">' . self::e(userdate((int) $r->filetimecreated, '%b %e, %Y')) . '</td>';
        if (!edition::is_free()) {
            // Category chip (🤖 auto / 👤 admin tag) + priority. The chip's title
            // carries the matched rules — the "why" behind the rank; admins get a
            // clickable chip (JS upgrades it) to reclassify one file directly.
            // The checker drops both cells (and the score-after cell below) to
            // match its slimmer table head; the data-* attributes above stay.
            $srcmark = $clssrc === 'user' ? '👤' : '🤖';
            $priocolor = $prio >= 85 ? '#a12727' : ($prio >= 60 ? '#9a4b00' : '#787c82');
            $h .= '<td class="eqw-cat-cell"><span class="eqw-tagchip" title="' . self::e($clsrules) . '">'
                . '<span class="eqw-catsw" style="background:' . $catmeta['color'] . '"></span>'
                . self::e(classification::cat_label($catkey)) . ' <span aria-hidden="true">' . $srcmark . '</span></span></td>';
            $h .= '<td class="eqw-prio-cell"><span class="eqw-prio"><b style="color:' . $priocolor . '">' . $prio . '</b>'
                . '<span class="eqw-prio-meter"><i style="width:' . $prio . '%;background:' . $priocolor
                . '"></i></span></span></td>';
            $h .= '<td class="eqw-before-cell"></td><td class="eqw-after-cell"></td>';
        } else {
            $h .= '<td class="eqw-before-cell"></td>';
        }
        $h .= '<td class="eqw-status-cell"></td><td class="eqw-actions-cell"></td></tr>';
        return $h;
    }

    /* ---------------------------------------------------------------- */
    /* Compliance: category panel + Classification screen                */
    /* ---------------------------------------------------------------- */



    /**
     * One location (course) row on the Classification screen.
     *
     * @param array $loc Location descriptor from classification::locations().
     * @return string
     */
    protected static function location_row(array $loc): string {
        $tag = $loc['tag'];
        $cats = classification::CATS;
        $h = '<div class="eqw-clsrow" data-prefix="' . self::e($loc['prefix']) . '">';
        $h .= '<span class="eqw-clsloc"><b>' . self::e($loc['label']) . '</b>'
            . '<small>' . get_string('loc_documents', 'tool_eqwpdfcheck', (int) $loc['count'])
            . (!empty($loc['ignored']) ? ' · ' . get_string('loc_ignored', 'tool_eqwpdfcheck', (int) $loc['ignored']) : '')
            . '</small></span>';

        if ($tag) {
            $h .= '<span class="eqw-srcchip eqw-srcchip-user">👤 '
                . self::e(isset($cats[$tag['cat']]) ? classification::cat_label($tag['cat']) : $tag['cat']) . '</span>';
            // Data (admin name / date) pre-escaped, then dropped into the sentence.
            $by = self::e($tag['by'] ?? get_string('cls_anadmin', 'tool_eqwpdfcheck'));
            if (!empty($tag['at'])) {
                $line = get_string(
                    'loc_taggedby',
                    'tool_eqwpdfcheck',
                    (object) ['by' => $by, 'date' => self::e(userdate((int) $tag['at'], '%b %e, %Y'))]
                );
            } else {
                $line = get_string('loc_taggedby_nodate', 'tool_eqwpdfcheck', $by);
            }
            $h .= '<span class="eqw-clsact"><span class="eqw-sugg-line">' . $line . '</span>'
                . '<button type="button" class="button eqw-cls-clear">'
                . get_string('loc_removetag', 'tool_eqwpdfcheck') . '</button></span>';
        } else {
            $h .= '<span class="eqw-srcchip eqw-srcchip-open">' . get_string('loc_untagged', 'tool_eqwpdfcheck') . '</span>';
            $h .= '<span class="eqw-clsact">';
            if (!empty($loc['suggestion'])) {
                $sc = $loc['suggestion']['cat'];
                $h .= '<span class="eqw-sugg-line">' . get_string('loc_suggprefix', 'tool_eqwpdfcheck')
                    . ' <b>' . self::e(classification::cat_label($sc)) . '</b> — '
                    . $loc['suggestion']['why'] . '</span>'
                    . '<button type="button" class="button button-primary eqw-cls-accept" data-cat="' . self::e($sc) . '">'
                    . get_string('loc_accept', 'tool_eqwpdfcheck') . '</button>';
            }
            $h .= '<select class="eqw-cls-select" aria-label="'
                . self::e(get_string('loc_tagaria', 'tool_eqwpdfcheck', $loc['label'])) . '">'
                . '<option value="">' . get_string('loc_choosetag', 'tool_eqwpdfcheck') . '</option>';
            foreach (array_keys($cats) as $key) {
                $h .= '<option value="' . $key . '">' . self::e(classification::cat_label($key)) . '</option>';
            }
            $h .= '</select></span>';
        }
        return $h . '</div>';
    }

    /* ---------------------------------------------------------------- */
    /* Analytics tab                                                     */
    /* ---------------------------------------------------------------- */


    /**
     * Per-year aggregates for the Executive report, newest year first.
     *
     * Derived from the plugin's own state rows (the same pdf_rows() feed the
     * analytics tab aggregates read). A year exists only if it saw an event.
     *
     * A check counts in the year/month of summary.checked_at.
     *
     * A document counts as remediated in year Y if, and only if, its state is
     * 'remediated' (the accessible version was actually APPLIED, by replace or
     * by save-as-new) AND its applied-timestamp falls in Y. Finishing a paid run
     * is not the same thing as applying it, and restore takes a document back
     * out of the count. This is the same predicate the Analytics "Remediated"
     * tile uses, so the tile and the report agree by construction. Rows applied
     * before the applied-timestamp shipped fall back to timemodified.
     */
    public static function yearly_stats(): array {
        $years = [];
        $blank = function (): array {
            return [
                'checked' => 0,
                'remediated' => 0,
                'pages' => 0,
                'fixed' => 0,
                'score_before_sum' => 0,
                'score_after_sum' => 0,
                'score_n' => 0,
                'months_checked' => array_fill(0, 12, 0),
                'months_remediated' => array_fill(0, 12, 0),
            ];
        };
        foreach (self::pdf_rows() as $r) {
            $summary = $r->summary ? json_decode($r->summary, true) : null;
            $safter = $r->summaryafter ? json_decode($r->summaryafter, true) : null;
            $checkedat = (int) ($summary['checked_at'] ?? 0);
            if ($checkedat > 0) {
                $y = (int) date('Y', $checkedat);
                if (!isset($years[$y])) {
                    $years[$y] = $blank();
                }
                $years[$y]['checked']++;
                $years[$y]['months_checked'][(int) date('n', $checkedat) - 1]++;
            }
            $remat = 0;
            if (($r->state ?: '') === 'remediated') {
                $remat = (int) $r->remediatedat ?: (int) $r->timemodified;
            }
            if ($remat > 0) {
                $y = (int) date('Y', $remat);
                if (!isset($years[$y])) {
                    $years[$y] = $blank();
                }
                $years[$y]['remediated']++;
                $years[$y]['months_remediated'][(int) date('n', $remat) - 1]++;
                $years[$y]['pages'] += (int) ($r->pages ?? 0);
                $before = isset($summary['score']) ? (int) $summary['score'] : null;
                $after = isset($safter['score']) ? (int) $safter['score'] : null;
                if ($before !== null && $after !== null) {
                    $years[$y]['score_before_sum'] += $before;
                    $years[$y]['score_after_sum'] += $after;
                    $years[$y]['score_n']++;
                }
                $failb = (int) ($summary['issues']['failed'] ?? 0);
                $faila = (int) ($safter['issues']['failed'] ?? 0);
                $years[$y]['fixed'] += max(0, $failb - $faila);
            }
        }
        foreach ($years as &$s) {
            $s['avg_before'] = $s['score_n'] ? (int) round($s['score_before_sum'] / $s['score_n']) : null;
            $s['avg_after'] = $s['score_n'] ? (int) round($s['score_after_sum'] / $s['score_n']) : null;
        }
        unset($s);
        krsort($years);
        return $years;
    }

    /* ---------------------------------------------------------------- */
    /* PDF AI Remediation tab                                            */
    /* ---------------------------------------------------------------- */

    /**
     * The PDF AI Remediation explainer tab (static content).
     *
     * @return string
     */
    protected static function ai_tab(): string {
        // Full explainer tab ported from the approved sketch (#tab-ai).
        // Pure server-rendered static HTML — no JS. The payment/onboarding
        // wizard is still in development and must NOT be added; the "Start
        // Auto Remediation" tab links to the hosted registration instead.
        global $CFG;
        $logowb = $CFG->wwwroot . '/admin/tool/eqwpdfcheck/pix/logowb.jpg';
        if (edition::is_free()) {
            return self::ai_tab_free($logowb);
        }
        $h = '<div class="eqw-explain">';
        $h .= '<div class="eqw-explain-grid">';

        $h .= '<div class="eqw-exp-copy">';
        $h .= '<h2>' . self::e(get_string('ai_heading', 'tool_eqwpdfcheck'))
            . ' <span class="eqw-badge eqw-ok">' . get_string('badge_new', 'tool_eqwpdfcheck') . '</span></h2>';
        $h .= '<p>' . get_string('ai_whatdoes', 'tool_eqwpdfcheck') . '</p>';
        $h .= '<p>' . get_string('ai_underhood', 'tool_eqwpdfcheck') . '</p>';
        $h .= '<p>' . get_string('ai_stamped', 'tool_eqwpdfcheck') . '</p>';
        $h .= '<p>' . get_string('ai_payment', 'tool_eqwpdfcheck') . '</p>';
        $h .= '</div>';

        $h .= '<div class="eqw-convpanel">';
        $h .= '<h3>' . get_string('ai_whatsend', 'tool_eqwpdfcheck') . '</h3>';
        $h .= '<div class="eqw-conv-in">'
            . '<span class="eqw-ftok eqw-f-pdf"><b>' . get_string('pill_pdf', 'tool_eqwpdfcheck')
            . '</b><small>' . get_string('ai_ftok_pdf_sub', 'tool_eqwpdfcheck') . '</small></span>'
            . '<span class="eqw-ftok eqw-f-doc"><b>' . get_string('ai_ftok_doc', 'tool_eqwpdfcheck')
            . '</b><small>' . get_string('pill_word', 'tool_eqwpdfcheck') . '</small></span>'
            . '<span class="eqw-ftok eqw-f-ppt"><b>' . get_string('ai_ftok_ppt', 'tool_eqwpdfcheck')
            . '</b><small>' . get_string('pill_ppt', 'tool_eqwpdfcheck') . '</small></span>'
            . '<span class="eqw-ftok eqw-f-xls"><b>' . get_string('ai_ftok_xls', 'tool_eqwpdfcheck')
            . '</b><small>' . get_string('pill_xls', 'tool_eqwpdfcheck') . '</small></span>'
            . '</div>';
        $h .= '<div class="eqw-conv-arrow">↓<small>' . get_string('ai_youget', 'tool_eqwpdfcheck') . '</small></div>';
        $h .= '<div class="eqw-conv-out"><img class="eqw-conv-logo" src="' . self::e($logowb) . '" alt="">'
            . '<span><b>' . get_string('ai_accessiblepdf', 'tool_eqwpdfcheck') . '</b>'
            . '<small>' . get_string('ai_pdfsub', 'tool_eqwpdfcheck') . '</small></span></div>';
        $h .= '<div class="eqw-limits">'
            . '<div class="eqw-limit"><b>' . get_string('ai_limit_size_val', 'tool_eqwpdfcheck') . '</b>'
            . '<span>' . get_string('ai_limit_size_lbl', 'tool_eqwpdfcheck') . '</span></div>'
            . '<div class="eqw-limit"><b>' . get_string('ai_limit_pages_val', 'tool_eqwpdfcheck') . '</b>'
            . '<span>' . get_string('ai_limit_pages_lbl', 'tool_eqwpdfcheck') . '</span></div>'
            . '</div>';
        $h .= '<h3 style="margin:13px 0 8px">' . get_string('ai_meetsstandards', 'tool_eqwpdfcheck') . '</h3>';
        $h .= '<div class="eqw-stds"><span class="eqw-std">PDF/UA</span><span class="eqw-std">WCAG 2.2</span>'
            . '<span class="eqw-std">Section 508</span><span class="eqw-std">ADA</span>'
            . '<span class="eqw-std">EN 301 549</span></div>';
        $h .= '<p class="eqw-conv-foot">' . get_string('ai_convfoot', 'tool_eqwpdfcheck') . '</p>';
        $h .= '</div>';

        $h .= '</div>'; // Closes .eqw-explain-grid.

        $h .= '<div class="eqw-steps">';
        $h .= '<div class="eqw-step"><b><span class="eqw-step-n">1</span>' . get_string('ai_step1', 'tool_eqwpdfcheck') . '</b>'
            . '<small>' . get_string('ai_step1_sub', 'tool_eqwpdfcheck') . '</small></div>';
        $h .= '<div class="eqw-step"><b><span class="eqw-step-n">2</span>' . get_string('ai_step2', 'tool_eqwpdfcheck') . '</b>'
            . '<small>' . get_string('ai_step2_sub', 'tool_eqwpdfcheck') . '</small></div>';
        $h .= '<div class="eqw-step"><b><span class="eqw-step-n">3</span>' . get_string('ai_step3', 'tool_eqwpdfcheck') . '</b>'
            . '<small>' . get_string('ai_step3_sub', 'tool_eqwpdfcheck') . '</small></div>';
        $h .= '<div class="eqw-step"><b><span class="eqw-step-n">4</span>' . get_string('ai_step4', 'tool_eqwpdfcheck') . '</b>'
            . '<small>' . get_string('ai_step4_sub', 'tool_eqwpdfcheck') . '</small></div>';
        $h .= '</div>';

        $h .= '<div class="eqw-handles"><span class="eqw-handles-label">' . get_string('ai_handles', 'tool_eqwpdfcheck') . '</span>'
            . '<span class="eqw-hchip">' . get_string('ai_handle_multicol', 'tool_eqwpdfcheck') . '</span>'
            . '<span class="eqw-hchip">' . get_string('ai_handle_scanned', 'tool_eqwpdfcheck') . '</span>'
            . '<span class="eqw-hchip">' . get_string('ai_handle_forms', 'tool_eqwpdfcheck') . '</span>'
            . '<span class="eqw-hchip">' . get_string('ai_handle_tables', 'tool_eqwpdfcheck') . '</span>'
            . '<span class="eqw-hchip">' . get_string('ai_handle_reports', 'tool_eqwpdfcheck') . '</span>'
            . '<span class="eqw-hchip">' . get_string('ai_handle_engineering', 'tool_eqwpdfcheck') . '</span>'
            . '<span class="eqw-hchip">' . get_string('ai_handle_brochures', 'tool_eqwpdfcheck') . '</span>'
            . '<span class="eqw-hchip">' . get_string('ai_handle_protected', 'tool_eqwpdfcheck') . '</span>'
            . '</div>';
        $h .= '</div>'; // Closes .eqw-explain.

        $h .= '<div class="eqw-card">';
        $h .= '<div class="eqw-sugg-head"><strong>' . get_string('ai_examples', 'tool_eqwpdfcheck') . '</strong>'
            . '<span class="eqw-sugg-note">' . get_string('ai_examples_note', 'tool_eqwpdfcheck') . '</span></div>';
        $h .= '<div class="eqw-sugg"><span class="eqw-kind eqw-k-fig"><span>▥</span>'
            . get_string('ai_kind_images', 'tool_eqwpdfcheck') . '</span>'
            . '<div><h4>' . get_string('ai_img_h', 'tool_eqwpdfcheck') . '</h4>'
            . '<p class="eqw-why">' . get_string('ai_img_why', 'tool_eqwpdfcheck') . '</p>'
            . '<p class="eqw-alt-sample">' . get_string('ai_img_sample', 'tool_eqwpdfcheck') . '</p></div>'
            . '<span class="eqw-badge eqw-ok">' . get_string('ai_badge_written', 'tool_eqwpdfcheck') . '</span></div>';
        $h .= '<div class="eqw-sugg"><span class="eqw-kind eqw-k-str"><span>¶</span>'
            . get_string('ai_kind_structure', 'tool_eqwpdfcheck') . '</span>'
            . '<div><h4>' . get_string('ai_str_h', 'tool_eqwpdfcheck') . '</h4>'
            . '<p class="eqw-why">' . get_string('ai_str_why', 'tool_eqwpdfcheck') . '</p></div>'
            . '<span class="eqw-badge eqw-ok">' . get_string('ai_badge_built', 'tool_eqwpdfcheck') . '</span></div>';
        $h .= '<div class="eqw-sugg"><span class="eqw-kind eqw-k-str"><span>≡</span>'
            . get_string('ai_kind_content', 'tool_eqwpdfcheck') . '</span>'
            . '<div><h4>' . get_string('ai_con_h', 'tool_eqwpdfcheck') . '</h4>'
            . '<p class="eqw-why">' . get_string('ai_con_why', 'tool_eqwpdfcheck') . '</p></div>'
            . '<span class="eqw-badge eqw-ok">' . get_string('ai_badge_tagged', 'tool_eqwpdfcheck') . '</span></div>';
        $h .= '<div class="eqw-sugg"><span class="eqw-kind eqw-k-tbl"><span>▦</span>'
            . get_string('ai_kind_tables', 'tool_eqwpdfcheck') . '</span>'
            . '<div><h4>' . get_string('ai_tbl_h', 'tool_eqwpdfcheck') . '</h4>'
            . '<p class="eqw-why">' . get_string('ai_tbl_why', 'tool_eqwpdfcheck') . '</p></div>'
            . '<span class="eqw-badge eqw-warn">' . get_string('ai_badge_flagged', 'tool_eqwpdfcheck') . '</span></div>';
        $h .= '<div class="eqw-sugg"><span class="eqw-kind eqw-k-meta"><span>◐</span>'
            . get_string('ai_kind_metadata', 'tool_eqwpdfcheck') . '</span>'
            . '<div><h4>' . get_string('ai_meta_h', 'tool_eqwpdfcheck') . '</h4>'
            . '<p class="eqw-why">' . get_string('ai_meta_why', 'tool_eqwpdfcheck') . '</p></div>'
            . '<span class="eqw-badge eqw-ok">' . get_string('ai_badge_set', 'tool_eqwpdfcheck') . '</span></div>';
        $h .= '<div class="eqw-sugg"><span class="eqw-kind eqw-k-rev"><span>◎</span>'
            . get_string('ai_kind_report', 'tool_eqwpdfcheck') . '</span>'
            . '<div><h4>' . get_string('ai_rep_h', 'tool_eqwpdfcheck') . '</h4>'
            . '<p class="eqw-why">' . get_string('ai_rep_why', 'tool_eqwpdfcheck') . '</p></div>'
            . '<span class="eqw-badge eqw-ok">' . get_string('ai_badge_everydoc', 'tool_eqwpdfcheck') . '</span></div>';
        $h .= '</div>';
        return $h;
    }

    /**
     * The free-edition AI tab: an informational page about the full
     * remediation product (Shopify FreeAiPage parity). Same layout classes as
     * the full tab so it looks native, but the copy carries NO prices, NO
     * credits and NO purchase CTAs — only one plain "Learn more at
     * login.equalweb.com" link (LEARN_MORE_URL, deliberately NOT SIGNUP_URL).
     *
     * @param string $logowb URL of the white-on-blue EqualWeb logo.
     * @return string
     */
    protected static function ai_tab_free(string $logowb): string {
        $h = '<div class="eqw-explain">';
        $h .= '<div class="eqw-explain-grid">';

        $h .= '<div class="eqw-exp-copy">';
        $h .= '<h2>' . self::e(get_string('ai_heading', 'tool_eqwpdfcheck'))
            . ' <span class="eqw-badge eqw-ok">' . get_string('ai_free_badge', 'tool_eqwpdfcheck') . '</span></h2>';
        $h .= '<p>' . get_string('ai_free_whatdoes', 'tool_eqwpdfcheck') . '</p>';
        $h .= '<p>' . get_string('ai_free_result', 'tool_eqwpdfcheck') . '</p>';
        $h .= '<p>' . get_string('ai_free_separate', 'tool_eqwpdfcheck') . '</p>';
        $h .= '<p><a href="' . self::e(self::LEARN_MORE_URL) . '" target="_blank" rel="noopener">'
            . get_string('ai_free_learnmore', 'tool_eqwpdfcheck') . self::icon('external') . '</a></p>';
        $h .= '</div>';

        $h .= '<div class="eqw-convpanel">';
        $h .= '<h3>' . get_string('ai_free_whatsend', 'tool_eqwpdfcheck') . '</h3>';
        $h .= '<div class="eqw-conv-in">'
            . '<span class="eqw-ftok eqw-f-pdf"><b>' . get_string('pill_pdf', 'tool_eqwpdfcheck')
            . '</b><small>' . get_string('ai_ftok_pdf_sub', 'tool_eqwpdfcheck') . '</small></span>'
            . '<span class="eqw-ftok eqw-f-doc"><b>' . get_string('ai_ftok_doc', 'tool_eqwpdfcheck')
            . '</b><small>' . get_string('pill_word', 'tool_eqwpdfcheck') . '</small></span>'
            . '<span class="eqw-ftok eqw-f-ppt"><b>' . get_string('ai_ftok_ppt', 'tool_eqwpdfcheck')
            . '</b><small>' . get_string('pill_ppt', 'tool_eqwpdfcheck') . '</small></span>'
            . '<span class="eqw-ftok eqw-f-xls"><b>' . get_string('ai_ftok_xls', 'tool_eqwpdfcheck')
            . '</b><small>' . get_string('pill_xls', 'tool_eqwpdfcheck') . '</small></span>'
            . '</div>';
        $h .= '<div class="eqw-conv-arrow">↓<small>' . get_string('ai_free_youget', 'tool_eqwpdfcheck') . '</small></div>';
        $h .= '<div class="eqw-conv-out"><img class="eqw-conv-logo" src="' . self::e($logowb) . '" alt="">'
            . '<span><b>' . get_string('ai_accessiblepdf', 'tool_eqwpdfcheck') . '</b>'
            . '<small>' . get_string('ai_pdfsub', 'tool_eqwpdfcheck') . '</small></span></div>';
        $h .= '<div class="eqw-limits">'
            . '<div class="eqw-limit"><b>' . get_string('ai_limit_size_val', 'tool_eqwpdfcheck') . '</b>'
            . '<span>' . get_string('ai_limit_size_lbl', 'tool_eqwpdfcheck') . '</span></div>'
            . '<div class="eqw-limit"><b>' . get_string('ai_limit_pages_val', 'tool_eqwpdfcheck') . '</b>'
            . '<span>' . get_string('ai_limit_pages_lbl', 'tool_eqwpdfcheck') . '</span></div>'
            . '</div>';
        $h .= '<h3 style="margin:13px 0 8px">' . get_string('ai_meetsstandards', 'tool_eqwpdfcheck') . '</h3>';
        $h .= '<div class="eqw-stds"><span class="eqw-std">PDF/UA</span><span class="eqw-std">WCAG 2.2</span>'
            . '<span class="eqw-std">Section 508</span><span class="eqw-std">ADA</span>'
            . '<span class="eqw-std">EN 301 549</span></div>';
        $h .= '<p class="eqw-conv-foot">' . get_string('ai_free_convfoot', 'tool_eqwpdfcheck') . '</p>';
        $h .= '</div>';

        $h .= '</div>'; // Closes .eqw-explain-grid.

        $h .= '<div class="eqw-steps">';
        $h .= '<div class="eqw-step"><b><span class="eqw-step-n">1</span>' . get_string('ai_step1', 'tool_eqwpdfcheck') . '</b>'
            . '<small>' . get_string('ai_step1_sub', 'tool_eqwpdfcheck') . '</small></div>';
        $h .= '<div class="eqw-step"><b><span class="eqw-step-n">2</span>' . get_string('ai_step2', 'tool_eqwpdfcheck') . '</b>'
            . '<small>' . get_string('ai_step2_sub', 'tool_eqwpdfcheck') . '</small></div>';
        $h .= '<div class="eqw-step"><b><span class="eqw-step-n">3</span>' . get_string('ai_step3', 'tool_eqwpdfcheck') . '</b>'
            . '<small>' . get_string('ai_step3_sub', 'tool_eqwpdfcheck') . '</small></div>';
        $h .= '<div class="eqw-step"><b><span class="eqw-step-n">4</span>' . get_string('ai_step4', 'tool_eqwpdfcheck') . '</b>'
            . '<small>' . get_string('ai_step4_sub', 'tool_eqwpdfcheck') . '</small></div>';
        $h .= '</div>';

        $h .= '</div>'; // Closes .eqw-explain.
        return $h;
    }

    /* ---------------------------------------------------------------- */
    /* Start Auto Remediation tab                                        */
    /* ---------------------------------------------------------------- */


    /* ---------------------------------------------------------------- */
    /* Permissions guide tab                                             */
    /* ---------------------------------------------------------------- */


    /* ---------------------------------------------------------------- */
    /* Settings tab                                                      */
    /* ---------------------------------------------------------------- */
}
