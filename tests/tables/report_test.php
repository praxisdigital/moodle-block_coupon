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

namespace block_coupon\tables;

/**
 * Tests for the progress report table query.
 *
 * @package     block_coupon
 * @category    test
 * @copyright   2026 Moxis
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \block_coupon\tables\report
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\block_coupon\tables\report::class)]
final class report_test extends \advanced_testcase {
    /**
     * Create a claimed coupon of the given type, linked to the given course or cohort.
     *
     * @param int $ownerid owner of the coupon
     * @param int $userid user who claimed it
     * @param string $type course, cohort or coursegrouping
     * @param int $targetid course id (course/coursegrouping) or cohort id (cohort)
     * @return int coupon id
     */
    private function create_claimed_coupon(int $ownerid, int $userid, string $type, int $targetid): int {
        global $DB;
        $now = time();
        $couponid = $DB->insert_record('block_coupon', (object) [
            'ownerid' => $ownerid,
            'userid' => $userid,
            'submission_code' => random_string(16),
            'typ' => $type,
            'claimed' => 1,
            'timecreated' => $now,
            'timeclaimed' => $now,
        ]);
        switch ($type) {
            case 'course':
                $DB->insert_record('block_coupon_courses', (object) ['couponid' => $couponid, 'courseid' => $targetid]);
                break;
            case 'cohort':
                $DB->insert_record('block_coupon_cohorts', (object) ['couponid' => $couponid, 'cohortid' => $targetid]);
                break;
            case 'coursegrouping':
                $DB->insert_record('block_coupon_cgucourses', (object) [
                    'couponid' => $couponid,
                    'courseid' => $targetid,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
                break;
        }
        return $couponid;
    }

    /**
     * Call a protected method on the report table.
     *
     * @param report $table
     * @param string $method
     * @param array $args
     * @return mixed
     */
    private function call(report $table, string $method, array $args = []) {
        $reflection = new \ReflectionMethod($table, $method);
        return $reflection->invokeArgs($table, $args);
    }

    /**
     * Owner-scoped and unscoped reports return the right rows from all three coupon sources.
     */
    public function test_get_query_scoped_by_owner(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();

        $owner = $gen->create_user();
        $otherowner = $gen->create_user();
        $student = $gen->create_user();
        $course = $gen->create_course();
        $cohort = $gen->create_cohort();
        $DB->insert_record('enrol', (object) [
            'enrol' => 'cohort',
            'courseid' => $course->id,
            'customint1' => $cohort->id,
            'status' => ENROL_INSTANCE_ENABLED,
        ]);

        $own = [
            $this->create_claimed_coupon($owner->id, $student->id, 'course', $course->id),
            $this->create_claimed_coupon($owner->id, $student->id, 'cohort', $cohort->id),
            $this->create_claimed_coupon($owner->id, $student->id, 'coursegrouping', $course->id),
        ];
        $this->create_claimed_coupon($otherowner->id, $student->id, 'course', $course->id);
        $this->create_claimed_coupon($otherowner->id, $student->id, 'coursegrouping', $course->id);

        // Scoped to one owner: placeholders must be unique per UNION part.
        $table = new report($owner->id);
        [$sql, $params] = $this->call($table, 'get_query', [false]);
        $this->assertCount(3, $params);
        $rows = $DB->get_records_sql($sql, $params);
        $this->assertEqualsCanonicalizing($own, array_map(fn($row) => (int) $row->id, array_values($rows)));
        $this->assertSame(3, $this->call($table, 'get_count'));

        // Unscoped (viewallreports).
        $table = new report();
        [$sql, $params] = $this->call($table, 'get_query', [false]);
        $this->assertSame([], $params);
        $this->assertCount(5, $DB->get_records_sql($sql, $params));
        $this->assertSame(5, $this->call($table, 'get_count'));
    }
}
