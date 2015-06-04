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
 * this file contains the tle to display coupons
 *
 * File: logtable.php
 * Encoding: UTF-8
 * @copyright   Sebsoft.nl
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coupon\tables;
require_once($CFG->libdir . '/tablelib.php');

/**
 * block_coupon\tables\coupons
 *
 * @package     block_coupon
 *
 * @copyright   Sebsoft.nl
 * @author      R.J. van Dongen <rogier@sebsoft.nl>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class coupons extends \table_sql {

    /**
     * Do we render the history or the current status?
     *
     * @var int
     */
    protected $ownerid;

    /**
     * Create a new instance of the logtable
     *
     * @param int $ownerid if set, display only coupons from given owner
     */
    public function __construct($ownerid = null) {
        global $USER;
        parent::__construct(__CLASS__. '-' . $USER->id . '-' . ((int)$ownerid));
        $this->ownerid = (int)$ownerid;
        $this->sortable(true, 'c.senddate', 'DESC');
    }

    /**
     * Set the sql to query the db.
     * This method is disabled for this class, since we use internal queries
     *
     * @param string $fields
     * @param string $from
     * @param string $where
     * @param array $params
     * @throws exception
     */
    public function set_sql($fields, $from, $where, array $params = null) {
        // We'll disable this method.
        throw new exception('err:statustable:set_sql');
    }

    /**
     * Display the general status log table.
     *
     * @param int $pagesize
     * @param bool $useinitialsbar
     */
    public function render($pagesize, $useinitialsbar = true) {
        $this->define_table_columns(array('owner', 'for_user_email', 'senddate',
            'enrolperiod', 'submission_code', 'course', 'cohorts', 'groups', 'issend'));

        // Generate SQL.
        $fields = 'c.*, ' . get_all_user_name_fields(true, 'u');
        $from = '{block_coupon} c LEFT JOIN {user} u ON c.ownerid=u.id';
        $where = 'c.userid IS NULL';
        $params = array();
        if ($this->ownerid > 0) {
            $where .= ' AND c.ownerid = ?';
            $params[] = $this->ownerid;
        }
        parent::set_sql($fields, $from, $where, $params);
        $this->out($pagesize, $useinitialsbar);
    }

    /**
     * Render visual representation of the 'owner' column for use in the table
     *
     * @param \stdClass $row
     * @return string time string
     */
    public function col_owner($row) {
        return fullname($row);
    }

    /**
     * Render visual representation of the 'senddate' column for use in the table
     *
     * @param \stdClass $row
     * @return string time string
     */
    public function col_senddate($row) {
        static $strimmediately;
        if ($strimmediately === null) {
            $strimmediately = get_string('report:immediately', 'block_coupon');
        }
        return (is_null($row->senddate) ? $strimmediately : userdate($row->senddate));
    }

    /**
     * Render visual representation of the 'issend' column for use in the table
     *
     * @param \stdClass $row
     * @return string time string
     */
    public function col_issend($row) {
        static $stryes;
        static $strno;
        if ($stryes === null) {
            $stryes = get_string('yes');
            $strno = get_string('no');
        }
        return (((bool)$row->senddate) ? $stryes : $strno);
    }

    /**
     * Render visual representation of the 'cohorts' column for use in the table
     *
     * @param \stdClass $row
     * @return string time string
     */
    public function col_cohorts($row) {
        global $DB;
        $rs = array();
        $records = $DB->get_records_sql("SELECT c.id,c.name FROM {block_coupon_cohorts} cc
            LEFT JOIN {cohort} c ON cc.cohortid = c.id
            WHERE cc.couponid = ?", array($row->id));
        foreach ($records as $record) {
            $rs[] = $record->name;
        }
        return implode($this->is_downloading() ? ', ' : '<br/>', $rs);
    }

    /**
     * Render visual representation of the 'course' column for use in the table
     *
     * @param \stdClass $row
     * @return string time string
     */
    public function col_course($row) {
        global $DB;
        $rs = array();
        $records = $DB->get_records_sql("SELECT c.id,c.fullname FROM {block_coupon_courses} cc
            LEFT JOIN {course} c ON cc.courseid = c.id
            WHERE cc.couponid = ?", array($row->id));
        foreach ($records as $record) {
            $rs[] = $record->fullname;
        }
        return implode($this->is_downloading() ? ', ' : '<br/>', $rs);
    }

    /**
     * Render visual representation of the 'groups' column for use in the table
     *
     * @param \stdClass $row
     * @return string time string
     */
    public function col_groups($row) {
        global $DB;
        $rs = array();
        $records = $DB->get_records_sql("SELECT g.id,g.name FROM {block_coupon_groups} cg
            LEFT JOIN {groups} g ON cg.groupid = g.id
            WHERE cg.couponid = ?", array($row->id));
        foreach ($records as $record) {
            $rs[] = $record->name;
        }
        return implode($this->is_downloading() ? ', ' : '<br/>', $rs);
    }

    /**
     * Render visual representation of the 'timecreated' column for use in the table
     *
     * @param \stdClass $row
     * @return string time string
     */
    public function col_timecreated($row) {
        return userdate($row->timecreated);
    }

    /**
     * Render visual representation of the 'action' column for use in the table
     *
     * @param \stdClass $row
     * @return string actions
     */
    public function col_action($row) {
        $actions = array();
        return implode('', $actions);
    }

    /**
     * Return the image tag representing an action image
     *
     * @param string $action
     * @return string HTML image tag
     */
    protected function get_action_image($action) {
        global $OUTPUT;
        return '<img src="' . $OUTPUT->pix_url($action, 'block_coupon') . '"/>';
    }

    /**
     * Return a string containing the link to an action
     *
     * @param \stdClass $row
     * @param string $action
     * @return string link representing the action with an image
     */
    protected function get_action($row, $action) {
        $actionstr = 'str' . $action;
        return '<a href="' . new \moodle_url($this->baseurl,
                array('action' => $action, 'id' => $row->id)) .
                '" alt="' . $this->{$actionstr} .
                '">' . $this->get_action_image($action) . '</a>';
    }

    /**
     * Define columns for output table and define the headers through automated
     * lookup of the language strings.
     *
     * @param array $columns list of column names
     */
    protected function define_table_columns($columns) {
        $this->define_columns($columns);
        $headers = array();
        foreach ($columns as $name) {
            $headers[] = get_string('th:' . $name, 'block_coupon');
        }
        $this->define_headers($headers);
    }

}