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
 * Prints an instance of mod_motbot.
 *
 * @package   mod_motbot
 * @copyright 2021, Pascal Hürten <pascal.huerten@th-luebeck.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once('lib.php');
require_once(__DIR__. '/course_settings_form.php');
require_once($CFG->dirroot.'/mod/motbot/locallib.php');

$id = required_param('id', PARAM_INT);
list ($course, $cm) = get_course_and_cm_from_cmid($id, 'motbot');
$moduleinstance = $DB->get_record('motbot', array('id'=> $cm->instance), '*', MUST_EXIST);

require_login();
$modulecontext = context_module::instance($cm->id);
$coursecontext = context_course::instance($course->id);

$motbot_user = $DB->get_record('motbot_user', array('user' => $USER->id), '*');
$motbot_course_user = $DB->get_record('motbot_course_user', array('motbot' => $moduleinstance->id, 'user' => $USER->id), '*');

if(!$motbot_user) {
    $time = time();
    $motbot_user = (object) [
        'id' => null,
        'user' => $USER->id,
        'authorized' => 0,
        'allow_teacher_involvement' => 0,
        'usermodified' => null,
        'timecreated' => null,
        'timemodified' => null,
    ];
}

if(!$motbot_course_user) {
    $motbot_course_user = (object) [
        'id' => null,
        'motbot' => $moduleinstance->id,
        'user' => $USER->id,
        'authorized' => 0,
        'allow_teacher_involvement' => 0,
        'usermodified' => null,
        'timecreated' => null,
        'timemodified' => null,
    ];
}

$toform = (object) [
    'id' => $id,
    'authorized' => $motbot_course_user->authorized,
    'allow_teacher_involvement' => $motbot_course_user->allow_teacher_involvement,
];

// Wenn parameter $ueid aus overview.php übergeben ist kein kurslogin nötig um alte zertifikate auch zu sehen.
require_login($course, true, $cm);

$PAGE->set_url('/mod/motbot/course_settings.php', array('id' => $cm->id));
$PAGE->set_title(format_string($moduleinstance->name));
$PAGE->set_heading(format_string($course->fullname));
// $PAGE->set_context($modulecontext);

//Instantiate simplehtml_form
$mform = new mod_motbot_course_settings_form();

//Form processing and displaying is done here
if ($mform->is_cancelled()) {
    //Handle form cancel operation, if cancel button is present on form

    if($motbot_course_user->authorized) {
        $url = $CFG->wwwroot.'/mod/motbot/view.php?id=' . $id;
    } else {
        $url = $CFG->wwwroot.'/course/view.php?id=' . $course->id;
    }
    redirect($url);
} else if ($fromform = $mform->get_data()) {
    //In this case you process validated data. $mform->get_data() returns data posted in form.
    $time = time();
    $form_data = $mform->get_data();
    $motbot_course_user->authorized = $form_data->authorized;
    $motbot_course_user->allow_teacher_involvement = $form_data->allow_teacher_involvement;
    $motbot_course_user->usermodified = $USER->id;
    $motbot_course_user->timemodified = $time;
    if(!$motbot_course_user->timecreated) {
        $motbot_course_user->timecreated = $time;
    }

    $motbot_user->authorized = $form_data->authorized;
    $motbot_user->allow_teacher_involvement = $form_data->allow_teacher_involvement;
    $motbot_user->usermodified = $USER->id;
    $motbot_user->timemodified = $time;
    if(!$motbot_user->timecreated) {
        $motbot_user->timecreated = $time;
    }

    if(!$motbot_course_user->id) {
        $DB->insert_record('motbot_course_user', $motbot_course_user);
    } else {
        $DB->update_record('motbot_course_user', $motbot_course_user);
    }

    if(!$motbot_user->id) {
        $DB->insert_record('motbot_user', $motbot_user);
    }

    if($motbot_course_user->authorized) {
        $url = $CFG->wwwroot.'/mod/motbot/view.php?id=' . $id;
    } else {
        $url = $CFG->wwwroot.'/course/view.php?id=' . $course->id;
    }
    redirect($url);
} else {
    // this branch is executed if the form is submitted but the data doesn't validate and the form should be redisplayed
    // or on the first display of the form.
    if(has_capability('mod/motbot:addinstance', $coursecontext)) {
        $url = $CFG->wwwroot.'/course/modedit.php?update=' . $id;
        redirect($url);
        die;
    }

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('pluginname', 'motbot'));

    //Set default data (if any)
    $mform->set_data($toform);
    //displays the form
    $mform->display();


    echo $OUTPUT->footer();

}