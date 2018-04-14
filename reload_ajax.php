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
 * Reloading functionality for culupcoming_events block.
 *
 * @package    block
 * @subpackage culupcoming_events
 * @copyright  2013 Amanda Doughty <amanda.doughty.1@city.ac.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

define('AJAX_SCRIPT', true);

require_once(dirname(__FILE__) . '/../../config.php');
require_once(dirname(__FILE__) . '/../../calendar/lib.php');

use block_culupcoming_events\output\eventlist;

require_sesskey();
require_login();
$PAGE->set_context(context_system::instance());
$lookahead = required_param('lookahead', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);
$limitnum = required_param('limitnum', PARAM_INT);
$page = required_param('page', PARAM_INT);
$limitfrom = $page > 1 ? ($page * $limitnum) - $limitnum : 0;
$list = '';
$end = false;
$renderer = $PAGE->get_renderer('block_culupcoming_events');

$events = new eventlist(
            $lookahead,
            $courseid,
            0,
            0,
            $limitfrom,
            $limitnum,
            $page
        );

$templatecontext = $events->export_for_template($renderer);
$events = $templatecontext['events'];
$more = $templatecontext['pagination'];

if ($events) {
    $list .= $renderer->render_from_template('block_culupcoming_events/eventlist', ['events' => $events]);
}

if (!$more) {
    $list .= html_writer::tag('li', get_string('nomoreevents', 'block_culupcoming_events'));
    $end = true;
}

echo json_encode(array('output' => $list, 'end' => $end));
