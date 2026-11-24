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

namespace block_coupon\forms;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();
// @codeCoverageIgnoreEnd

use block_coupon\app\batch\name;

/** Shared optional batch name field for request forms.
 *
 * @package block_coupon
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait batchname {
    /** Add the field to the form. */
    private function add_batchname_field(): void {
        $this->_form->addElement('text', 'batchname', get_string('request:batchname', 'block_coupon'));
        $this->_form->setType('batchname', PARAM_RAW);
    }

    /** Validate the original input before any cleaning that could remove invalid characters. */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $value = (string) ($data['batchname'] ?? '');
        $error = name::error($value);
        if ($error !== null) {
            $errors['batchname'] = get_string($error, 'block_coupon', name::MAX_LENGTH);
        }
        return $errors;
    }
}
