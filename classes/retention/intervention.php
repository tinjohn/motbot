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
 * Interaction.
 *
 * @package   mod_motbot
 * @copyright 2021, Pascal Hürten <pascal.huerten@th-luebeck.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_motbot\retention;

defined('MOODLE_INTERNAL') || die();

class intervention {
    const TARGET_NAME_REGEX = '/.+\\\(.+)/m';

    const SCHEDULED = 0;
    const INTERVENED = 1;
    const SUCCESSFUL = 2;
    const UNSUCCESSFUL = 3;

    private $id = null;
    private $user = null;
    private $course = null;
    private $target = null;
    private $desired_event = null;
    private $state = self::SCHEDULED;
    private $message = null;
    private $usermodified = null;
    private $timecreated = null;
    private $timemodified = null;


    private function __construct() {

    }

    public static function from_prediction($prediction) {
        global $DB;

        $intervention = new self();

        // Get user id.
        $userid = \mod_motbot\manager::get_prediction_subject($prediction->sampleid);
        if(!$userid) {
            error_log('no subject');
            return;
        }
        $intervention->user = $userid;

        $intervention->course = $prediction->samplecontext->instanceid;

        // Get target of ananlytics model.
        $model = $DB->get_record('analytics_models', array('id'=> $prediction->modelid), 'target');
        if(!$model) {
            error_log('Model not found.');
            return;
        }
        $intervention->target = $model->target;

        $intervention->desired_event = $intervention->get_desired_event();

        // Create DB entry.
        $intervention->id = $DB->insert_record('intervention', $intervention->get_db_data());
        if(!$intervention->id) {
            error_log('Intervention couldnt be inserted into DB');
            return;
        }

        return $intervention;
    }

    public static function from_db($record) {
        $intervention = new self();

        $intervention->id = $record->id;
        $intervention->user = $record->user;
        $intervention->course = $record->course;
        $intervention->desired_event = $record->desired_event;
        $intervention->target = $record->target;
        $intervention->state = $record->state;
        $intervention->message = $record->message;
        $intervention->usermodified = $record->usermodified;
        $intervention->timecreated = $record->timecreated;
        $intervention->timemodified = $record->timemodified;

        return $intervention;
    }

    private function get_db_data() {
        global $USER;

        if(!$this->timecreated) {
            $this->timecreated = time();
        }

        return (object) [
            'id' => $this->id,
            'user' => $this->user,
            'course' => $this->course,
            'desired_event' => $this->desired_event,
            'target' => $this->target,
            'state' => $this->state,
            'message' => $this->message,
            'usermodified' => $USER->id,
            'timecreated' => $this->timecreated,
            'timemodified' => time(),
        ];
    }

    private function get_desired_event() {
        $desired_event = null;

        switch($this->target) {
            case '\mod_motbot\analytics\target\no_recent_accesses':
                $desired_event = '\core\event\course_viewed';
                break;
        }

        return $desired_event;
    }


    private function send_intervention_message() {
        global $DB;
        preg_match(self::TARGET_NAME_REGEX, $this->target, $matches);
        $target_name = $matches[1];

        if(!$target_name || empty($target_name)) {
            error_log('Target name couldnt be identified.');
            return;
        }

        $user = $DB->get_record('user', array('id' => $this->user));

        $message = new \core\message\message();
        $message->component = 'mod_motbot'; // Your plugin's name
        $message->name = 'motbot_intervention'; // Your notification name from message.php
        $message->userfrom = \core_user::get_noreply_user(); // If the message is 'from' a specific user you can set them here
        $message->userto = $user;
        $message->subject = \get_string('message:' . $target_name . '_subject', 'motbot');
        $message->fullmessage = 'message body';
        $message->fullmessageformat = FORMAT_MARKDOWN;
        $message->fullmessagehtml = \get_string('message:' . $target_name . '_fullmessagehtml', 'motbot', $user->firstname);
        $message->smallmessage = 'small message';
        $message->notification = 1; // Because this is a notification generated from Moodle, not a user-to-user message
        $message->contexturl = (new \moodle_url('/course/view.php?id=' . $this->course))->out(false); // A relevant URL for the notification
        $message->contexturlname = 'To Course'; // Link title explaining where users get to for the contexturl
        // $content = array('*' => array('header' => ' test ', 'footer' => ' test ')); // Extra content for specific processor
        // $message->set_additional_content('email', $content);

        // Actually send the message
        $this->message = message_send($message);

        $this->update_record();

        echo('Message ' . $this->message . ' sent to User ' . $this->user);
    }

    private function update_record() {
        global $DB;

        if(!$DB->update_record('intervention', $this->get_db_data())) {
            error_log('Couldnt update intervention.');
            return false;
        }

        return true;
    }

    public function schedule() {
        // TODO: Schedule..
        $this->intervene();
    }

    private function intervene() {

        switch($this->desired_event) {
            default:
                $this->send_intervention_message();
        }

        $this->state = self::INTERVENED;

        $this->update_record();

        return;
    }

    public function on_success() {
        $this->state = self::SUCCESSFUL;
        $this->update_record();
    }
}