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
 * Classification & triage engine (v1, Moodle signals).
 *
 * Assigns every managed document a category + priority score from metadata the
 * plugin already indexes (course + course-module linkage, path, dates), plus
 * admin-supplied location tags. Design rules
 * (docs/classification-triage-spec-2026-07.md, ported from the Drupal engine):
 *  - The engine NEVER excludes a document or calls anything "exempt" —
 *    categories set PRIORITY only. Only an explicit, logged, reversible admin
 *    action (ignore) hides a row, and analytics still count it.
 *  - A signal that was not evaluated is unknown, never a silent negative.
 *  - Admin tags (per-location or per-file) are first-class and always win over
 *    automatic rules; they are recorded with who/when.
 *  - Every result carries the matched rules — the "why" behind the rank.
 *
 * Moodle v1 signals (cheap, all from the state table — no per-file API calls):
 *  - course visible AND course-module visible -> 'public' ("Active course
 *    material"): the file is live in a course learners can reach.
 *  - hidden course/module, or not attached to a live activity -> 'internal'.
 *  - filepath/filearea named like an archive, or (opt-in) file older than N
 *    years while NOT active course material -> 'archive'.
 *  - 'service' (required to obtain/access a service) is NOT machine-detectable —
 *    it comes only from admin tagging; filename heuristics merely SUGGEST it on
 *    the Classification screen.
 *
 * Location tags are keyed by course: 'course:{id}' (children could be filearea
 * paths, omitted here — the course is the natural Moodle location).
 *
 * @package    tool_eqwpdfcheck
 * @copyright  2026 EqualWeb
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class classification {
    /** Category => [label, swatch color, table flag, priority for admin tags]. */
    const CATS = [
        'service' => ['label' => 'Required for a service', 'color' => '#a12727', 'flag' => 'Fix first', 'prio' => 94],
        'public' => ['label' => 'Active course material', 'color' => '#9a4b00', 'flag' => 'Fix first', 'prio' => 90],
        'internal' => ['label' => 'Internal only', 'color' => '#5b6472', 'flag' => 'In scope if used', 'prio' => 38],
        'archive' => ['label' => 'Archive', 'color' => '#a7aaad', 'flag' => 'Possibly exempt — confirm', 'prio' => 12],
    ];

    /**
     * Translated display label for a category key (cat_<key>). The English
     * value in CATS is kept only as a developer-facing reference/fallback; every
     * display site goes through here so the label is a single source of truth.
     * An unknown key returns the raw key (mirrors the old ?? fallbacks).
     *
     * @param string $key
     * @return string
     */
    public static function cat_label(string $key): string {
        return isset(self::CATS[$key]) ? get_string('cat_' . $key, 'tool_eqwpdfcheck') : $key;
    }

    /**
     * Translated fix-first flag for a category key (catflag_<key>).
     *
     * @param string $key
     * @return string
     */
    public static function cat_flag(string $key): string {
        return isset(self::CATS[$key]) ? get_string('catflag_' . $key, 'tool_eqwpdfcheck') : $key;
    }

    /** Filename pattern that SUGGESTS "required for a service" to the admin. */
    const SERVICE_HINT = '/form|request|claim|application|enrol|apply/i';

    /**
     * Archive path rule: a filepath or filearea segment literally named like an
     * archive. Anchored to whole segments so "gold"/"folder" never false-match.
     */
    const ARCHIVE_RE = '#(?:^|/)(?:archive|archived|old|backup|backups)(?:/|$)#i';

    /* ---------------------------------------------------------------- */
    /* Config (archive age threshold + location tags)                    */
    /* ---------------------------------------------------------------- */

    /**
     * Age (in years) after which a NOT-active-course file is auto-classified
     * Archive. 0 = OFF, and OFF is the DEFAULT: the plugin never decides an age
     * cutoff for the customer — they opt in and pick the number themselves.
     * (Folder/area names like /archive|/old still classify; and age NEVER
     * overrides active use — a file live in a visible course + activity stays
     * out of Archive at any age, the legal "currently used" line.)
     */
    public static function archive_years(): int {
        $raw = get_config('tool_eqwpdfcheck', 'cls_archive_years');
        // Unset => the 2-year default (2026-07-20 product decision); an explicit 0 = off.
        $years = ($raw === false || $raw === '') ? 2 : (int) $raw;
        return max(0, min(15, $years));
    }

    /**
     * Store the auto-archive age threshold (clamped to 0..15 years).
     *
     * @param int $years
     */
    public static function set_archive_years(int $years): void {
        set_config('cls_archive_years', max(0, min(15, $years)), 'tool_eqwpdfcheck');
    }

    /**
     * All saved location tags: 'course:{id}' => [cat, by, at].
     *
     * @return array
     */
    public static function location_tags(): array {
        $raw = get_config('tool_eqwpdfcheck', 'cls_locations');
        $decoded = $raw ? json_decode($raw, true) : [];
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Save/replace one location tag (logged: who + when).
     *
     * @param string $prefix
     * @param string $cat
     * @param string $by
     */
    public static function tag_location(string $prefix, string $cat, string $by): void {
        $tags = self::location_tags();
        $tags[$prefix] = ['cat' => $cat, 'by' => $by, 'at' => time()];
        set_config('cls_locations', json_encode($tags), 'tool_eqwpdfcheck');
    }

    /**
     * Remove a saved location tag.
     *
     * @param string $prefix
     */
    public static function clear_location(string $prefix): void {
        $tags = self::location_tags();
        unset($tags[$prefix]);
        set_config('cls_locations', json_encode($tags), 'tool_eqwpdfcheck');
    }

    /**
     * Location key for a state row: the owning course (0 = site/no course).
     *
     * @param \stdClass $row
     * @return string
     */
    public static function location_key(\stdClass $row): string {
        return 'course:' . (int) $row->courseid;
    }

    /**
     * Human label for a location key: the course fullname (cached, bounded).
     *
     * @param string $prefix
     * @return string
     */
    public static function location_label(string $prefix): string {
        global $DB;
        static $names = [];
        if ($prefix === 'course:0') {
            return get_string('loc_sitefiles', 'tool_eqwpdfcheck');
        }
        if (preg_match('/^course:(\d+)$/', $prefix, $m)) {
            $cid = (int) $m[1];
            if (!array_key_exists($cid, $names)) {
                $c = $DB->get_record('course', ['id' => $cid], 'id, fullname');
                // Raw fullname — every render path escapes it (self::e / s()).
                $names[$cid] = $c ? (string) $c->fullname : get_string('loc_coursefallback', 'tool_eqwpdfcheck', $cid);
            }
            return $names[$cid];
        }
        return $prefix;
    }

    /**
     * True when a location key belongs to a course present in the given rows.
     *
     * @param string $prefix
     * @param \stdClass[] $rows
     * @return bool
     */
    public static function location_exists(string $prefix, array $rows): bool {
        foreach ($rows as $r) {
            if (self::location_key($r) === $prefix) {
                return true;
            }
        }
        return false;
    }

    /* ---------------------------------------------------------------- */
    /* Active-course signal (batched — never one query per file)          */
    /* ---------------------------------------------------------------- */

    /**
     * For a set of context ids: contextid => TRUE when it is a MODULE context
     * whose course-module is visible (cm.visible, i.e. available to learners —
     * a "stealth" activity that is available but unlisted still counts), FALSE
     * when it is a module context but the module is hidden. Contexts that are
     * not module contexts are simply absent from the map. One query.
     */
    protected static function cm_visibility(array $ctxids): array {
        global $DB;
        $out = [];
        if (!$ctxids) {
            return $out;
        }
        [$insql, $params] = $DB->get_in_or_equal($ctxids, SQL_PARAMS_NAMED, 'ctx');
        $params['modlevel'] = CONTEXT_MODULE;
        $sql = "SELECT ctx.id AS contextid, cm.visible AS visible
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid
                 WHERE ctx.contextlevel = :modlevel AND ctx.id $insql";
        foreach ($DB->get_records_sql($sql, $params) as $rec) {
            $out[(int) $rec->contextid] = ((int) $rec->visible === 1);
        }
        return $out;
    }

    /* ---------------------------------------------------------------- */
    /* Classify                                                          */
    /* ---------------------------------------------------------------- */

    /**
     * Classify a list of state rows. Returns fileid => [cat, prio, src, rules,
     * ignored]. src: 'user' (admin tag, wins) | 'auto'. Automatic results are
     * computed per request and never persisted — only admin overrides and
     * ignores live in the state table, so nothing can go stale. All course and
     * course-module visibility is resolved in TWO batched queries, never one
     * per file.
     *
     * @param \stdClass[] $rows state rows (raw, from page_renderer::pdf_rows()).
     * @return array
     */
    public static function classify_all(array $rows): array {
        global $DB;

        // Batch: distinct course + context ids across the whole set.
        $courseids = [];
        $ctxids = [];
        foreach ($rows as $r) {
            if ((int) $r->courseid > 0) {
                $courseids[(int) $r->courseid] = true;
            }
            if (!empty($r->contextid)) {
                $ctxids[(int) $r->contextid] = true;
            }
        }
        $coursevis = $courseids
            ? $DB->get_records_list('course', 'id', array_keys($courseids), '', 'id, visible')
            : [];
        $cmvis = self::cm_visibility(array_keys($ctxids));

        $tags = self::location_tags();
        $years = self::archive_years();
        $now = time();
        $out = [];

        foreach ($rows as $r) {
            $fileid = (int) $r->fileid;
            $ignored = !empty($r->ignored);

            // 1) Per-file admin tag beats everything.
            if (!empty($r->cls_cat) && isset(self::CATS[$r->cls_cat]) && ($r->cls_src ?? '') === 'user') {
                $rules = (isset($r->cls_rules) && $r->cls_rules !== null && $r->cls_rules !== '')
                    ? json_decode($r->cls_rules, true) : null;
                $out[$fileid] = [
                    'cat' => $r->cls_cat,
                    'prio' => (int) ($r->cls_prio ?? self::CATS[$r->cls_cat]['prio']),
                    'src' => 'user',
                    'rules' => is_array($rules) ? $rules : [get_string('cls_rule_taggedadmin', 'tool_eqwpdfcheck')],
                    'ignored' => $ignored,
                ];
                continue;
            }

            // 2) Admin location (course) tag — everything in the course inherits.
            $lockey = self::location_key($r);
            if (isset($tags[$lockey]) && isset(self::CATS[$tags[$lockey]['cat'] ?? ''])) {
                $loc = $tags[$lockey];
                $cat = $loc['cat'];
                $out[$fileid] = [
                    'cat' => $cat,
                    'prio' => self::CATS[$cat]['prio'],
                    'src' => 'user',
                    'rules' => [
                        // Raw text — the render path escapes each rule (self::e).
                        get_string(
                            !empty($loc['at']) ? 'cls_rule_inherited' : 'cls_rule_inherited_nodate',
                            'tool_eqwpdfcheck',
                            (object) [
                                'location' => self::location_label($lockey),
                                'by' => $loc['by'] ?? get_string('cls_anadmin', 'tool_eqwpdfcheck'),
                                'date' => !empty($loc['at']) ? userdate((int) $loc['at'], '%b %e, %Y') : '',
                            ]
                        ),
                    ],
                    'ignored' => $ignored,
                ];
                continue;
            }

            // 3) Automatic rules.
            $cid = (int) $r->courseid;
            $coursevisible = $cid > 0 && isset($coursevis[$cid]) && (int) $coursevis[$cid]->visible === 1;
            $ctxid = (int) $r->contextid;
            $hascm = array_key_exists($ctxid, $cmvis);
            $cmvisible = $hascm && $cmvis[$ctxid] === true;
            $active = $coursevisible && $cmvisible;

            // Why it is NOT active course material (drives the internal/archive
            // rule text). Hidden course/module is a different fact from
            // "not in an activity at all", and the wording says which.
            if ($cid > 0 && !$coursevisible) {
                $notactive = get_string('cls_rule_hidden', 'tool_eqwpdfcheck');
            } else if ($hascm && !$cmvisible) {
                $notactive = get_string('cls_rule_hidden', 'tool_eqwpdfcheck');
            } else {
                $notactive = get_string('cls_rule_notattached', 'tool_eqwpdfcheck');
            }

            // Age applies only when the admin opted in ($years 0 = off, the
            // default). Age NEVER overrides active use.
            $created = (int) $r->filetimecreated;
            $old = $years > 0 && $created > 0 && $created < $now - $years * 31536000;
            $archpath = self::archive_path_match((string) ($r->filepath ?? ''), (string) ($r->filearea ?? ''));

            if (($archpath || $old) && !$active) {
                $rules = [];
                if ($archpath) {
                    $rules[] = get_string('cls_rule_archpath', 'tool_eqwpdfcheck');
                }
                if ($old) {
                    $rules[] = get_string(
                        $years > 1 ? 'cls_rule_old_many' : 'cls_rule_old_one',
                        'tool_eqwpdfcheck',
                        $years
                    );
                }
                $rules[] = $notactive;
                $out[$fileid] = [
                    'cat' => 'archive',
                    'prio' => self::CATS['archive']['prio'],
                    'src' => 'auto',
                    'rules' => $rules,
                    'ignored' => $ignored,
                ];
            } else if ($active) {
                $out[$fileid] = [
                    'cat' => 'public',
                    'prio' => self::CATS['public']['prio'],
                    'src' => 'auto',
                    'rules' => [get_string('cls_rule_active', 'tool_eqwpdfcheck')],
                    'ignored' => $ignored,
                ];
            } else {
                $out[$fileid] = [
                    'cat' => 'internal',
                    'prio' => self::CATS['internal']['prio'],
                    'src' => 'auto',
                    'rules' => [$notactive],
                    'ignored' => $ignored,
                ];
            }
        }
        return $out;
    }

    /**
     * True when a filepath or filearea segment is literally named like an archive.
     *
     * @param string $filepath
     * @param string $filearea
     * @return bool
     */
    protected static function archive_path_match(string $filepath, string $filearea): bool {
        $filepath = $filepath !== '' ? $filepath : '/';
        return (bool) preg_match(self::ARCHIVE_RE, $filepath)
            || (bool) preg_match(self::ARCHIVE_RE, '/' . $filearea . '/');
    }

    /**
     * Location summary for the Classification screen. Groups the files by
     * course; per location: doc count, ignored count, current tag (or null),
     * and a name-based suggestion for untagged ones. Untagged first, biggest
     * first — the highest-impact decision on top.
     *
     * @param \stdClass[] $rows
     * @param array $cls fileid => classification (from classify_all()).
     * @return array
     */
    public static function locations(array $rows, array $cls): array {
        $tags = self::location_tags();
        $locs = [];
        foreach ($rows as $r) {
            $fileid = (int) $r->fileid;
            $key = self::location_key($r);
            if (!isset($locs[$key])) {
                $locs[$key] = ['prefix' => $key, 'count' => 0, 'ignored' => 0, 'service_hits' => 0, 'archive_hits' => 0];
            }
            $locs[$key]['count']++;
            if (!empty($cls[$fileid]['ignored'])) {
                $locs[$key]['ignored']++;
            }
            if (preg_match(self::SERVICE_HINT, (string) $r->filename)) {
                $locs[$key]['service_hits']++;
            }
            if (($cls[$fileid]['cat'] ?? '') === 'archive' && ($cls[$fileid]['src'] ?? '') === 'auto') {
                $locs[$key]['archive_hits']++;
            }
        }
        foreach ($locs as $key => &$loc) {
            $loc['label'] = self::location_label($key);
            $loc['tag'] = $tags[$key] ?? null;
            $loc['suggestion'] = null;
            if (!$loc['tag']) {
                if ($loc['service_hits'] > 0) {
                    $loc['suggestion'] = [
                        'cat' => 'service',
                        'why' => get_string('sugg_service', 'tool_eqwpdfcheck', $loc['service_hits']),
                    ];
                } else if ($loc['archive_hits'] === $loc['count'] && $loc['count'] > 0) {
                    $loc['suggestion'] = ['cat' => 'archive', 'why' => get_string('sugg_archive', 'tool_eqwpdfcheck')];
                }
            }
        }
        unset($loc);
        uasort($locs, function ($a, $b) {
            $ua = $a['tag'] ? 1 : 0;
            $ub = $b['tag'] ? 1 : 0;
            return $ua === $ub ? ($b['count'] <=> $a['count']) : ($ua <=> $ub);
        });
        return $locs;
    }
}
