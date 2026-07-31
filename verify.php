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
 * Public registration-handshake endpoint fetched by the EqualWeb check proxy.
 * Returns the one-time nonce created by client::register_site().
 *
 * @package    tool_eqwpdfcheck
 * @copyright  2026 EqualWeb
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// This is a public, cookie-less endpoint (the proxy has no Moodle session).
define('NO_MOODLE_COOKIES', true);

require(__DIR__ . '/../../../config.php');

header('Content-Type: application/json');
header('Cache-Control: no-store');

$nonce = get_config('tool_eqwpdfcheck', 'regnonce');
$expires = (int) get_config('tool_eqwpdfcheck', 'regnonceexpires');

if (!$nonce || $expires < time()) {
    http_response_code(404);
    echo json_encode(['error' => 'No pending registration.']);
    exit;
}

echo json_encode(['nonce' => $nonce]);
exit;
