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
 * Certificate template created event.
 *
 * @package   block_coupon
 * @copyright 2023 Leon Stringer <leon.stringer@ntlworld.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coupon\event;

use block_coupon\template;

/**
 * Certificate template created event class.
 *
 * @package   block_coupon
 * @copyright 2023 Leon Stringer <leon.stringer@ntlworld.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_created extends \core\event\base {

    /**
     * Initialises the event.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'block_coupon_templates';
    }

    /**
     * Returns non-localised description of what happened.
     *
     * @return string
     */
    public function get_description() {
        if ($this->contextlevel == \context_system::instance()->contextlevel) {
            // If CONTEXT_SYSTEM assume it's a template.
            return "The user with id '$this->userid' created the certificate template with id '$this->objectid'.";
        } else {
            // Else assume it's a module instance in a course.
            return "The user with id '$this->userid' created the certificate in course module '$this->contextinstanceid'.";
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventtemplatecreated', 'block_coupon');
    }

    /**
     * Create instance of event.
     *
     * @param template $template
     * @return template_created
     */
    public static function create_from_template(template $template) : template_created {
        $data = array(
            'context' => $template->get_context(),
            'objectid' => $template->get_id(),
        );

        return self::create($data);
    }

    /**
     * Returns relevant URL.
     * @return \moodle_url
     */
    public function get_url() {
        if ($this->contextlevel == \context_system::instance()->contextlevel) {
            return new \moodle_url('/blocks/coupon/manage_templates.php');
        } else {
            return new \moodle_url('/blocks/coupon/view.php',
                    array('id' => $this->contextinstanceid));
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return string[]
     */
    public static function get_objectid_mapping() {
        return array('db' => 'block_coupon_templates', 'restore' => 'block_coupon_templates');
    }

    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    public static function get_other_mapping() {
        // No need to map.
        return false;
    }
}
