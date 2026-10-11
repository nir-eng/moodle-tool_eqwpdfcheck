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
 * Free-check registration: what the EqualWeb service answered, and what to tell the admin.
 *
 * The service answers every failed registration with a short `code` (for example
 * verify_blocked) next to its `error` text. classify() reads the code first. An
 * older service, or a message stored by an older version of this plugin, has only
 * the error text; LEGACY maps those texts to the same codes.
 *
 * The classification functions (classify(), classify_stored(), legacy_rule(),
 * transport_sub(), check_code(), next_auto_at(), redact_proxy()) are pure: they
 * read no Moodle state, so they can be tested without a Moodle site. The
 * functions below them read the plugin configuration and the language strings.
 *
 * @package    tool_eqwpdfcheck
 * @copyright  2026 EqualWeb
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class registration {
    /** @var string Where admins are sent for help with a failed registration. */
    const SUPPORT_URL = 'https://www.equalweb.com/platform/pdf_tools/support.html';

    /**
     * @var array Every answer code, mapped to its automatic retry policy:
     * 'after_fix' (a person must change something first; only Try again runs),
     * 'backoff' (retried automatically, spaced out by BACKOFF) or 'at_reset'
     * (wait for the daily reset). null marks the codes whose policy is decided
     * per case (outbound_blocked, unknown) or that never come from registration
     * (token_invalid, daily_limit_reached come from a check).
     */
    const CODES = [
        'rate_limited' => 'at_reset',
        'bad_request' => 'after_fix',
        'not_https' => 'after_fix',
        'host_mismatch' => 'after_fix',
        'verify_redirect' => 'after_fix',
        'verify_auth' => 'after_fix',
        'verify_blocked' => 'after_fix',
        'verify_not_found' => 'after_fix',
        'verify_nonce_missing' => 'backoff',
        'verify_site_error' => 'backoff',
        'verify_tls' => 'after_fix',
        'verify_unreachable' => 'backoff',
        'verify_timeout' => 'backoff',
        'verify_not_json' => 'backoff',
        'verify_debug_output' => 'after_fix',
        'verify_nonce_mismatch' => 'backoff',
        'verify_http' => 'backoff',
        'service_error' => 'backoff',
        'token_invalid' => null,
        'daily_limit_reached' => null,
        'outbound_blocked' => null,
        'unknown' => null,
    ];

    /** @var int[] Automatic retry spacing after the 1st, 2nd, 3rd and every later consecutive failure, in seconds. */
    const BACKOFF = [600, 3600, 21600, 86400];

    /**
     * @var array How to read an answer that carries no code: ordered rules, first
     * match wins. A rule matches the exact error text, or an error that starts with
     * error_prefix and whose remainder matches error_regex (flags 'i' = ignore case).
     * Each error text belongs to one HTTP status, so a stored message (which has no
     * status) can be matched on the text alone. Any 'Proxy error:' text that does
     * not look like a timeout, TLS or network failure is the service's own problem.
     */
    const LEGACY = [
        ['status' => 429, 'error' => 'Too many registration attempts. Try again later.', 'code' => 'rate_limited'],
        ['status' => 400, 'error' => 'site_url, verify_url and nonce are required', 'code' => 'bad_request'],
        ['status' => 400, 'error' => 'site_url and verify_url must be https', 'code' => 'not_https'],
        ['status' => 400, 'error' => 'verify_url must be on the same host as site_url', 'code' => 'host_mismatch'],
        [
            'status' => 400,
            'error' => 'Domain verification failed: the verify URL must not redirect',
            'code' => 'verify_redirect',
        ],
        ['status' => 400, 'error' => 'Domain verification failed: nonce mismatch', 'code' => 'verify_nonce_mismatch'],
        [
            'status' => 400,
            'error_prefix' => 'Domain verification failed (HTTP ',
            'error_regex' => '^(401|407)\\)$',
            'code' => 'verify_auth',
        ],
        [
            'status' => 400,
            'error_prefix' => 'Domain verification failed (HTTP ',
            'error_regex' => '^(403|406|429|451)\\)$',
            'code' => 'verify_blocked',
        ],
        [
            'status' => 400,
            'error_prefix' => 'Domain verification failed (HTTP ',
            'error_regex' => '^(404|410)\\)$',
            'code' => 'verify_not_found',
        ],
        [
            'status' => 400,
            'error_prefix' => 'Domain verification failed (HTTP ',
            'error_regex' => '^(525|526)\\)$',
            'code' => 'verify_tls',
        ],
        [
            'status' => 400,
            'error_prefix' => 'Domain verification failed (HTTP ',
            'error_regex' => '^(52[0-4]|530)\\)$',
            'code' => 'verify_unreachable',
        ],
        [
            'status' => 400,
            'error_prefix' => 'Domain verification failed (HTTP ',
            'error_regex' => '^50[0-4]\\)$',
            'code' => 'verify_site_error',
        ],
        [
            'status' => 400,
            'error_prefix' => 'Domain verification failed (HTTP ',
            'error_regex' => '^[0-9]{3}\\)$',
            'code' => 'verify_http',
        ],
        [
            'status' => 502,
            'error_prefix' => 'Proxy error: ',
            'error_regex' => 'aborted due to timeout|timed out',
            'flags' => 'i',
            'code' => 'verify_timeout',
        ],
        [
            'status' => 502,
            'error_prefix' => 'Proxy error: ',
            'error_regex' => '\\bTLS\\b|\\bSSL\\b|certificate|handshake',
            'flags' => 'i',
            'code' => 'verify_tls',
        ],
        [
            'status' => 502,
            'error_prefix' => 'Proxy error: ',
            'error_regex' => 'network connection lost|connection (refused|reset|closed)|Fetch API cannot load|fetch failed'
                . '|ECONNREFUSED|ECONNRESET|ENOTFOUND|EAI_AGAIN|getaddrinfo|socket hang up',
            'flags' => 'i',
            'code' => 'verify_unreachable',
        ],
        ['status' => 502, 'error_prefix' => 'Proxy error: ', 'code' => 'service_error'],
        ['status' => 401, 'error' => 'Site not registered or invalid token', 'code' => 'token_invalid'],
        ['status' => 429, 'error' => 'Daily free-check limit reached for this site', 'code' => 'daily_limit_reached'],
    ];

    /** @var string[] Sub-reasons of outbound_blocked, taken from the transport error. */
    const OUTBOUND_SUBS = ['dns', 'connect', 'timeout', 'tls', 'proxy', 'policy'];

    /* ---------------------------------------------------------------- */
    /* Pure classification                                               */
    /* ---------------------------------------------------------------- */

    /**
     * Classify one answer to the registration request.
     *
     * @param int $status HTTP status of the answer; 0 when no HTTP answer arrived.
     * @param string $rawbody The answer body as received.
     * @param array $headers Answer headers, lowercase name => first value.
     * @param array $transport Only for status 0: 'errno' (cURL error number), 'message',
     *   'proxy' (bool, a proxy is configured) and 'sub' (a sub-reason already known).
     * @param int|null $now Current time (for the daily reset of rate_limited).
     * @return array ok, token, code, status, verify_status, error, hint, retry,
     *   resets_at (epoch seconds or null), sub (outbound only) and source
     *   ('code' = the service sent a code, 'legacy' = read from the error text,
     *   'client' = decided here).
     */
    public static function classify(
        int $status,
        string $rawbody,
        array $headers = [],
        array $transport = [],
        ?int $now = null
    ): array {
        $now = $now ?? time();
        $out = self::blank($status);
        if ($status === 0) {
            $sub = self::transport_sub($transport);
            $out['code'] = 'outbound_blocked';
            $out['sub'] = $sub;
            $out['retry'] = $sub === 'policy' ? 'after_fix' : 'backoff';
            $out['error'] = trim((string) ($transport['message'] ?? ''));
            return $out;
        }

        $text = trim(preg_replace('/^\xEF\xBB\xBF/', '', $rawbody));
        $body = json_decode($text, true);
        $body = is_array($body) ? $body : null;
        $error = ($body !== null && isset($body['error']) && is_string($body['error'])) ? $body['error'] : null;
        $out['error'] = $error ?? ('HTTP ' . $status);

        if ($status === 200) {
            $token = $body['site_token'] ?? null;
            if (is_string($token) && $token !== '') {
                $out['ok'] = true;
                $out['token'] = $token;
                $out['code'] = '';
                $out['retry'] = null;
                $out['error'] = '';
            }
            // Otherwise a 200 without a token: 'unknown'.
            return $out;
        }

        if ($body !== null && isset($body['code']) && is_string($body['code']) && $body['code'] !== '') {
            $out['source'] = 'code';
            $out['verify_status'] = (isset($body['verify_status']) && is_int($body['verify_status']))
                ? $body['verify_status'] : null;
            $code = $body['code'];
            if (array_key_exists($code, self::CODES) && self::CODES[$code] !== null) {
                $out['code'] = $code;
                $out['retry'] = self::CODES[$code];
            } else {
                // A code this version does not know yet: keep the service's own
                // English sentence to show, and its retry advice when valid.
                $out['code'] = 'unknown';
                $out['hint'] = (isset($body['hint']) && is_string($body['hint'])) ? self::clip($body['hint'], 300) : null;
                $retry = $body['retry'] ?? null;
                $out['retry'] = in_array($retry, ['after_fix', 'backoff', 'at_reset'], true) ? $retry : 'backoff';
            }
            if ($out['code'] === 'rate_limited' || $out['retry'] === 'at_reset') {
                $at = (isset($body['resets_at']) && is_string($body['resets_at'])) ? strtotime($body['resets_at']) : false;
                $out['resets_at'] = $at ?: self::next_utc_midnight($now);
            }
            return $out;
        }

        if ($error !== null) {
            $out['source'] = 'legacy';
            $rule = self::legacy_rule($status, $error);
            if ($rule !== null) {
                $code = $rule['code'];
            } else if ($status === 404 && $error === 'Not found') {
                // The service address answered, but not the registration route:
                // a wrong address override. Not the site's fault.
                $code = 'service_error';
            } else {
                $code = 'unknown';
            }
            $out['code'] = $code;
            $out['retry'] = self::CODES[$code] ?? 'backoff';
            $out['verify_status'] = self::verify_status_of($error);
            if ($code === 'rate_limited') {
                $out['resets_at'] = self::next_utc_midnight($now);
            }
            return $out;
        }

        // No JSON error at all, so the registration service itself did not answer.
        // A Cloudflare page in front of it is the service's problem; an egress
        // proxy's own refusal page is this server's network.
        $cfpage = isset($headers['cf-ray']) || preg_match('/error code:?\s*1\d{3}\b/i', $text);
        if ($cfpage) {
            $out['code'] = 'service_error';
        } else if ($status === 403 || $status === 407) {
            $out['code'] = 'outbound_blocked';
            $out['sub'] = 'proxy';
        } else if ($status >= 500) {
            $out['code'] = 'service_error';
        }
        $out['retry'] = 'backoff';
        return $out;
    }

    /**
     * Classify a registration error message stored without a code, by a version
     * of this plugin that predates the codes.
     *
     * Such a message is the service's error text, a transport error message, or
     * 'HTTP <status>' when the answer had no error text.
     *
     * @param string $error The stored message.
     * @param string[] $blockedtexts Texts that mean Moodle itself refused the request.
     * @return array Same shape as classify(), with source 'legacy'.
     */
    public static function classify_stored(string $error, array $blockedtexts = []): array {
        $out = self::blank(0);
        $out['source'] = 'legacy';
        $out['error'] = $error;
        $rule = self::legacy_rule(null, $error);
        if ($rule !== null) {
            $out['code'] = $rule['code'];
            $out['status'] = $rule['status'];
            $out['retry'] = self::CODES[$rule['code']] ?? 'backoff';
            $out['verify_status'] = self::verify_status_of($error);
            return $out;
        }
        if (preg_match('/^HTTP (\d{3})$/', $error, $m)) {
            $out['status'] = (int) $m[1];
            if ($out['status'] === 403 || $out['status'] === 407) {
                $out['code'] = 'outbound_blocked';
                $out['sub'] = 'proxy';
            } else if ($out['status'] >= 500) {
                $out['code'] = 'service_error';
            }
            return $out;
        }
        $blockedtexts[] = 'The URL is blocked.';
        if (preg_match('/cURL error \d+/i', $error) || in_array($error, $blockedtexts, true)) {
            // The next attempt re-checks the address, so even a stored 'blocked'
            // stays on automatic retries until a fresh answer says otherwise.
            $out['code'] = 'outbound_blocked';
            $sub = self::transport_sub(['message' => $error]);
            $out['sub'] = in_array($error, $blockedtexts, true) ? null : $sub;
            return $out;
        }
        return $out;
    }

    /**
     * The first LEGACY rule matching an error text.
     *
     * @param int|null $status HTTP status of the answer, or null to match on the text alone.
     * @param string $error The error text.
     * @return array|null The rule, or null when none matches.
     */
    public static function legacy_rule(?int $status, string $error): ?array {
        foreach (self::LEGACY as $rule) {
            if ($status !== null && $rule['status'] !== $status) {
                continue;
            }
            if (isset($rule['error'])) {
                if ($rule['error'] === $error) {
                    return $rule;
                }
                continue;
            }
            if (strpos($error, $rule['error_prefix']) !== 0) {
                continue;
            }
            if (!isset($rule['error_regex'])) {
                return $rule;
            }
            $rest = (string) substr($error, strlen($rule['error_prefix']));
            $pattern = '~' . $rule['error_regex'] . '~' . (($rule['flags'] ?? '') === 'i' ? 'i' : '');
            if (preg_match($pattern, $rest)) {
                return $rule;
            }
        }
        return null;
    }

    /**
     * The sub-reason of an outbound failure, from the transport error.
     *
     * @param array $transport 'sub' (already known), 'errno', 'message' and 'proxy' (bool).
     * @return string One of OUTBOUND_SUBS.
     */
    public static function transport_sub(array $transport): string {
        if (isset($transport['sub']) && in_array($transport['sub'], self::OUTBOUND_SUBS, true)) {
            return $transport['sub'];
        }
        $message = (string) ($transport['message'] ?? '');
        $errno = isset($transport['errno']) ? (int) $transport['errno'] : 0;
        if ($errno === 0 && preg_match('/cURL error (\d+)/i', $message, $m)) {
            $errno = (int) $m[1];
        }
        if ($errno === 5 || preg_match('/\bproxy\b/i', $message)) {
            return 'proxy';
        }
        if (!empty($transport['proxy']) && in_array($errno, [7, 28, 56], true)) {
            return 'proxy';
        }
        if ($errno === 6) {
            return 'dns';
        }
        if ($errno === 28) {
            return 'timeout';
        }
        if (in_array($errno, [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 83, 90, 91], true)) {
            return 'tls';
        }
        return 'connect';
    }

    /**
     * The code of a check-time answer from the free-check service, if it has one
     * this plugin explains: token_invalid or daily_limit_reached.
     *
     * @param int $status HTTP status.
     * @param array|null $body Decoded JSON body.
     * @return array|null ['code' => ..., 'limit' => ?int, 'resets_at' => ?int], or null.
     */
    public static function check_code(int $status, ?array $body): ?array {
        $code = ($body !== null && isset($body['code']) && is_string($body['code'])) ? $body['code'] : null;
        if ($code === null && $body !== null && isset($body['error']) && is_string($body['error'])) {
            $rule = self::legacy_rule($status, $body['error']);
            $code = $rule['code'] ?? null;
        }
        if ($code !== 'token_invalid' && $code !== 'daily_limit_reached') {
            return null;
        }
        $at = (isset($body['resets_at']) && is_string($body['resets_at'])) ? strtotime($body['resets_at']) : false;
        return [
            'code' => $code,
            'limit' => (isset($body['limit']) && is_int($body['limit'])) ? $body['limit'] : null,
            'resets_at' => $at ?: null,
        ];
    }

    /**
     * When the next automatic attempt may run after a failure.
     *
     * @param array $failure A failure as returned by failure(): code, retry, at,
     *   attempts and resets_at.
     * @return int|null Epoch seconds, or null when only a person (Try again) may retry.
     */
    public static function next_auto_at(array $failure): ?int {
        $at = (int) ($failure['at'] ?? 0);
        $retry = $failure['retry'] ?? 'backoff';
        if ($retry === 'after_fix') {
            return null;
        }
        if ($retry === 'at_reset') {
            return !empty($failure['resets_at']) ? (int) $failure['resets_at'] : self::next_utc_midnight($at);
        }
        $n = max(1, (int) ($failure['attempts'] ?? 1));
        return $at + self::BACKOFF[min($n, count(self::BACKOFF)) - 1];
    }

    /**
     * The verify status inside a 'Domain verification failed (HTTP n)' text.
     *
     * @param string $error
     * @return int|null
     */
    public static function verify_status_of(string $error): ?int {
        return preg_match('/^Domain verification failed \(HTTP (\d{3})\)$/', $error, $m) ? (int) $m[1] : null;
    }

    /**
     * The next 00:00 UTC after a moment.
     *
     * @param int $now Epoch seconds.
     * @return int
     */
    public static function next_utc_midnight(int $now): int {
        return (intdiv($now, 86400) + 1) * 86400;
    }

    /**
     * An outcome with every field present and nothing decided yet.
     *
     * @param int $status
     * @return array
     */
    protected static function blank(int $status): array {
        return [
            'ok' => false, 'token' => null, 'code' => 'unknown', 'status' => $status, 'verify_status' => null,
            'error' => '', 'hint' => null, 'retry' => 'backoff', 'resets_at' => null, 'sub' => null, 'source' => 'client',
        ];
    }

    /**
     * Cut a text to a length, on a character boundary.
     *
     * @param string $text
     * @param int $max
     * @return string
     */
    protected static function clip(string $text, int $max): string {
        $text = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text));
        return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
    }

    /**
     * Remove the proxy credentials from a transport error message.
     *
     * Moodle builds its proxy address with the user name and the password run
     * together in front of the host (http://userpasswordhost:port), so an error
     * such as 'Could not resolve proxy: ...' carries the password in clear text.
     * The user name on its own is left alone: it is not a secret, and replacing
     * it everywhere would garble ordinary words and addresses.
     *
     * @param string $text The message.
     * @param string $user Moodle's proxy user name.
     * @param string $password Moodle's proxy password.
     * @return string
     */
    public static function redact_proxy(string $text, string $user, string $password): string {
        if ($password === '') {
            return $text;
        }
        $map = [];
        foreach ([$user . $password, $user . ':' . $password, $password] as $secret) {
            foreach ([$secret, rawurlencode($secret), urlencode($secret)] as $form) {
                $map[$form] = '[proxy credentials]';
            }
        }
        // One pass, longest match first: a replaced part is never searched again.
        return strtr($text, $map);
    }

    /* ---------------------------------------------------------------- */
    /* Site state (plugin configuration)                                 */
    /* ---------------------------------------------------------------- */

    /**
     * The address the service fetches to confirm this site runs the plugin.
     *
     * @return string
     */
    public static function verify_url(): string {
        global $CFG;
        return $CFG->wwwroot . '/admin/tool/eqwpdfcheck/verify.php';
    }

    /**
     * Host name of the free-check service.
     *
     * @return string
     */
    public static function service_host(): string {
        return (string) parse_url(client::worker_url(), PHP_URL_HOST);
    }

    /**
     * Whether this site holds a working free-check registration, and if not, why.
     *
     * A stored token counts only when it was issued for this site's host and the
     * service has not refused it since the last successful registration:
     *  - after a move, or on a copy of another site, the token belongs to the old
     *    host, and the service refuses it for this one;
     *  - a token the service refused at check time (token_invalid) shows as a
     *    failure with Try again. Nothing is cleared automatically.
     *
     * @return array 'registered' (bool); 'movedfrom' (the host the stored token was
     *   issued for, when it is not this one, else null); 'failure' (what to show
     *   when not registered: the newer of the last failed registration and the
     *   last refusal of the token, or null when neither is on record).
     */
    public static function state(): array {
        $token = (string) get_config('tool_eqwpdfcheck', 'sitetoken');
        $movedfrom = self::moved_from();
        $rejectedat = ($token !== '' && $movedfrom === null) ? self::rejected_at() : 0;
        if ($token !== '' && $movedfrom === null && $rejectedat === 0) {
            return ['registered' => true, 'movedfrom' => null, 'failure' => null];
        }
        $failure = self::failure();
        if ($rejectedat > 0 && ($failure === null || (int) $failure['at'] < $rejectedat)) {
            $failure = self::rejection($rejectedat);
        }
        return ['registered' => false, 'movedfrom' => $movedfrom, 'failure' => $failure];
    }

    /**
     * The host the stored token was issued for, when that is not this site's host:
     * the site moved, or this is a copy of another site (for example a staging
     * clone of production). The service refuses such a token, so it is no
     * registration here. A token stored without a host, by an early version,
     * counts as this host's. Host names compare without case, as the service
     * compares them.
     *
     * @return string|null The other host, or null.
     */
    public static function moved_from(): ?string {
        $host = (string) get_config('tool_eqwpdfcheck', 'sitehost');
        if (
            (string) get_config('tool_eqwpdfcheck', 'sitetoken') === '' || $host === ''
                || strtolower($host) === strtolower(client::current_host())
        ) {
            return null;
        }
        return $host;
    }

    /**
     * When the service last refused the stored token, if that was after the last
     * successful registration.
     *
     * @return int Epoch seconds, or 0.
     */
    protected static function rejected_at(): int {
        $at = (int) get_config('tool_eqwpdfcheck', 'tokenrejectedat');
        return $at > (int) get_config('tool_eqwpdfcheck', 'regokat') ? $at : 0;
    }

    /**
     * The refusal of the stored token, in the shape of failure().
     *
     * @param int $at When the service refused it.
     * @return array
     */
    protected static function rejection(int $at): array {
        return [
            'code' => 'token_invalid', 'error' => 'Site not registered or invalid token', 'at' => $at,
            'status' => 401, 'verify_status' => null, 'retry' => 'after_fix', 'resets_at' => null, 'sub' => null,
            'hint' => null, 'attempts' => 0, 'trigger' => 'check', 'plugin' => null, 'platform' => null,
            'verify_url' => null, 'site_url' => null, 'worker_version' => null, 'source' => 'check',
        ];
    }

    /**
     * Record that the service refused this site's token at check time
     * (token_invalid). The token stays: the notice and System status show the
     * registration as no longer valid, with Try again, until a registration
     * succeeds or the service accepts the token again. A refusal of a token that
     * is no longer the stored one (a registration replaced it meanwhile) changes
     * nothing.
     *
     * @param string $token The token the refused request carried.
     */
    public static function note_rejection(string $token): void {
        if ($token !== '' && $token === (string) get_config('tool_eqwpdfcheck', 'sitetoken')) {
            set_config('tokenrejectedat', time(), 'tool_eqwpdfcheck');
        }
    }

    /**
     * Record that the service accepted this site's token, which ends an earlier
     * refusal (for example one seen while the service was still storing a new
     * registration).
     *
     * @param string $token The token the accepted request carried.
     */
    public static function note_acceptance(string $token): void {
        if (
            $token !== '' && (string) get_config('tool_eqwpdfcheck', 'tokenrejectedat') !== ''
                && $token === (string) get_config('tool_eqwpdfcheck', 'sitetoken')
        ) {
            unset_config('tokenrejectedat', 'tool_eqwpdfcheck');
        }
    }

    /**
     * The last failed registration, or null when none is on record.
     *
     * A message saved by an earlier version of the plugin has no code; it is
     * classified from its text, so an upgraded site explains it the same way.
     *
     * @return array|null code, error, at, status, verify_status, retry, resets_at,
     *   sub, hint, attempts, trigger, plugin, platform, verify_url, site_url,
     *   worker_version and source.
     */
    public static function failure(): ?array {
        // Scrubbed again on the way out: a message stored by an earlier version
        // can still carry the proxy password.
        $raw = client::scrub((string) get_config('tool_eqwpdfcheck', 'regerror'));
        if ($raw === '') {
            return null;
        }
        $code = (string) get_config('tool_eqwpdfcheck', 'regerrorclass');
        if ($code !== '' && array_key_exists($code, self::CODES)) {
            $detail = json_decode((string) get_config('tool_eqwpdfcheck', 'regerrordetail'), true);
            $f = (is_array($detail) ? $detail : []);
            $f['code'] = $code;
            $f['error'] = $raw;
            $f['at'] = (int) get_config('tool_eqwpdfcheck', 'regerrorat');
        } else {
            $f = self::classify_stored($raw, [get_string('curlsecurityurlblocked', 'admin')]);
            $f['at'] = (int) get_config('tool_eqwpdfcheck', 'regattempted');
            $f['attempts'] = 1;
        }
        return $f + [
            'status' => null, 'verify_status' => null, 'retry' => self::CODES[$code] ?? 'backoff',
            'resets_at' => null, 'sub' => null, 'hint' => null, 'attempts' => 1, 'trigger' => null,
            'plugin' => null, 'platform' => null, 'verify_url' => null, 'site_url' => null,
            'worker_version' => null, 'source' => 'code',
        ];
    }

    /**
     * Whether this site should register automatically now (after the admin page
     * loaded, or before a check when the site moved).
     *
     * Never more often than every 10 minutes. After a failure the retry policy
     * decides: a reason a person must fix waits for Try again (or a changed site
     * address), the rate limit waits for its reset, anything else backs off.
     * A token issued for another host counts as no registration. A token for this
     * host never registers automatically, also when the service refused it: that
     * waits for Try again.
     *
     * @return bool
     */
    public static function auto_due(): bool {
        global $CFG;
        if ((string) get_config('tool_eqwpdfcheck', 'sitetoken') !== '' && self::moved_from() === null) {
            return false;
        }
        $now = time();
        if ((int) get_config('tool_eqwpdfcheck', 'regattempted') > $now - 600) {
            return false;
        }
        $f = self::failure();
        if ($f === null) {
            return true;
        }
        if ($f['retry'] === 'after_fix') {
            return !empty($f['site_url']) && $f['site_url'] !== $CFG->wwwroot;
        }
        return $now >= (int) self::next_auto_at($f);
    }

    /**
     * Store the outcome of one registration attempt.
     *
     * @param array $outcome From classify().
     * @param array $context trigger, plugin, platform, verify_url, site_url, worker_version.
     */
    public static function record(array $outcome, array $context): void {
        if ($outcome['ok']) {
            set_config('sitetoken', $outcome['token'], 'tool_eqwpdfcheck');
            set_config('sitehost', client::current_host(), 'tool_eqwpdfcheck');
            set_config('regokat', time(), 'tool_eqwpdfcheck');
            $done = ['regerror', 'regerrorclass', 'regerrorat', 'regerrordetail', 'reregthrottle', 'tokenrejectedat'];
            foreach ($done as $name) {
                unset_config($name, 'tool_eqwpdfcheck');
            }
            return;
        }
        $previous = self::failure();
        $error = $outcome['error'] !== '' ? $outcome['error'] : ('HTTP ' . $outcome['status']);
        set_config('regerror', \core_text::substr($error, 0, 1000), 'tool_eqwpdfcheck');
        set_config('regerrorclass', $outcome['code'], 'tool_eqwpdfcheck');
        set_config('regerrorat', time(), 'tool_eqwpdfcheck');
        set_config('regerrordetail', json_encode([
            'status' => $outcome['status'],
            'verify_status' => $outcome['verify_status'],
            'retry' => $outcome['retry'],
            'resets_at' => $outcome['resets_at'],
            'sub' => $outcome['sub'],
            'hint' => $outcome['hint'],
            'source' => $outcome['source'],
            'attempts' => ($previous !== null ? (int) $previous['attempts'] : 0) + 1,
        ] + $context), 'tool_eqwpdfcheck');
    }

    /* ---------------------------------------------------------------- */
    /* Wording (language strings; every key is a literal)                */
    /* ---------------------------------------------------------------- */

    /**
     * Why it failed, in one sentence.
     *
     * @param array $f A failure (failure()) or an outcome (classify()).
     * @return string Plain text.
     */
    public static function why(array $f): string {
        switch ($f['code']) {
            case 'rate_limited':
                return get_string('reg_why_rate_limited', 'tool_eqwpdfcheck');
            case 'bad_request':
                return get_string('reg_why_bad_request', 'tool_eqwpdfcheck');
            case 'not_https':
                return get_string('reg_why_not_https', 'tool_eqwpdfcheck', self::site_url());
            case 'host_mismatch':
                return get_string('reg_why_host_mismatch', 'tool_eqwpdfcheck');
            case 'verify_redirect':
                return get_string('reg_why_verify_redirect', 'tool_eqwpdfcheck');
            case 'verify_auth':
                return get_string('reg_why_verify_auth', 'tool_eqwpdfcheck');
            case 'verify_blocked':
                return get_string('reg_why_verify_blocked', 'tool_eqwpdfcheck');
            case 'verify_not_found':
                return get_string('reg_why_verify_not_found', 'tool_eqwpdfcheck');
            case 'verify_nonce_missing':
                return get_string('reg_why_verify_nonce_missing', 'tool_eqwpdfcheck');
            case 'verify_site_error':
                return get_string('reg_why_verify_site_error', 'tool_eqwpdfcheck');
            case 'verify_tls':
                return get_string('reg_why_verify_tls', 'tool_eqwpdfcheck');
            case 'verify_unreachable':
                return get_string('reg_why_verify_unreachable', 'tool_eqwpdfcheck');
            case 'verify_timeout':
                return get_string('reg_why_verify_timeout', 'tool_eqwpdfcheck');
            case 'verify_not_json':
                return get_string('reg_why_verify_not_json', 'tool_eqwpdfcheck');
            case 'verify_debug_output':
                return get_string('reg_why_verify_debug_output', 'tool_eqwpdfcheck');
            case 'verify_nonce_mismatch':
                return get_string('reg_why_verify_nonce_mismatch', 'tool_eqwpdfcheck');
            case 'verify_http':
                return get_string('reg_why_verify_http', 'tool_eqwpdfcheck');
            case 'service_error':
                return get_string('reg_why_service_error', 'tool_eqwpdfcheck');
            case 'token_invalid':
                return get_string('reg_why_token_invalid', 'tool_eqwpdfcheck');
            case 'daily_limit_reached':
                return get_string('reg_why_daily_limit_reached', 'tool_eqwpdfcheck');
            case 'outbound_blocked':
                return get_string('reg_why_outbound_blocked', 'tool_eqwpdfcheck', self::service_host());
        }
        // A code this version does not know: the service's own sentence when it sent
        // one, otherwise the answer itself (the contract's hint, then error).
        if (!empty($f['hint'])) {
            return (string) $f['hint'];
        }
        $error = self::clip((string) ($f['error'] ?? ''), 200);
        return $error !== ''
            ? get_string('reg_why_unknown_error', 'tool_eqwpdfcheck', $error)
            : get_string('reg_why_unknown', 'tool_eqwpdfcheck');
    }

    /**
     * What the admin should do about it.
     *
     * @param array $f A failure (failure()) or an outcome (classify()).
     * @return string Plain text.
     */
    public static function todo(array $f): string {
        switch ($f['code']) {
            case 'rate_limited':
                $at = !empty($f['resets_at']) ? (int) $f['resets_at'] : self::next_utc_midnight(time());
                return get_string('reg_todo_rate_limited', 'tool_eqwpdfcheck', userdate($at));
            case 'bad_request':
                return get_string('reg_todo_bad_request', 'tool_eqwpdfcheck');
            case 'not_https':
                return get_string('reg_todo_not_https', 'tool_eqwpdfcheck');
            case 'host_mismatch':
                return get_string('reg_todo_host_mismatch', 'tool_eqwpdfcheck');
            case 'verify_redirect':
                return get_string('reg_todo_verify_redirect', 'tool_eqwpdfcheck');
            case 'verify_auth':
                return get_string('reg_todo_verify_auth', 'tool_eqwpdfcheck');
            case 'verify_blocked':
                return get_string('reg_todo_verify_blocked', 'tool_eqwpdfcheck');
            case 'verify_not_found':
                return get_string('reg_todo_verify_not_found', 'tool_eqwpdfcheck');
            case 'verify_nonce_missing':
                return get_string('reg_todo_verify_nonce_missing', 'tool_eqwpdfcheck');
            case 'verify_site_error':
                return get_string('reg_todo_verify_site_error', 'tool_eqwpdfcheck');
            case 'verify_tls':
                return get_string('reg_todo_verify_tls', 'tool_eqwpdfcheck');
            case 'verify_unreachable':
                return get_string('reg_todo_verify_unreachable', 'tool_eqwpdfcheck', self::site_url());
            case 'verify_timeout':
                return get_string('reg_todo_verify_timeout', 'tool_eqwpdfcheck');
            case 'verify_not_json':
                return get_string('reg_todo_verify_not_json', 'tool_eqwpdfcheck');
            case 'verify_debug_output':
                return get_string('reg_todo_verify_debug_output', 'tool_eqwpdfcheck');
            case 'verify_nonce_mismatch':
                return get_string('reg_todo_verify_nonce_mismatch', 'tool_eqwpdfcheck');
            case 'verify_http':
                return get_string('reg_todo_verify_http', 'tool_eqwpdfcheck');
            case 'service_error':
                return get_string('reg_todo_service_error', 'tool_eqwpdfcheck');
            case 'token_invalid':
                return get_string('reg_todo_token_invalid', 'tool_eqwpdfcheck');
            case 'daily_limit_reached':
                $at = !empty($f['resets_at']) ? (int) $f['resets_at'] : self::next_utc_midnight(time());
                return get_string('reg_todo_daily_limit_reached', 'tool_eqwpdfcheck', userdate($at));
            case 'outbound_blocked':
                return ($f['sub'] ?? null) === 'policy'
                    ? get_string('reg_todo_outbound_blocked_policy', 'tool_eqwpdfcheck')
                    : get_string('reg_todo_outbound_blocked', 'tool_eqwpdfcheck');
        }
        return get_string('reg_todo_unknown', 'tool_eqwpdfcheck');
    }

    /**
     * Whether the failure is about the verification address (so the notice shows it).
     *
     * @param string $code
     * @return bool
     */
    public static function names_verify_url(string $code): bool {
        return strpos($code, 'verify_') === 0 && $code !== 'verify_unreachable' && $code !== 'verify_tls';
    }

    /**
     * The copyable technical details of a failure, one 'name: value' line each.
     *
     * The names are fixed identifiers (they are read by EqualWeb support, not
     * translated); the values are data.
     *
     * @param array $f A failure from failure().
     * @return string
     */
    public static function details(array $f): string {
        global $CFG;
        $release = (string) (\core_plugin_manager::instance()->get_plugin_info('tool_eqwpdfcheck')->release ?? '');
        $lines = [
            'code' => $f['code'] . (($f['sub'] ?? null) ? ' (' . $f['sub'] . ')' : ''),
            'worker_status' => $f['status'] === null ? '-' : (string) $f['status'],
            'verify_status' => $f['verify_status'] === null ? '-' : (string) $f['verify_status'],
            'error' => (string) $f['error'],
            'time_utc' => $f['at'] ? gmdate('Y-m-d H:i:s', (int) $f['at']) . ' UTC' : '-',
            'attempts' => (string) (int) $f['attempts'],
            'trigger' => $f['trigger'] ?? '-',
            'plugin' => 'tool_eqwpdfcheck ' . ($f['plugin'] ?? $release),
            'moodle' => $f['platform'] ?? self::moodle_version(),
            'verify_url' => $f['verify_url'] ?? self::verify_url(),
            'service' => self::service_host(),
            'service_version' => $f['worker_version'] ?? '-',
            'source' => (string) $f['source'],
        ];
        if (!empty($f['hint'])) {
            $lines['hint'] = (string) $f['hint'];
        }
        $movedfrom = self::moved_from();
        if ($movedfrom !== null) {
            $lines['registered_host'] = $movedfrom;
        }
        $out = '';
        foreach ($lines as $name => $value) {
            $out .= $name . ': ' . $value . "\n";
        }
        if (!empty($CFG->proxyhost)) {
            $out .= "proxy: configured\n";
        }
        return rtrim($out);
    }

    /**
     * Short Moodle version, for example 4.5.12.
     *
     * @return string
     */
    public static function moodle_version(): string {
        global $CFG;
        return preg_match('/^\d+(\.\d+)*/', (string) ($CFG->release ?? ''), $m) ? $m[0] : '';
    }

    /**
     * This site's address.
     *
     * @return string
     */
    protected static function site_url(): string {
        global $CFG;
        return (string) $CFG->wwwroot;
    }
}
