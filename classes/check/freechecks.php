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

namespace tool_eqwpdfcheck\check;

use core\check\check;
use core\check\result;
use tool_eqwpdfcheck\local\client;
use tool_eqwpdfcheck\local\edition;
use tool_eqwpdfcheck\local\registration;

/**
 * Free-check registration health, for Site administration > Reports > System status.
 *
 * Reads the stored registration state only; it never contacts the service.
 *
 * @package    tool_eqwpdfcheck
 * @copyright  2026 EqualWeb
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class freechecks extends check {
    /**
     * Short name of the check. It names the plugin: the System status table has
     * no component column, and two EqualWeb PDF plugins can be installed side by side.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('reg_checkname', 'tool_eqwpdfcheck', get_string('pluginname', 'tool_eqwpdfcheck'));
    }

    /**
     * Link to the plugin page, where the registration notice explains and retries.
     *
     * @return \action_link|null
     */
    public function get_action_link(): ?\action_link {
        return new \action_link(
            new \moodle_url('/admin/tool/eqwpdfcheck/index.php'),
            get_string('reg_checkaction', 'tool_eqwpdfcheck', get_string('pluginname', 'tool_eqwpdfcheck'))
        );
    }

    /**
     * The registration state as a check result.
     *
     * @return result
     */
    public function get_result(): result {
        $state = registration::state();
        if ($state['registered']) {
            return new result(result::OK, s(get_string('reg_ok', 'tool_eqwpdfcheck')));
        }
        if (!edition::is_free() && client::get_personal_key() !== '') {
            return new result(result::INFO, s(get_string('reg_keyinuse', 'tool_eqwpdfcheck')));
        }
        $moved = $state['movedfrom'] === null ? '' : s(get_string('reg_moved', 'tool_eqwpdfcheck', $state['movedfrom']));
        $f = $state['failure'];
        if ($f === null) {
            return new result(result::INFO, s(get_string('reg_checkpending', 'tool_eqwpdfcheck')), $moved);
        }
        $details = ($moved !== '' ? $moved . ' ' : '') . s(registration::todo($f));
        // The what-to-do text refers to the address below, as on the plugin page.
        if (registration::names_verify_url($f['code'])) {
            $details .= ' ' . s(get_string('reg_verifyaddress', 'tool_eqwpdfcheck')) . ' ' . s(registration::verify_url());
        } else if ($f['code'] === 'outbound_blocked') {
            $details .= ' ' . s(get_string('reg_serviceaddress', 'tool_eqwpdfcheck')) . ' ' . s(registration::service_host());
        }
        return new result(result::WARNING, s(registration::why($f)), $details);
    }
}
