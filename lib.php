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
 * Library hooks for tool_eqwpdfcheck.
 *
 * @package    tool_eqwpdfcheck
 * @copyright  2026 EqualWeb
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Serve the "Save as new file" accessible copies (our 'accessible' filearea only;
 * backups/remediated/temp are never served). System :manage, or :view in the
 * course that owns the ORIGINAL file (the itemid is the original file's id).
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool false on failure (otherwise sends the file and exits)
 */
function tool_eqwpdfcheck_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel != CONTEXT_SYSTEM || $filearea !== 'accessible') {
        return false;
    }
    require_login();

    $itemid = (int) array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    if (!has_capability('tool/eqwpdfcheck:manage', context_system::instance())) {
        // Non-managers: resolve the ORIGINAL file (itemid) to its course and
        // require :view there. Gone/courseless originals are admin-only.
        $original = get_file_storage()->get_file_by_id($itemid);
        $origctx = $original ? context::instance_by_id($original->get_contextid(), IGNORE_MISSING) : null;
        $coursectx = $origctx ? $origctx->get_course_context(false) : false;
        if (!$coursectx) {
            return false;
        }
        require_login($coursectx->instanceid);
        if (!has_capability('tool/eqwpdfcheck:view', $coursectx)) {
            return false;
        }
    }

    $file = get_file_storage()->get_file($context->id, 'tool_eqwpdfcheck', 'accessible', $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, $forcedownload, $options);
}

/**
 * Add "PDF accessibility" to the course navigation (More menu / secondary
 * navigation) for users who can view the course's PDF accessibility page.
 *
 * @param navigation_node $nav
 * @param stdClass $course
 * @param context $context
 */
function tool_eqwpdfcheck_extend_navigation_course(navigation_node $nav, stdClass $course, context $context) {
    if (!has_capability('tool/eqwpdfcheck:view', $context)) {
        return;
    }
    $nav->add(
        get_string('coursenav', 'tool_eqwpdfcheck'),
        new moodle_url('/admin/tool/eqwpdfcheck/course.php', ['id' => $course->id]),
        navigation_node::TYPE_SETTING,
        null,
        'eqwpdfcheck'
    );
}
