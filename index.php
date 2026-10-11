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
 * EqualWeb PDF Accessibility admin screen.
 *
 * @package    tool_eqwpdfcheck
 * @copyright  2026 EqualWeb
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_eqwpdfcheck\local\client;
use tool_eqwpdfcheck\local\discovery;
use tool_eqwpdfcheck\local\edition;
use tool_eqwpdfcheck\local\page_renderer;
use tool_eqwpdfcheck\local\registration;

admin_externalpage_setup('tool_eqwpdfcheck');
$context = context_system::instance();
require_capability('tool/eqwpdfcheck:manage', $context);

$tab = optional_param('tab', 'files', PARAM_ALPHA);
// The free EqualWeb PDF Checker edition keeps only the checker surfaces:
// Documents plus the informational AI page (Shopify checker parity).
$alltabs = edition::is_free()
    ? ['files', 'ai']
    : ['files', 'analytics', 'classification', 'ai', 'start', 'settings', 'permissions'];
if (!in_array($tab, $alltabs, true)) {
    $tab = 'files';
}

// Refresh the indexed PDF list (throttled) before any tab that reads it.
if ($tab === 'files' || $tab === 'analytics' || $tab === 'classification') {
    discovery::maybe_run_on_view();
}

// Front-end config (mirrors drupalSettings.equalwebPdf).
$jsconfig = [
    'sesskey' => sesskey(),
    'hasApiKey' => (!edition::is_free() && client::get_personal_key() !== ''),
    // The free checker has no Settings tab and never links to signup/purchase.
    'settingsUrl' => edition::is_free()
        ? ''
        : (new moodle_url('/admin/tool/eqwpdfcheck/index.php', ['tab' => 'settings']))->out(false),
    'signupUrl' => edition::is_free() ? '' : page_renderer::SIGNUP_URL,
    // The free checker ships no download.php (remediated-copy downloads are a
    // paid-edition surface); the free JS never reads an empty base.
    'downloadBase' => edition::is_free()
        ? ''
        : (new moodle_url('/admin/tool/eqwpdfcheck/download.php'))->out(false),
    'i18n' => page_renderer::js_i18n(),
    'free' => edition::is_free(),
    // Setting manage=true on the admin page unlocks the classification admin
    // controls in the JS (bulk tag/ignore, clickable chips, restore-all).
    'can' => edition::is_free()
        ? ['check' => true, 'remediate' => false, 'replace' => false, 'manage' => false]
        : ['check' => true, 'remediate' => true, 'replace' => true, 'manage' => true],
    // Free-check registration runs from the page script once the page has
    // rendered, never holding it up; auto_due() applies the retry policy.
    'regAuto' => registration::auto_due(),
];

$PAGE->requires->js_call_amd('tool_eqwpdfcheck/admin', 'init', [$jsconfig]);

echo $OUTPUT->header();
echo page_renderer::render($tab, null, edition::is_free()
    ? ['manage' => false, 'check' => true, 'remediate' => false, 'replace' => false]
    : ['manage' => true, 'check' => true, 'remediate' => true, 'replace' => true]);
echo $OUTPUT->footer();
