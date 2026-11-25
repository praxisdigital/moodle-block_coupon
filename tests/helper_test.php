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

namespace block_coupon;

use block_coupon\coupon\generator;
use block_coupon\coupon\generatoroptions;

/**
 * Tests for block_coupon\helper.
 *
 * @package     block_coupon
 * @category    test
 * @copyright   2026 Moxis
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \block_coupon\helper
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\block_coupon\helper::class)]
final class helper_test extends \advanced_testcase {
    /** Manual generation keeps bad recipient rows on the form and supplies only validated recipients. */
    public function test_manual_recipient_validation(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('max_coupons', 50, 'block_coupon');
        $options = new generatoroptions();
        $options->type = generatoroptions::COHORT;
        $options->generatormethod = 'manual';
        $form = new \block_coupon\forms\coupon\ctpage(new \moodle_url('/blocks/coupon/view/generator/cohort.php'), [$options]);
        $field = 'coupon_recipients_manual';

        foreach (['Bad header', 'E-mail,Gender,Gender', "E-mail,Gender,Name\nlearner@example.com"] as $input) {
            $this->assertSame(
                get_string('error:recipients-columns-missing', 'block_coupon', 'e-mail,gender,name'),
                $form->validation([$field => $input], [])[$field]
            );
            $this->assertSame([], $form->get_manual_recipients());
        }
        $input = "E-mail,Gender,Name\r\n\r\n";
        $this->assertSame(get_string('error:recipients-empty', 'block_coupon'), $form->validation([$field => $input], [])[$field]);
        $this->assertSame([], $form->validation([$field => $input . 'learner@example.com,,"Learner, Test"'], []));
        $this->assertCount(1, $form->get_manual_recipients());
        $this->assertSame('Learner, Test', $form->get_manual_recipients()[0]->name);
        $this->assertArrayHasKey($field, $form->validation([$field => $input . 'invalid-address,,Learner'], []));
        $this->assertSame([], $form->get_manual_recipients());
    }

    /** An unknown coupon code is a field error rather than an exception from the form. */
    public function test_unknown_coupon_code_is_a_form_error(): void {
        $form = new \block_coupon\forms\coupon\validator(new \moodle_url('/blocks/coupon/view/input_coupon.php'));
        $this->assertSame(
            ['coupon_code' => get_string('error:invalid_coupon_code', 'block_coupon')],
            $form->validation(['coupon_code' => 'UNKNOWNTESTCODE'], [])
        );
    }

    /** Extended claim workflow notifications should be shown as field errors. */
    public function test_used_coupon_notification_is_a_form_error(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('claimworkflow', 2, 'block_coupon');
        $options = new generatoroptions();
        $options->type = generatoroptions::COURSE;
        $options->courses = [$this->getDataGenerator()->create_course()->id];
        $options->amount = 1;
        $generator = new generator();
        $generator->generate_coupons($options);
        $coupon = $DB->get_record('block_coupon', ['id' => $generator->get_generated_couponids()[0]], '*', MUST_EXIST);
        $coupon->claimed = 1;
        $coupon->userid = $this->getDataGenerator()->create_user()->id;
        $DB->update_record('block_coupon', $coupon);

        $form = new \block_coupon\forms\coupon\validator(new \moodle_url('/blocks/coupon/view/input_coupon.php'));
        $this->assertSame(
            ['coupon_code' => get_string('error:coupon_already_used_other', 'block_coupon')],
            $form->validation(['coupon_code' => $coupon->submission_code], [])
        );
    }

    /**
     * Data provider for test_get_findcourses_dropdown_courseids.
     *
     * @return array
     */
    public static function dropdown_setting_provider(): array {
        return [
            'empty' => ['', []],
            'whitespace only' => ["  \n ", []],
            'commas only' => [',,', []],
            'single id' => ['5', [5]],
            'comma separated with spaces' => [' 5, 7 ,9 ', [5, 7, 9]],
            'newline separated' => ["5\n7\r\n9", [5, 7, 9]],
            'duplicates removed' => ['5,5,7', [5, 7]],
            'junk and zero ignored' => ['abc,0,-3,4.5,12', [12]],
        ];
    }

    /**
     * The dropdown setting is parsed into clean course IDs.
     *
     * @dataProvider dropdown_setting_provider
     * @param string $setting raw setting value
     * @param int[] $expected expected course IDs
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('dropdown_setting_provider')]
    public function test_get_findcourses_dropdown_courseids(string $setting, array $expected): void {
        $this->resetAfterTest();
        set_config('courses_to_show_in_findcourses_dropdown', $setting, 'block_coupon');
        $this->assertSame($expected, helper::get_findcourses_dropdown_courseids());
    }

    /**
     * An unset setting means no limit.
     */
    public function test_get_findcourses_dropdown_courseids_unset(): void {
        $this->resetAfterTest();
        unset_config('courses_to_show_in_findcourses_dropdown', 'block_coupon');
        $this->assertSame([], helper::get_findcourses_dropdown_courseids());
    }

    /**
     * Accepting a request generates the PDF, mails the request user and marks the keys as sent.
     */
    public function test_mail_requested_coupons(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();

        $requestuser = $this->getDataGenerator()->create_user(['email' => 'requester@example.com']);
        $cohort = $this->getDataGenerator()->create_cohort();

        // Same shape as a cohort request stored by controller\my\requests and unserialised on accept.
        $options = new generatoroptions();
        $options->type = generatoroptions::COHORT;
        $options->amount = 2;
        $options->ownerid = $requestuser->id;
        $options->cohorts = [$cohort->id];
        $options->emailto = 'alternative@example.com';
        $options->generatesinglepdfs = false;
        $options = unserialize(serialize($options));

        $generator = new generator();
        $generator->generate_coupons($options);
        $coupons = $DB->get_records_list('block_coupon', 'id', $generator->get_generated_couponids());
        $this->assertCount(2, $coupons);

        $sink = $this->redirectEmails();
        [$mailstatus, $batchid, $ts] = helper::mail_requested_coupons($requestuser, $coupons, $options, 'Extra');
        $messages = $sink->get_messages();
        $sink->close();

        $this->assertTrue($mailstatus);
        $this->assertSame($options->batchid, $batchid);
        $this->assertCount(1, $messages);
        $this->assertSame('alternative@example.com', $messages[0]->to);
        $this->assertFileExists("{$CFG->dataroot}/coupons-{$batchid}-{$ts}.pdf");

        foreach ($DB->get_records_list('block_coupon', 'id', array_keys($coupons)) as $coupon) {
            $this->assertEquals(1, $coupon->issend);
            $this->assertEquals($requestuser->id, $coupon->ownerid);
        }
        unlink("{$CFG->dataroot}/coupons-{$batchid}-{$ts}.pdf");
    }
}
