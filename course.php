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
 * EqualWeb PDF Accessibility course screen (files only).
 *
 * The course-scoped counterpart of index.php: lists this course's PDFs for
 * users holding tool/eqwpdfcheck:view here. No admin side effects (no site
 * registration, no discovery run — freshness comes from the scheduled task
 * and admin visits), and no Analytics/Settings tabs at course scope.
 *
 * @package    tool_eqwpdfcheck
 * @copyright  2026 EqualWeb
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

use tool_eqwpdfcheck\local\client;
use tool_eqwpdfcheck\local\edition;
use tool_eqwpdfcheck\local\page_renderer;

$id = required_param('id', PARAM_INT);

$course = get_course($id);
require_login($course);
$context = context_course::instance($course->id);
require_capability('tool/eqwpdfcheck:view', $context);

$PAGE->set_url('/admin/tool/eqwpdfcheck/course.php', ['id' => $course->id]);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('coursepagetitle', 'tool_eqwpdfcheck'));
$PAGE->set_heading($course->fullname);

// This user's capability set for this course. System :manage implies all —
// those users normally use the admin page, but the course page must not
// hide anything from them.
$manage = has_capability('tool/eqwpdfcheck:manage', context_system::instance());
$caps = [
    'manage' => $manage && !edition::is_free(),
    'check' => $manage || has_capability('tool/eqwpdfcheck:check', $context),
    'remediate' => !edition::is_free()
        && ($manage || has_capability('tool/eqwpdfcheck:remediate', $context)),
    'replace' => !edition::is_free()
        && ($manage || has_capability('tool/eqwpdfcheck:replace', $context)),
];

// Front-end config — same shape as index.php, plus per-capability 'can'
// booleans; settingsUrl stays empty for non-managers so the need-key modal
// never links to a page they cannot open.
$jsconfig = [
    'sesskey' => sesskey(),
    'hasApiKey' => (!edition::is_free() && client::get_personal_key() !== ''),
    'settingsUrl' => $caps['manage']
        ? (new moodle_url('/admin/tool/eqwpdfcheck/index.php', ['tab' => 'settings']))->out(false) : '',
    'signupUrl' => edition::is_free() ? '' : page_renderer::SIGNUP_URL,
    // The free checker ships no download.php (remediated-copy downloads are a
    // paid-edition surface); the free JS never reads an empty base.
    'downloadBase' => edition::is_free()
        ? ''
        : (new moodle_url('/admin/tool/eqwpdfcheck/download.php'))->out(false),
    'i18n' => page_renderer::js_i18n(),
    'free' => edition::is_free(),
    // The manage flag gates the classification admin controls in the JS; a course
    // teacher who is not a site manager sees the read-only category panel only.
    'can' => [
        'check' => $caps['check'],
        'remediate' => $caps['remediate'],
        'replace' => $caps['replace'],
        'manage' => $caps['manage'],
    ],
];

$PAGE->requires->js_call_amd('tool_eqwpdfcheck/admin', 'init', [$jsconfig]);

echo $OUTPUT->header();
echo page_renderer::render('files', $course->id, $caps);
echo $OUTPUT->footer();
