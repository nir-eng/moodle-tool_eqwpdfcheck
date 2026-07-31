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
 * HTTP client for the EqualWeb PDF API (port of the WordPress/Drupal client).
 *
 * Two backends share the same endpoint shape:
 *  - Free checks:  the EqualWeb check proxy (Cloudflare Worker) holds the shared
 *    key and only allows check-type processing.
 *  - Remediation:  login.equalweb.com directly, with the site owner's personal
 *    API key (stored encrypted in plugin config).
 *
 * @package    tool_eqwpdfcheck
 * @copyright  2026 EqualWeb
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class client {
    /** @var string EqualWeb API base. */
    const API_BASE = 'https://login.equalweb.com/api/v2';

    /** @var string Default shared Cloudflare Worker proxy. */
    const WORKER_URL = 'https://eqw-pdf-plugins-free-check.wcag2worker.workers.dev';

    /**
     * @var array Retention periods offered for autoRemove, in days. EqualWeb
     * takes an INTEGER 1..365 here and has no "never" value: "never" is the
     * removal checkbox being off, which sends no autoRemove at all. The
     * settings dropdown and audit_defaults() are both built from this set, so
     * the screen can never offer a day count the API would reject.
     */
    const AUTO_REMOVE_DAYS = [1, 30, 60, 90, 365];

    /** @var string Base URL endpoints are appended to. */
    protected $base;

    /** @var array Auth headers added to every request. */
    protected $headers;

    /** @var string Which EqualWeb backend this client talks to: 'key' | 'proxy'. */
    protected $backend;

    /**
     * @var bool True when the last get_status() PROVED the document is gone
     * (the list came back fine and the doc was not in it).
     */
    protected $docgone = false;

    /**
     * Build a client bound to one backend, with auth headers sent on every request.
     *
     * @param string $base
     * @param array $headers
     * @param string $backend 'key' (personal API key) or 'proxy' (shared Worker).
     */
    protected function __construct(string $base, array $headers, string $backend = 'key') {
        $this->base = rtrim($base, '/');
        $this->backend = $backend;
        // Installation/tenant attribution on EVERY call (multi-tenant
        // research, 2026-07): usage must be attributable server-side before
        // per-tenant quotas, reports or reseller sub-keys can ever exist.
        // Vanilla Moodle has no tenancy, so the tenant field carries a stable
        // hash of the site identifier (never the identifier itself — it also
        // salts the key encryption); a real tenant id can replace it later
        // without a wire-format change.
        try {
            global $CFG;
            $release = (string) (\core_plugin_manager::instance()->get_plugin_info('tool_eqwpdfcheck')->release ?? '');
            $attribution = [
                'X-Eqw-Site' => self::current_host(),
                'X-Eqw-Platform' => 'moodle',
                'X-Eqw-Tenant' => !empty($CFG->siteidentifier) ? sha1($CFG->siteidentifier) : self::current_host(),
                'X-Eqw-Plugin' => $release,
            ];
        } catch (\Throwable $e) {
            // Attribution must never break an API call (e.g. CLI install).
            $attribution = ['X-Eqw-Platform' => 'moodle'];
        }
        $this->headers = $headers + $attribution;
    }

    /**
     * Worker base URL (overridable in config.php via $CFG->tool_eqwpdfcheck_worker_url).
     *
     * @return string
     */
    public static function worker_url(): string {
        global $CFG;
        if (!empty($CFG->tool_eqwpdfcheck_worker_url)) {
            return $CFG->tool_eqwpdfcheck_worker_url;
        }
        return self::WORKER_URL;
    }

    /**
     * Platform identifier sent to the proxy on registration.
     *
     * @return string
     */
    public static function platform(): string {
        global $CFG;
        return !empty($CFG->tool_eqwpdfcheck_platform) ? $CFG->tool_eqwpdfcheck_platform : 'moodle';
    }

    /**
     * Client for free accessibility checks.
     *
     * A site WITH a personal key checks directly against EqualWeb (unlimited,
     * no proxy limit — checks cost no credits); only keyless sites use the
     * rate-limited shared proxy. Mirrors WordPress v2.1.1.
     *
     * @param string|null $backend Force the backend a RUNNING check started on:
     *   'key' (personal key) or 'proxy' (shared Worker). A doc id only exists in
     *   the account it was uploaded to, so a poll MUST come back to the same
     *   one: picking freshly would send it to the other account after the admin
     *   saves or removes a key mid-flight, and the run would look vanished.
     *   null = pick fresh (key first), which is what starting a new check does.
     * @return static|string Client, or an error message.
     */
    public static function for_check(?string $backend = null) {
        $key = self::get_personal_key();
        if ($backend === 'key') {
            if ($key === '') {
                return get_string('err_checkkeygone', 'tool_eqwpdfcheck');
            }
            return new static(self::API_BASE, ['x-a11y-api-key' => $key], 'key');
        }
        if ($backend === null && $key !== '') {
            return new static(self::API_BASE, ['x-a11y-api-key' => $key], 'key');
        }

        $token = get_config('tool_eqwpdfcheck', 'sitetoken');

        // Token is bound to the host it was issued for; re-register when the
        // site moves (throttled so a failing proxy does not fire every check).
        $registered = get_config('tool_eqwpdfcheck', 'sitehost');
        $current = self::current_host();
        $throttle = (int) get_config('tool_eqwpdfcheck', 'reregthrottle');
        if ($token && $registered && $registered !== $current && $throttle < time() - 600) {
            set_config('reregthrottle', time(), 'tool_eqwpdfcheck');
            self::register_site();
            $token = get_config('tool_eqwpdfcheck', 'sitetoken');
        }

        if ($token) {
            return new static(self::worker_url() . '/check', [
                'x-eqw-site-token' => $token,
                'x-eqw-site-host' => $current,
            ], 'proxy');
        }
        if ($backend === 'proxy') {
            return get_string('err_checkproxygone', 'tool_eqwpdfcheck');
        }
        return get_string('err_notregistered', 'tool_eqwpdfcheck');
    }

    /**
     * Client for paid remediation (personal key required).
     *
     * Always the personal key, so a remediation is never at risk of the
     * mid-flight backend switch for_check() has to guard against.
     *
     * @return static|string
     */
    public static function for_remediation() {
        $key = self::get_personal_key();
        if ($key === '') {
            return self::key_decrypt_failed()
                ? get_string('err_keydecrypt', 'tool_eqwpdfcheck')
                : get_string('err_needkey', 'tool_eqwpdfcheck');
        }
        return new static(self::API_BASE, ['x-a11y-api-key' => $key], 'key');
    }

    /**
     * Which backend this client talks to: 'key' (personal API key) or 'proxy' (shared Worker).
     *
     * @return string
     */
    public function backend(): string {
        return $this->backend;
    }

    /**
     * Public URL of the browser-viewable report for a document.
     *
     * @param string $docid
     * @return string
     */
    public static function report_url(string $docid): string {
        return 'https://login.equalweb.com/reports/pdf/' . rawurlencode($docid);
    }

    /**
     * The stored audit defaults, normalised. The single reader: the settings
     * screen and the remediation payload must never disagree about what is set.
     *
     * A corrupt or scalar stored value decodes to null, which must come back as
     * an array: every caller treats the result as one, and PHP 8 fatals on
     * `null + [...]`.
     *
     * @return array
     */
    public static function audit_defaults(): array {
        $decoded = json_decode((string) get_config('tool_eqwpdfcheck', 'auditdefaults'), true);
        $ad = (is_array($decoded) ? $decoded : []) + [
            'add_signature' => true,
            'fix_contrast' => false,
            // Whether EqualWeb ever removes the document. The stored key is the
            // legacy name: it used to carry the immediate-delete flag, which is
            // never sent now (see audit_extra()).
            'delete_after_audit' => true,
            'auto_remove' => 30,
            'auto_replace' => false,
        ];
        // A day count the dropdown cannot offer (a value stored by an older
        // version, a crafted call) would leave the screen showing one retention
        // while the API is told another, so it falls back to the default.
        $days = (int) $ad['auto_remove'];
        if (!in_array($days, self::AUTO_REMOVE_DAYS, true)) {
            $days = 30;
        }

        return [
            'add_signature' => (bool) $ad['add_signature'],
            'fix_contrast' => (bool) $ad['fix_contrast'],
            'delete_after_audit' => (bool) $ad['delete_after_audit'],
            'auto_remove' => $days,
            'auto_replace' => (bool) $ad['auto_replace'],
        ];
    }

    /**
     * The `extra` payload for start_process('tag'), in EqualWeb's key names.
     *
     * @return array
     */
    public static function audit_extra(): array {
        $ad = self::audit_defaults();

        // The booleans are sent EXPLICITLY, false included. An omitted false
        // does not read as "off" to EqualWeb, it reads as "unspecified", so
        // EqualWeb applies its OWN default and the user's choice never arrives.
        $extra = [
            'addSignature' => $ad['add_signature'],
            'fixContrast' => $ad['fix_contrast'],
            // NEVER true. deleteAfterAudit destroys the audited output the
            // moment the audit completes (the download 404s seconds later),
            // erasing the remediated file before it can be downloaded or
            // applied. Retention is governed by autoRemove (days); immediate
            // deletion is not offered.
            'deleteAfterAudit' => false,
        ];

        // The checkbox decides WHETHER the document is ever removed; autoRemove
        // only says AFTER HOW LONG. With the checkbox off, no removal
        // instruction is sent at all, whatever the day count holds.
        if ($ad['delete_after_audit'] && $ad['auto_remove'] > 0) {
            $extra['autoRemove'] = min(365, max(1, $ad['auto_remove']));
        }

        return $extra;
    }

    /* ---------------------------------------------------------------- */
    /* Endpoints                                                         */
    /* ---------------------------------------------------------------- */

    /**
     * Upload a local file. Returns the document ID (also the report ID).
     * Uses Guzzle multipart with a RAW filename (never URL-encoded).
     *
     * @param string $path
     * @param string $filename
     * @param string $mime The file's REAL mime type — office documents
     *                     (Word/PowerPoint/Excel) must not be mislabeled as
     *                     PDF or the conversion on EqualWeb's side goes wrong.
     * @return string Doc ID, or "ERR:<message>".
     */
    public function upload_file(string $path, string $filename, string $mime = 'application/pdf') {
        if (!file_exists($path)) {
            return 'ERR:' . get_string('err_uploadnotfound', 'tool_eqwpdfcheck');
        }
        // An unchecked fopen() is a money bug: Guzzle turns a false handle into
        // an EMPTY stream without complaining, so an unreadable file uploads as
        // 0 bytes, the process starts, and the credit is spent on nothing.
        clearstatcache(true, $path);
        $handle = (int) @filesize($path) > 0 ? @fopen($path, 'r') : false;
        if (!$handle) {
            return 'ERR:' . get_string('err_uploadunreadable', 'tool_eqwpdfcheck');
        }
        try {
            $res = (new \core\http_client(['timeout' => 120]))->request('PUT', $this->base . '/docs/upload?mode=sync', [
                'headers' => $this->headers,
                'multipart' => [[
                    'name' => 'file',
                    'contents' => $handle,
                    'filename' => $filename,
                    'headers' => ['Content-Type' => $mime],
                ]],
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            return 'ERR:' . $e->getMessage();
        }
        $body = json_decode((string) $res->getBody(), true);
        if ($res->getStatusCode() >= 300 || empty($body['id'])) {
            return 'ERR:' . ($body['error'] ?? ('Upload failed (HTTP ' . $res->getStatusCode() . ').'));
        }
        return $body['id'];
    }

    /**
     * Start processing: 'check' (free report) or 'tag' (remediation).
     *
     * @param string $docid
     * @param string $type
     * @param array $extra
     * @return true|string
     */
    public function start_process(string $docid, string $type, array $extra = []) {
        $res = $this->request('POST', '/docs/audit', [
            'json' => array_merge($extra, ['files' => [$docid], 'type' => $type]),
        ]);
        if (is_string($res)) {
            return $res;
        }
        // EqualWeb can accept the call (HTTP 202) yet not run the process —
        // e.g. {"results":"TEST MODE - no audit performed"} when the key's
        // account is in test mode. Treating that as success would wedge the
        // row in 'checking'/'remediating' forever, so fail fast instead.
        if (isset($res['results']) && is_string($res['results']) && preg_match('/test\s*mode|no\s*audit/i', $res['results'])) {
            return get_string('err_testmode', 'tool_eqwpdfcheck');
        }
        return true;
    }

    /**
     * Processing status of a document ('ready' -> processing -> 'done').
     *
     * @param string $docid
     * @return array|string The document record, or an error message.
     */
    public function get_status(string $docid) {
        $this->docgone = false;
        $docs = $this->request('GET', '/docs');
        if (is_string($docs)) {
            // Transport / upstream error: the document's fate is UNKNOWN, so
            // doc_gone() stays false and the caller must leave the row alone.
            return $docs;
        }
        foreach ((array) $docs as $doc) {
            if (isset($doc['reportID']) && $doc['reportID'] === $docid) {
                return $doc;
            }
        }
        // The doc_gone() flag (just set) is the machine sentinel callers branch
        // on; this returned string is only ever shown, never inspected.
        $this->docgone = true;
        return get_string('err_docgone', 'tool_eqwpdfcheck');
    }

    /**
     * True only when the last get_status() PROVED the document is gone: the
     * account's document list came back fine and the doc simply was not in it.
     *
     * Callers reset a running row on this, which discards a PAID remediation.
     * Sniffing the error text for "not found" would fire on any unrelated
     * upstream message that happened to contain those words, so the signal is
     * this flag, set at the one place that can actually establish the fact.
     */
    public function doc_gone(): bool {
        return $this->docgone;
    }

    /**
     * Fetch a report ('original', 'audited' or 'both').
     *
     * @param string $reportid
     * @param string $type
     * @return array|string
     */
    public function get_report(string $reportid, string $type = 'original') {
        return $this->request('GET', '/docs/report/' . rawurlencode($reportid) . '?type=' . rawurlencode($type));
    }

    /**
     * Download a document to a local file. $original=true fetches the ORIGINAL
     * (pre-remediation) file EqualWeb still stores — used for Restore without a
     * local backup. Default fetches the audited/remediated version.
     *
     * @param string $reportid
     * @param string $dest
     * @param bool $original
     * @return true|string
     */
    public function download_to_file(string $reportid, string $dest, bool $original = false) {
        $url = $this->base . '/docs/download/file/' . rawurlencode($reportid) . ($original ? '?original=true' : '');
        try {
            $res = (new \core\http_client(['timeout' => 180]))->request('GET', $url, [
                'headers' => $this->headers,
                'sink' => $dest,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            @unlink($dest);
            return $e->getMessage();
        }
        if ($res->getStatusCode() !== 200) {
            @unlink($dest);
            return 'Download failed (HTTP ' . $res->getStatusCode() . ').';
        }
        return true;
    }

    /**
     * Credit balance for this client's key.
     *
     * @return float|string
     */
    public function balance() {
        $res = $this->request('GET', '/docs/info/balance');
        if (is_string($res)) {
            return $res;
        }
        return isset($res['results']) ? (float) $res['results'] : get_string('err_balanceresp', 'tool_eqwpdfcheck');
    }

    /**
     * Validate an arbitrary key WITHOUT saving it. Returns balance or error.
     *
     * @param string $key
     * @return float|string
     */
    public static function validate_key(string $key) {
        $client = new static(self::API_BASE, ['x-a11y-api-key' => $key]);
        return $client->balance();
    }

    /* ---------------------------------------------------------------- */
    /* Site registration with the check proxy                            */
    /* ---------------------------------------------------------------- */

    /**
     * Register this site with the check proxy (domain ownership handshake).
     *
     * @return true|string
     */
    public static function register_site() {
        global $CFG;
        $nonce = bin2hex(random_bytes(16));
        set_config('regnonce', $nonce, 'tool_eqwpdfcheck');
        set_config('regnonceexpires', time() + 300, 'tool_eqwpdfcheck');

        $siteurl = $CFG->wwwroot;
        $verifyurl = $CFG->wwwroot . '/admin/tool/eqwpdfcheck/verify.php';

        try {
            $res = (new \core\http_client(['timeout' => 30]))->request('POST', self::worker_url() . '/register', [
                'json' => [
                    'site_url' => $siteurl,
                    'verify_url' => $verifyurl,
                    'nonce' => $nonce,
                    'platform' => self::platform(),
                ],
                'http_errors' => false,
            ]);
            $body = json_decode((string) $res->getBody(), true);
            $code = $res->getStatusCode();
            $err = null;
        } catch (\Throwable $e) {
            $body = null;
            $code = 0;
            $err = $e->getMessage();
        }
        unset_config('regnonce', 'tool_eqwpdfcheck');
        unset_config('regnonceexpires', 'tool_eqwpdfcheck');

        if ($code !== 200 || empty($body['site_token'])) {
            $msg = $body['error'] ?? ($err ?? ('HTTP ' . $code));
            set_config('regerror', $msg, 'tool_eqwpdfcheck');
            return $msg;
        }
        set_config('sitetoken', $body['site_token'], 'tool_eqwpdfcheck');
        set_config('sitehost', self::current_host(), 'tool_eqwpdfcheck');
        unset_config('regerror', 'tool_eqwpdfcheck');
        unset_config('reregthrottle', 'tool_eqwpdfcheck');
        return true;
    }

    /**
     * Host of this site's wwwroot.
     *
     * @return string
     */
    public static function current_host(): string {
        global $CFG;
        return (string) parse_url($CFG->wwwroot, PHP_URL_HOST);
    }

    /* ---------------------------------------------------------------- */
    /* Personal API key storage (encrypted at rest)                      */
    /* ---------------------------------------------------------------- */

    /**
     * The decrypted personal API key, or '' when none is stored / it cannot be decrypted.
     *
     * @return string
     */
    public static function get_personal_key(): string {
        $stored = (string) get_config('tool_eqwpdfcheck', 'apikey');
        return $stored === '' ? '' : self::decrypt($stored);
    }

    /**
     * Store (or clear) the personal API key.
     *
     * @param string $key
     * @return true|string true, or an error message when the key cannot be
     *   stored SECURELY. It is then not stored at all: a readable secret at rest
     *   is worse than no key, and the caller surfaces the error so the admin
     *   knows the save did not happen.
     */
    public static function set_personal_key(string $key) {
        $key = trim($key);
        if ($key === '') {
            unset_config('apikey', 'tool_eqwpdfcheck');
            return true;
        }
        $encrypted = self::encrypt($key);
        if ($encrypted === null) {
            return get_string('err_nostorekey', 'tool_eqwpdfcheck');
        }
        set_config('apikey', $encrypted, 'tool_eqwpdfcheck');
        return true;
    }

    /**
     * True when a key is stored but cannot be decrypted (e.g. site salt rotated).
     *
     * @return bool
     */
    public static function key_decrypt_failed(): bool {
        $stored = (string) get_config('tool_eqwpdfcheck', 'apikey');
        return $stored !== '' && self::decrypt($stored) === '';
    }

    /**
     * The site secret every key derivation is salted with.
     *
     * @return string
     */
    protected static function secret(): string {
        global $CFG;
        return isset($CFG->siteidentifier) ? $CFG->siteidentifier : ($CFG->wwwroot ?? 'eqw');
    }

    /**
     * AES key of the LEGACY 'enc:' format.
     *
     * Never used to write anything any more, but existing installs still hold
     * 'enc:' values and MUST keep decrypting or they silently lose their key on
     * upgrade.
     */
    protected static function encryption_key(): string {
        return hash('sha256', self::secret() . 'eqw-pdf', true);
    }

    /**
     * Cipher key of the current 'enc2:' format.
     *
     * @return string
     */
    protected static function enc2_cipher_key(): string {
        return hash('sha256', self::secret() . 'eqw-pdf-enc', true);
    }

    /**
     * MAC key of the current 'enc2:' format — derived from the same secret, but distinct from the cipher key.
     *
     * @return string
     */
    protected static function enc2_mac_key(): string {
        return hash('sha256', self::secret() . 'eqw-pdf-mac', true);
    }

    /**
     * Encrypt-then-MAC: 'enc2:' + base64( iv | ciphertext | HMAC-SHA256(iv|ciphertext) ).
     *
     * The bare AES-CBC of the old 'enc:' format carried no integrity tag, so a
     * tampered value decrypted to garbage instead of being rejected.
     *
     * @param string $plain
     * @return string|null null when the key cannot be encrypted. There is no
     *   cleartext fallback: the pre-2.6.3 'plain:' branch wrote the API key to
     *   the config table in the clear, which is never an acceptable outcome.
     */
    protected static function encrypt(string $plain): ?string {
        if (!function_exists('openssl_encrypt')) {
            return null;
        }
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', self::enc2_cipher_key(), OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            return null;
        }
        $mac = hash_hmac('sha256', $iv . $cipher, self::enc2_mac_key(), true);
        return 'enc2:' . base64_encode($iv . $cipher . $mac);
    }

    /**
     * Read any format the plugin has ever written. Only writing changed in
     * 2.6.3 — 'plain:' and 'enc:' values still decrypt so an upgrade never
     * costs an install its key. The next save re-stores it as 'enc2:'.
     */
    protected static function decrypt(string $stored): string {
        if (strpos($stored, 'enc2:') === 0) {
            if (!function_exists('openssl_decrypt')) {
                return '';
            }
            $raw = (string) base64_decode(substr($stored, 5), true);
            // 16-byte IV + at least one 16-byte AES block + 32-byte MAC.
            if (strlen($raw) < 64) {
                return '';
            }
            $mac = substr($raw, -32);
            $body = substr($raw, 0, -32);
            // Verify BEFORE decrypting, in constant time.
            if (!hash_equals(hash_hmac('sha256', $body, self::enc2_mac_key(), true), $mac)) {
                return '';
            }
            $out = openssl_decrypt(
                substr($body, 16),
                'aes-256-cbc',
                self::enc2_cipher_key(),
                OPENSSL_RAW_DATA,
                substr($body, 0, 16)
            );
            return $out === false ? '' : $out;
        }
        if (strpos($stored, 'plain:') === 0) {
            return (string) base64_decode(substr($stored, 6));
        }
        if (strpos($stored, 'enc:') === 0 && function_exists('openssl_decrypt')) {
            $raw = (string) base64_decode(substr($stored, 4));
            $out = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', self::encryption_key(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
            return $out === false ? '' : $out;
        }
        return '';
    }

    /* ---------------------------------------------------------------- */

    /**
     * Low-level request helper.
     *
     * @param string $method
     * @param string $path
     * @param array $options
     * @return array|string Decoded JSON, or error message.
     */
    protected function request(string $method, string $path, array $options = []) {
        $options += ['http_errors' => false];
        $options['headers'] = ($options['headers'] ?? []) + $this->headers;
        try {
            $res = (new \core\http_client(['timeout' => 60]))->request($method, $this->base . $path, $options);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
        $code = $res->getStatusCode();
        $body = json_decode((string) $res->getBody(), true);
        if ($code < 200 || $code >= 300) {
            return is_array($body) && isset($body['error']) ? $body['error'] : ('EqualWeb API error (HTTP ' . $code . ').');
        }
        return is_array($body) ? $body : [];
    }
}
