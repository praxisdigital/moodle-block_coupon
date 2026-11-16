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

namespace block_coupon\external;

/**
 * Tests for block_coupon\external\course.
 *
 * @package     block_coupon
 * @category    test
 * @copyright   2026 Moxis
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \block_coupon\external\course
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\block_coupon\external\course::class)]
final class course_test extends \advanced_testcase {
    /**
     * Return the course IDs found by find_courses().
     *
     * @param string $query search string
     * @return int[]
     */
    private function find_course_ids(string $query): array {
        $result = course::find_courses($query);
        $this->assertFalse(isset($result->overflow));
        $ids = array_map(fn($course) => (int) $course->id, $result->data);
        sort($ids);
        return $ids;
    }

    /**
     * Data provider for "no limit" setting values.
     *
     * @return array
     */
    public static function empty_setting_provider(): array {
        return [
            'empty string' => [''],
            'whitespace' => ['  '],
            'only separators' => [' , ,'],
        ];
    }

    /**
     * With an empty dropdown setting all visible courses are found.
     *
     * @dataProvider empty_setting_provider
     * @param string $setting raw setting value
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('empty_setting_provider')]
    public function test_find_courses_without_limit(string $setting): void {
        $this->resetAfterTest();
        set_config('courses_to_show_in_findcourses_dropdown', $setting, 'block_coupon');

        $c1 = $this->getDataGenerator()->create_course(['fullname' => 'Couponsearch one']);
        $c2 = $this->getDataGenerator()->create_course(['fullname' => 'Couponsearch two']);
        $this->getDataGenerator()->create_course(['fullname' => 'Couponsearch hidden', 'visible' => 0]);

        $this->assertSame([(int) $c1->id, (int) $c2->id], $this->find_course_ids('Couponsearch'));
    }

    /**
     * With course IDs in the dropdown setting only those courses are found.
     */
    public function test_find_courses_with_limit(): void {
        $this->resetAfterTest();

        $c1 = $this->getDataGenerator()->create_course(['fullname' => 'Couponsearch one']);
        $this->getDataGenerator()->create_course(['fullname' => 'Couponsearch two']);
        $c3 = $this->getDataGenerator()->create_course(['fullname' => 'Couponsearch three']);
        set_config('courses_to_show_in_findcourses_dropdown', "{$c1->id}, {$c3->id},", 'block_coupon');

        $this->assertSame([(int) $c1->id, (int) $c3->id], $this->find_course_ids('Couponsearch'));
        $this->assertSame([(int) $c1->id, (int) $c3->id], $this->find_course_ids(''));
    }
}
