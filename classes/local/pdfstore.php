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
 * Per-file EqualWeb state (indexed DB table) + bounded PDF metadata extraction.
 *
 * @package    tool_eqwpdfcheck
 * @copyright  2026 EqualWeb
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pdfstore {
    /** @var string State table. */
    const TABLE = 'tool_eqwpdfcheck_state';

    /** @var string[] Columns stored as JSON. */
    const JSON_FIELDS = ['summary', 'summaryafter', 'report', 'reportafter', 'cls_rules'];

    /**
     * Raw state row for a file, or null.
     *
     * @param int $fileid
     * @return \stdClass|null
     */
    public static function get_row(int $fileid): ?\stdClass {
        global $DB;
        $rec = $DB->get_record(self::TABLE, ['fileid' => $fileid]);
        return $rec ?: null;
    }

    /**
     * Decode a state row's JSON fields into arrays; returns a plain object.
     *
     * @param \stdClass $row
     * @return \stdClass
     */
    public static function decode(\stdClass $row): \stdClass {
        foreach (self::JSON_FIELDS as $f) {
            $row->$f = (isset($row->$f) && $row->$f !== null && $row->$f !== '')
                ? json_decode($row->$f, true) : null;
        }
        return $row;
    }

    /**
     * Merge values into a file's state row (creating it if needed).
     * Array values for JSON columns are encoded automatically.
     *
     * @param int $fileid
     * @param array $values
     */
    public static function upsert(int $fileid, array $values): void {
        global $DB;
        $data = [];
        foreach ($values as $k => $v) {
            if (in_array($k, self::JSON_FIELDS, true)) {
                $data[$k] = ($v === null) ? null : json_encode($v);
            } else {
                $data[$k] = $v;
            }
        }
        $data['timemodified'] = time();
        $existing = $DB->get_record(self::TABLE, ['fileid' => $fileid], 'id');
        if ($existing) {
            $data['id'] = $existing->id;
            $DB->update_record(self::TABLE, (object) $data);
        } else {
            $data['fileid'] = $fileid;
            $DB->insert_record(self::TABLE, (object) $data);
        }
    }

    /**
     * Null out specific columns for a file.
     *
     * @param int $fileid
     * @param array $keys
     */
    public static function forget(int $fileid, array $keys): void {
        global $DB;
        $rec = $DB->get_record(self::TABLE, ['fileid' => $fileid]);
        if (!$rec) {
            return;
        }
        foreach ($keys as $k) {
            $rec->$k = null;
        }
        $rec->timemodified = time();
        $DB->update_record(self::TABLE, $rec);
    }

    /**
     * Remove a file's whole state row.
     *
     * @param int $fileid
     */
    public static function delete(int $fileid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['fileid' => $fileid]);
    }

    /* ---------------------------------------------------------------- */
    /* PDF metadata extraction (bounded reads — no full file load)        */
    /* ---------------------------------------------------------------- */

    /**
     * Read the first + last 1 MB of a stored file. PDF metadata (Info/XMP,
     * /Lang, /Pages /Count) lives in the header/trailer, so this suffices while
     * a huge PDF can no longer exhaust the memory limit.
     */
    protected static function read_slice(\stored_file $file): string {
        $maxhead = 1048576;
        $maxtail = 1048576;
        $size = (int) $file->get_filesize();
        if ($size <= 0) {
            return '';
        }
        $fh = $file->get_content_file_handle();
        if (!$fh) {
            return '';
        }
        $head = (string) fread($fh, (int) min($size, $maxhead));
        $tail = '';
        if ($size > $maxhead) {
            $taillen = (int) min($size - $maxhead, $maxtail);
            if (fseek($fh, -$taillen, SEEK_END) === 0) {
                $tail = (string) fread($fh, $taillen);
            }
        }
        fclose($fh);
        return $head . $tail;
    }

    /**
     * Document title from PDF metadata, cached. Empty when none.
     *
     * @param int $fileid
     * @param \stored_file|null $file
     * @return string
     */
    public static function doc_title(int $fileid, ?\stored_file $file = null): string {
        $rec = self::get_row($fileid);
        if ($rec && $rec->title !== null) {
            return $rec->title === 'none' ? '' : $rec->title;
        }
        $title = '';
        $file = $file ?? get_file_storage()->get_file_by_id($fileid);
        if ($file && !$file->is_directory()) {
            $data = self::read_slice($file);
            if (preg_match('#<dc:title>.*?<rdf:li[^>]*>(.*?)</rdf:li>#s', $data, $m)) {
                $title = trim(html_to_text($m[1], 0, false));
            }
            if ($title === '' && preg_match('#/Title\s*\(((?:[^()\\\\]|\\\\.)*)\)#s', $data, $m)) {
                $raw = stripcslashes($m[1]);
                if (strpos($raw, "\xFE\xFF") === 0) {
                    $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
                }
                $title = trim($raw);
            }
            if ($title === '' && preg_match('#/Title\s*<([0-9A-Fa-f\s]+)>#', $data, $m)) {
                $raw = pack('H*', preg_replace('/\s+/', '', $m[1]));
                if (strpos($raw, "\xFE\xFF") === 0) {
                    $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
                }
                $title = trim($raw);
            }
        }
        $title = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $title);
        $title = \core_text::substr($title, 0, 255);
        self::upsert($fileid, ['title' => ($title === '' ? 'none' : $title)]);
        return $title;
    }

    /**
     * Document language (/Lang or XMP dc:language), cached.
     *
     * @param int $fileid
     * @param \stored_file|null $file
     * @return string
     */
    public static function doc_lang(int $fileid, ?\stored_file $file = null): string {
        $rec = self::get_row($fileid);
        if ($rec && $rec->lang !== null) {
            return $rec->lang === 'none' ? '' : $rec->lang;
        }
        $lang = '';
        $file = $file ?? get_file_storage()->get_file_by_id($fileid);
        if ($file && !$file->is_directory()) {
            $data = self::read_slice($file);
            if (preg_match('#/Lang\s*\(([A-Za-z0-9\-]{2,10})\)#', $data, $m)) {
                $lang = $m[1];
            } else if (preg_match('#<dc:language>.*?<rdf:li[^>]*>([A-Za-z0-9\-]{2,10})</rdf:li>#s', $data, $m)) {
                $lang = $m[1];
            }
        }
        self::upsert($fileid, ['lang' => ($lang === '' ? 'none' : $lang)]);
        return $lang;
    }

    /**
     * Page count, cached. NULL when undeterminable. Prefers the /Pages /Count
     * (survives bounded reads); the EqualWeb check overrides it later.
     *
     * Safe to call for ANY supported doctype. Local extraction is PDF-only, as
     * the regexes below mean nothing to an office binary, but the cached value
     * is served for every doctype: a poll writes EqualWeb's numberOfPages into
     * this same column, so an office document does have a page count once it has
     * been through the service.
     */
    public static function page_count(int $fileid, ?\stored_file $file = null): ?int {
        $rec = self::get_row($fileid);
        if ($rec && $rec->pages !== null) {
            return ((int) $rec->pages) > 0 ? (int) $rec->pages : null;
        }
        $count = 0;
        $file = $file ?? get_file_storage()->get_file_by_id($fileid);
        // Local extraction is PDF-only - the regexes below mean nothing to an
        // office binary. An office row therefore caches the 0 sentinel, which
        // reads back as "unknown" and stops the lookup repeating on every
        // render, until the poll overwrites it with EqualWeb's real count.
        if ($file && !$file->is_directory() && $file->get_mimetype() === 'application/pdf') {
            $data = self::read_slice($file);
            if (preg_match_all('#/Count\s+(\d+)#', $data, $m)) {
                $count = (int) max($m[1]);
            }
            if (!$count) {
                $count = (int) preg_match_all('#/Type\s*/Page\b(?!s)#', $data);
            }
        }
        self::upsert($fileid, ['pages' => $count]);
        return $count > 0 ? $count : null;
    }

    /**
     * Drop cached metadata so it re-extracts from the (new) file content.
     *
     * @param int $fileid
     */
    public static function reset_metadata(int $fileid): void {
        self::forget($fileid, ['title', 'lang', 'pages']);
    }
}
