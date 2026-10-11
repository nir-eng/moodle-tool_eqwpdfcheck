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
use tool_eqwpdfcheck\local\page_renderer;
use tool_eqwpdfcheck\local\registration;

/**
 * External function: (re)register the site with the free-check proxy.
 *
 * @package    tool_eqwpdfcheck
 * @copyright  2026 EqualWeb
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class register_site extends base {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'trigger' => new external_value(
                PARAM_ALPHA,
                'page (automatic, after the admin page loaded) or button (Try again)',
                VALUE_DEFAULT,
                'button'
            ),
        ]);
    }

    /**
     * Register the site with the free-check proxy.
     *
     * Answers with the classified message and the re-rendered registration box
     * body (html), so the page updates the notice in place.
     *
     * Like every AJAX call that does not declare 'readonlysession', this one
     * holds the session lock while the service verifies the site (up to about
     * 30 seconds), so the same user's other requests wait for it. The page starts
     * it after rendering, so nothing on screen waits; declaring 'readonlysession'
     * would only help on sites with $CFG->enable_read_only_sessions, and needs a
     * test on such a site first.
     *
     * @param string $trigger 'page' or 'button'.
     * @return array
     */
    public static function execute(string $trigger = 'button'): array {
        self::guard_system();
        $trigger = $trigger === 'page' ? 'page' : 'button';

        if ($trigger === 'page' && !registration::auto_due()) {
            // Another tab or a recent attempt got there first: report the state
            // as it is, without asking the service again.
            $state = registration::state();
            if ($state['registered']) {
                $res = true;
            } else if ($state['failure'] !== null) {
                $res = registration::why($state['failure']);
            } else {
                $res = get_string('reg_pending', 'tool_eqwpdfcheck');
            }
        } else {
            // An automatic attempt for a site whose token was issued for another
            // host (it moved, or it is a copy) tells the service it is a host change.
            if ($trigger === 'page' && registration::moved_from() !== null) {
                $trigger = 'host_change';
            }
            $res = client::register_site($trigger);
        }

        $html = page_renderer::registration_notice_body();
        if (is_string($res)) {
            return self::err($res, ['html' => $html, 'registered' => false]);
        }
        return self::ok([
            'message' => get_string('reg_success', 'tool_eqwpdfcheck'),
            'html' => $html,
            'registered' => true,
        ]);
    }
}
