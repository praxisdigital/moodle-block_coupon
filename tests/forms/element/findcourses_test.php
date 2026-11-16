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

namespace block_coupon\forms\element;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Tests for the findcourses form element.
 *
 * @package     block_coupon
 * @category    test
 * @copyright   2026 Moxis
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \block_coupon\forms\element\findcourses
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\block_coupon\forms\element\findcourses::class)]
final class findcourses_test extends \advanced_testcase {
    /**
     * addElement() without options makes QuickForm pass null as options; that must not fail.
     */
    public function test_add_element_without_options(): void {
        global $CFG;
        $this->resetAfterTest();
        $path = $CFG->dirroot . '/blocks/coupon/classes/forms/element/findcourses.php';
        \MoodleQuickForm::registerElementType('findcourses', $path, findcourses::class);

        $form = new \MoodleQuickForm('findcoursestest', 'post', '');
        $element = $form->addElement('findcourses', 'course', 'Course');

        $this->assertInstanceOf(findcourses::class, $element);
        $this->assertTrue($element->getMultiple());
    }

    /**
     * Options are still honoured.
     */
    public function test_add_element_with_options(): void {
        global $CFG;
        $this->resetAfterTest();
        $path = $CFG->dirroot . '/blocks/coupon/classes/forms/element/findcourses.php';
        \MoodleQuickForm::registerElementType('findcourses', $path, findcourses::class);

        $form = new \MoodleQuickForm('findcoursestest', 'post', '');
        $element = $form->addElement('findcourses', 'course', 'Course', ['multiple' => false]);

        $this->assertInstanceOf(findcourses::class, $element);
        $this->assertFalse($element->getMultiple());
    }
}
