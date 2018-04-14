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
 * CUL Course Format Information
 *
 * A collapsed format that solves the issue of the 'Scroll of Death' when a course has many sections. All sections
 * except zero have a toggle that displays that section. One or more sections can be displayed at any given time.
 * Toggles are persistent on a per browser session per course basis but can be made to persist longer.
 *
 * @package    block/culupcoming_events
 * @version    See the value of '$plugin->version' in below.
 * @author     Amanda Doughty
 * @license    http://www.gnu.org/copyleft/gpl.html GNU Public License
 *
 */

namespace block_culupcoming_events\output;

use renderer_base;
use renderable;
use templatable;

defined('MOODLE_INTERNAL') || die();

class eventlist implements templatable, renderable {
    /**
     * @var string The tab to display.
     */
    // public $events;

    /**
     * Constructor.
     *
     * @param string $tab The tab to display.
     */
    public function __construct(
        $lookahead,
        $courseid,
        $lastid,
        $lastdate,
        $limitfrom,
        $limitnum,
        $page) 
    {
        $this->lookahead = $lookahead;
        $this->courseid = $courseid;
        $this->lastid = $lastid;
        $this->lastdate = $lastdate;
        $this->limitfrom = $limitfrom;
        $this->limitnum = $limitnum;
        $this->page = $page;
    }

    /**
     * Export this data so it can be used as the context for a mustache template.
     *
     * @param \renderer_base $output
     * @return stdClass
     */
    public function export_for_template(renderer_base $output) {
        $this->output = $output;

        list($more, $events) = $this->get_events(
            $this->lookahead,
            $this->courseid,
            $this->lastid,
            $this->lastdate,
            $this->limitfrom,
            $this->limitnum
            );

        $prev = false;
        $next = false;

        if ($this->page > 1) {
            // Add a 'sooner' link.
            $prev = $this->page - 1;
        }

        if ($more) {
            // Add an 'later' link.
            $next = $this->page + 1;
        }

        $paginationobj = new pagination($prev, $next);
        $pagination = $paginationobj->export_for_template($this->output);

        return [
            'events' => $events,
            'pagination' => $pagination
        ];
    }

    public function get_view(\calendar_information $calendar, $tstart = 0, $tstartaftereventid = 0, $eventlimit = 5) {
        global $PAGE, $CFG;

        $renderer = $PAGE->get_renderer('core_calendar');
        $type = \core_calendar\type_factory::get_calendar_instance();

        // Calculate the bounds of the month.
        $calendardate = $type->timestamp_to_date_array($calendar->time);

        $date = new \DateTime('now', \core_date::get_user_timezone_object(99));

        // Number of days in the future that will be used to fetch events.
        if (isset($CFG->calendar_lookahead)) {
            $defaultlookahead = intval($CFG->calendar_lookahead);
        } else {
            $defaultlookahead = CALENDAR_DEFAULT_UPCOMING_LOOKAHEAD;
        }
        $lookahead = get_user_preferences('calendar_lookahead', $defaultlookahead);

        // Maximum number of events to be displayed on upcoming view.
        $defaultmaxevents = CALENDAR_DEFAULT_UPCOMING_MAXEVENTS;
        if (isset($CFG->calendar_maxevents)) {
            $defaultmaxevents = intval($CFG->calendar_maxevents);
        }


        $tstart = $type->convert_to_timestamp($calendardate['year'], $calendardate['mon'], $calendardate['mday'],
                $calendardate['hours']);
        $date->setTimestamp($tstart);
        $date->modify('+' . $lookahead . ' days');

        // We need to extract 1 second to ensure that we don't get into the next day.
        $date->modify('-1 second');
        $tend = $date->getTimestamp();

        list($userparam, $groupparam, $courseparam, $categoryparam) = array_map(function($param) {
                // If parameter is true, return null.
                if ($param === true) {
                    return null;
                }

                // If parameter is false, return an empty array.
                if ($param === false) {
                    return [];
                }

                // If the parameter is a scalar value, enclose it in an array.
                if (!is_array($param)) {
                    return [$param];
                }

                // No normalisation required.
                return $param;
            }, 
            [$calendar->users, $calendar->groups, $calendar->courses, $calendar->categories]
        );

        $events = \core_calendar\local\api::get_events(
            $tstart,
            $tend,
            null,
            null,
            $tstartaftereventid,
            null,
            $eventlimit,
            null,
            $userparam,
            $groupparam,
            $courseparam,
            $categoryparam,
            true,
            true,
            function ($event) {
                if ($proxy = $event->get_course_module()) {
                    $cminfo = $proxy->get_proxied_instance();
                    return $cminfo->uservisible;
                }

                if ($proxy = $event->get_category()) {
                    $category = $proxy->get_proxied_instance();

                    return $category->is_uservisible();
                }

                return true;
            }
        );

        $related = [
            'events' => $events,
            'cache' => new \core_calendar\external\events_related_objects_cache($events),
            'type' => $type,
        ];

        $data = [];     
        $upcoming = new \core_calendar\external\calendar_upcoming_exporter($calendar, $related);
        $data = $upcoming->export($renderer);

        return $data;
    }

    /**
     * Retrieves and filters the calendar upcoming events and adds meta data
     *
     * @param int $lookahead the number of days to look ahead
     * @param int $courseid the course the block is displaying events for
     * @param int $lastid the id of the last event loaded
     * @param array|int $lastdate the date of the last event loaded
     * @param int $limitfrom the index to start from (for non-JS paging)
     * @param int $limitnum maximum number of events
     * @return array $more bool if there are more events to load, $output array of upcoming events
     */
    public function get_events(
        $lookahead = 365,
        $courseid = SITEID,
        $lastid = 0,
        $lastdate = 0,
        $limitfrom = 0,
        $limitnum = 5) {

        $output = array();
        $more = false;

        // We need a subset of the events and we cannot use timestartafterevent because we want to be able to page forward
        // and backwards. So we retrieve all the events for previous and current page plus one to check if there are more to
        // page through.
        $eventnum = $limitfrom + $limitnum + 1;
        $events = $this->get_all_events($lookahead, $courseid, $lastdate, $lastid, $eventnum);

        if ($events !== false) {
            if (count($events) > ($limitfrom + $limitnum)) {
                $more = true;
                $events = array_slice($events, $limitfrom, $limitnum);
            }

            foreach ($events as $key => $event) {
                $event = $this->add_event_metadata($event);
                $output[] = $event;
            }
        }

        return [$more, $output];
    }

    /* Gets the raw calendar upcoming events
     *
     * @param int $lookahead the number of days to look ahead
     * @param stClass $course the course the block is displaying events for
     * @param array|int $lastdate the date of the last event loaded
     * @return array $filterclass, $events
     */
    public function get_all_events ($lookahead, $courseid, $lastdate = 0, $lastid = 0, $limitnum = 5) {
        global $USER, $PAGE;

        $categoryid = ($PAGE->context->contextlevel === CONTEXT_COURSECAT) ? $PAGE->category->id : null;
        $calendar = \calendar_information::create(time(), $courseid, $categoryid);
        $events = $this->get_view($calendar, $lastdate, $lastid, $limitnum);

        return $events->events;
    }

    /**
     * Gets the calendar upcoming event metadata
     *
     * @param stdClass $event
     * @return stdClass $event with additional attributes
     */
    public function add_event_metadata($event) {
        $event->timeuntil = $this->human_timing($event->timestart);
        $courseid = isset($event->course->id) ? $event->course->id : 0;

        $a = new \stdClass();
        $a->name = $event->name;

        if ($courseid && $courseid != SITEID) {
            $a->course = $this->get_course_displayname ($courseid);
            $event->description = get_string('courseevent', 'block_culupcoming_events', $a);
        } else {
            $event->description = get_string('event', 'block_culupcoming_events', $a);
        }

        switch (strtolower($event->eventtype)) {
            case 'user':
                $event->img = $this->get_user_img($event->userid);
                break;
            case 'course':
                $event->img = $this->get_course_img($event->courseid);
                break;
            case 'site':
                $event->img = $this->get_site_img();
                break;
            default:
                $event->img = $this->get_course_img($event->course->id);
        }

        return $event;
    }


    /**
     * Function that compares a time stamp to the current time and returns a human
     * readable string saying how long until time stamp
     *
     * @param int $time unix time stamp
     * @return string representing time since message created
     */
    public function human_timing ($time) {
        // To get the time until that moment.
        $time = $time - time();
        $timeuntil = get_string('today');

        $tokens = array (
            31536000 => get_string('year'),
            2592000 => get_string('month'),
            604800 => get_string('week'),
            86400 => get_string('day'),
            3600 => get_string('hour'),
            60 => get_string('minute'),
            1 => get_string('second', 'block_culupcoming_events')
        );

        foreach ($tokens as $unit => $text) {

            if ($time < $unit) {
                continue;
            }

            $numberofunits = floor($time / $unit);
            $units = $numberofunits . ' ' . $text . (($numberofunits > 1) ? 's' : '');
            return get_string('time', 'block_culupcoming_events', $units);
        }

        return $timeuntil;
    }

    /**
     * Get the course display name
     * 
     * @param  int $courseid
     * @return string
     */
    public function get_course_displayname ($courseid) {
        global $DB;

        if (!$courseid) {
            return '';
        } else {
            $course = $DB->get_record('course', array('id' => $courseid));
            $courseshortname = $course->shortname;
        }

        return $courseshortname;
    }

    /**
     * Get a course avatar
     * 
     * @param  int $courseid
     * @return string Image tag, wrapped in a hyperlink.
     */
    public function get_course_img ($courseid) {
        global $DB;

        if ($course = $DB->get_record('course', array('id' => $courseid))) {
            $coursepic = new course_picture($course);
            $coursepic->link = true;
            $coursepic->class = 'coursepicture';
            $templatecontext = $coursepic->export_for_template($this->output);
            // get_user_img returns html and we need to be consistent.
            $courseimg = $this->output->render_from_template('block_culupcoming_events/course_picture', $templatecontext);

        }

        return $courseimg;
    }

    /**
     * Get a user avatar
     * 
     * @param  int $userid
     * @return string Image tag, possibly wrapped in a hyperlink.
     */
    public function get_user_img ($userid) {
        global $CFG, $DB, $OUTPUT;

        $userid  = is_numeric($userid) ? $userid : null;

        if ($user = $DB->get_record('user', array('id' => $userid))) {
            $userpic = new \user_picture($user);
            $userpic->link = true;
            $userpic->class = 'personpicture';
            $userimg = $OUTPUT->render($userpic);
        } else {
            $url = $OUTPUT->pix_url('u/f2');
            $attributes = array(
                'src' => $url,
                'alt' => get_string('anon', 'block_culupcoming_events'),
                'class' => 'personpicture'
            );
            $img = html_writer::empty_tag('img', $attributes);
            $attributes = array('href' => $CFG->wwwroot);
            $userimg = html_writer::tag('a', $img, $attributes);
        }

        return $userimg;
    }

    /**
     * Get a site avatar
     * 
     * @return string full image tag, possibly wrapped in a link.
     */
    public function get_site_img () {

        $admins      = get_admins();
        $adminuserid = 2;

        foreach ($admins as $admin) {
            if ('admin' == $admin->username) {
                $adminuserid = $admin->id;
                break;
            }
        }

        $siteimg = $this->get_user_img($adminuserid);

        return $siteimg;
    }

    /**
     * Reload the events including newer ones via ajax call
     * 
     * @param  int $courseid the course id
     * @param  int $lastdate the date of the last event loaded
     * @return array $events array of upcoming event events
     */
    public function ajax_reload($lookahead, $courseid, $lastid) {
        global $DB;
     
        $output = [];
        $more = false;

        // We need a subset of the events and we cannot use timestartafterevent because we want to be able to page forward
        // and backwards. So we retrieve all the events for previous and current page plus one to check if there are more to
        // page through.
        $eventnum = $limitnum + 1;
        $events = $this->get_events($lookahead, $courseid, 0, $lastid, $eventnum);
print_r($events);
        if ($events !== false) {
            if (count($events) > ($limitnum)) {
                $more = true;
                // Get rid of the extra one used to test for more.
                array_pop($events);
            }

            foreach ($events as $key => $event) {
                $event = $this->add_event_metadata($event);
                $output[] = $event;
            }
        }

        return [$more, $output];
    }
}
