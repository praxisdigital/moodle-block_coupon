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

use block_coupon\app\batch\files;
use block_coupon\app\batch\name;
use block_coupon\app\request\acceptance;
use block_coupon\coupon\generatoroptions;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use PHPUnit\Framework\Attributes\CoversClass;

/** Tests for named request batches.
 *
 * @package block_coupon
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(files::class)]
#[CoversClass(name::class)]
#[CoversClass(acceptance::class)]
#[CoversClass(privacy\provider::class)]
final class request_batch_test extends provider_testcase {
    /** Test both request types, override, existing IDs, fallback and repeated acceptance. */
    public function test_acceptance_assigns_unique_batch_ids(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $owner = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $cohort = $this->getDataGenerator()->create_cohort();
        $options = new generatoroptions();
        $options->amount = 2;
        $options->ownerid = $owner->id;
        $options->type = generatoroptions::COURSE;
        $options->courses = [$course->id];

        $first = $this->create_request($options, 'PO 123');
        $options->type = generatoroptions::COHORT;
        $options->cohorts = [$cohort->id];
        $second = $this->create_request($options, 'Original order');
        $legacy = $this->create_request($options, null, 'PO 123');
        $blank = $this->create_request($options, null);
        $zero = $this->create_request($options, '0');

        $record = $DB->get_record('block_coupon_requests', ['id' => $legacy], '*', MUST_EXIST);
        $this->assertSame('PO 123', name::requested($record));
        $this->assertSame('Original order', name::requested($DB->get_record('block_coupon_requests', ['id' => $second])));

        $sink = $this->redirectEmails();
        try {
            $this->assertSame(['batchid' => 'PO 123', 'delivered' => true], acceptance::process($first, 'PO 123', ''));
            $this->assertSame('PO 123-2', acceptance::process($second, 'PO 123', '')['batchid']);
            $this->assertSame('PO 123-3', acceptance::process($legacy, name::requested($record), '')['batchid']);
            $this->assertSame($options->batchid, acceptance::process($blank, '   ', '')['batchid']);
            $this->assertSame('0', acceptance::process($zero, '0', '')['batchid']);
            $this->assertNull(acceptance::process($first, 'Different batch', ''));
            $this->assertCount(5, $sink->get_messages());
        } finally {
            $sink->close();
        }

        foreach (['PO 123', 'PO 123-2', 'PO 123-3', $options->batchid, '0'] as $batchid) {
            $this->assertEquals(2, $DB->count_records('block_coupon', ['batchid' => $batchid, 'ownerid' => $owner->id]));
        }
        $this->assertSame('PO 123', $DB->get_field('block_coupon_requests', 'clientref', ['id' => $legacy]));
        $this->assertSame('Original order', $DB->get_field('block_coupon_requests', 'batchname', ['id' => $second]));
    }

    /** Ensure uniqueness also accounts for names taken by the admin generator. */
    public function test_suffix_fits_and_skips_used_ids(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $options = new generatoroptions();
        $options->type = generatoroptions::COURSE;
        $options->courses = [$this->getDataGenerator()->create_course()->id];
        $options->amount = 1;
        $base = str_repeat('Æ', 255);
        $prefix = str_repeat('Æ', 253);

        foreach ([$base, $prefix . '-2'] as $batchid) {
            $options->batchid = $batchid;
            (new \block_coupon\coupon\generator())->generate_coupons($options);
        }
        $this->assertSame($prefix . '-3', name::next_available($base));
        $this->expectException(exception::class);
        $this->expectExceptionMessage(get_string('err:batchid', 'block_coupon'));
        (new \block_coupon\coupon\generator())->generate_coupons($options);
    }

    /** Check name validation, including a legacy reference that needs an edit before acceptance. */
    public function test_batch_name_validation(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->assertNull(name::error('PO 123-4'));
        $this->assertNull(name::error(str_repeat('Æ', 255)));
        $this->assertSame('request:batchname:toolong', name::error(str_repeat('Æ', 256)));
        foreach (['PO/123', 'PO\\123', "PO\n123"] as $invalid) {
            $this->assertSame('request:batchname:invalid', name::error($invalid));
        }

        $url = new \moodle_url('/blocks/coupon/view/requests/admin.php');
        $PAGE->set_url($url);
        $form = new \block_coupon\forms\request\accept($url, ['Heading', 'Details', 'PO/123']);
        $this->assertStringContainsString('value="PO/123"', $form->render());
        $this->assertArrayHasKey('batchname', $form->validation(['batchname' => 'PO/123'], []));
        $this->assertSame([], $form->validation(['batchname' => 'PO 123'], []));
    }

    /** Both request forms expose the name instead of the legacy field. */
    public function test_request_forms_show_optional_batch_name(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $instance = (object) ['configuration' => json_encode([
            'courses' => [$this->getDataGenerator()->create_course()->id],
            'cohorts' => [$this->getDataGenerator()->create_cohort()->id],
        ])];
        $url = new \moodle_url('/blocks/coupon/view/my/requests.php');
        foreach ([\block_coupon\forms\coupon\request\course::class, \block_coupon\forms\coupon\request\cohort::class] as $class) {
            $form = new $class($url, [$instance, $USER]);
            $html = $form->render();
            $this->assertStringContainsString('name="batchname"', $html);
            $this->assertStringNotContainsString('name="clientref"', $html);
            $this->assertArrayHasKey('batchname', $form->validation(['batchname' => 'PO\\123'], []));
            $this->assertArrayNotHasKey('batchname', $form->validation(['batchname' => ''], []));
        }
    }

    /** Privacy exports retain the original value alongside the new request field. */
    public function test_privacy_export_preserves_request_names(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $options = new generatoroptions();
        $options->ownerid = $owner->id;
        $this->create_request($options, 'Invoice 42', 'Legacy 42');
        $context = \context_system::instance();

        $this->export_context_data_for_user($owner->id, $context, 'block_coupon');
        $data = writer::with_context($context)->get_related_data(['block_coupon'], 'couponrequests');
        $this->assertCount(1, $data->coupon);
        $this->assertSame('Invoice 42', $data->coupon[0]->batchname);
        $this->assertSame('Legacy 42', $data->coupon[0]->clientref);
    }

    /** A large batch must appear once in the requester's batch filter suggestions. */
    public function test_my_batch_lookup_finds_large_named_batches(): void {
        global $CFG;
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $this->setUser($owner);
        require_once($CFG->dirroot . '/blocks/coupon/externallib.php');

        $options = new generatoroptions();
        $options->type = generatoroptions::COURSE;
        $options->courses = [$this->getDataGenerator()->create_course()->id];
        $options->amount = 101;
        $options->ownerid = $owner->id;
        $options->batchid = 'Invoice 42';
        (new \block_coupon\coupon\generator())->generate_coupons($options);
        $options->amount = 1;
        $options->ownerid = $this->getDataGenerator()->create_user()->id;
        $options->batchid = 'Invoice 43';
        (new \block_coupon\coupon\generator())->generate_coupons($options);

        $matches = \block_coupon_external::find_batches('Invoice', false);
        $this->assertSame(['Invoice 42'], $matches->data);
        $this->setAdminUser();
        $matches = \block_coupon_external::find_batches('Invoice', false);
        $this->assertSame(['Invoice 42', 'Invoice 43'], $matches->data);
    }

    /** Generate a PDF and ZIP with full-length IDs and resolve them by the public batch ID. */
    public function test_long_batch_files_are_downloadable(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        $options = new generatoroptions();
        $options->ownerid = $this->getDataGenerator()->create_user()->id;
        $options->type = generatoroptions::COHORT;
        $options->cohorts = [$this->getDataGenerator()->create_cohort()->id];
        $options->amount = 1;
        $ids = [str_repeat('A', 255), str_repeat('Æ', 255)];
        $sink = $this->redirectEmails();
        try {
            $generated = [];
            foreach (['pdf', 'zip'] as $index => $extension) {
                $options->generatesinglepdfs = $extension === 'zip';
                $request = $this->create_request($options, $ids[$index]);
                $this->assertSame(['batchid' => $ids[$index], 'delivered' => true], acceptance::process($request, $ids[$index], ''));
                $generated[$ids[$index]] = $extension;
            }
            foreach (files::list_batches($CFG->dataroot) as $file) {
                if (!isset($generated[$file->batchid])) {
                    continue;
                }
                $extension = $generated[$file->batchid];
                $path = $CFG->dataroot . '/' . files::path($file->batchid, $file->tid, $extension);
                $this->assertFileExists($path);
                $this->assertSame($extension === 'zip' ? "PK\x03\x04" : '%PDF', file_get_contents($path, false, null, 0, 4));
                ob_start();
                try {
                    helper::download($file->batchid, $file->tid);
                    $this->assertStringContainsString('name="bid" value="' . s($file->batchid) . '"', ob_get_contents());
                } finally {
                    ob_end_clean();
                    unlink($path);
                }
                unset($generated[$file->batchid]);
            }
            $this->assertSame([], $generated);
        } finally {
            $sink->close();
        }
    }

    /** The owner can open an older archive, but another signed-in user cannot access it. */
    public function test_legacy_archive_requires_batch_ownership(): void {
        global $CFG;
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $options = new generatoroptions();
        $options->type = generatoroptions::COURSE;
        $options->courses = [$this->getDataGenerator()->create_course()->id];
        $options->amount = 1;
        $options->ownerid = $owner->id;
        $options->batchid = DIRECTORY_SEPARATOR === '/' ? 'Old PO\\123' : 'Old PO 123';
        $timeid = '24112026150000';
        $path = $CFG->dataroot . "/coupons-{$options->batchid}-{$timeid}.pdf";
        $this->setAdminUser();
        (new \block_coupon\coupon\generator())->generate_coupons($options);
        file_put_contents($path, '%PDF');

        try {
            files::require_download_access($options->batchid);
            $this->setUser($owner);
            ob_start();
            try {
                helper::download($options->batchid, $timeid);
                $this->assertStringContainsString('name="bid" value="' . s($options->batchid) . '"', ob_get_contents());
            } finally {
                ob_end_clean();
            }

            $this->setUser($other);
            $this->expectException(\required_capability_exception::class);
            helper::download($options->batchid, $timeid);
        } finally {
            $this->assertFileExists($path);
            unlink($path);
        }
    }

    /** Sending a personalized coupon must not split batch ID "0" into per-coupon IDs. */
    public function test_personalized_sending_preserves_zero_batch_id(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('personalsendpdf', 0, 'block_coupon');
        $options = new generatoroptions();
        $options->type = generatoroptions::COURSE;
        $options->courses = [$this->getDataGenerator()->create_course()->id];
        $options->amount = 1;
        $options->batchid = '0';
        $generator = new \block_coupon\coupon\generator();
        $generator->generate_coupons($options);
        $coupon = $DB->get_record('block_coupon', ['id' => $generator->get_generated_couponids()[0]], '*', MUST_EXIST);
        $coupon->for_user_email = 'learner@example.com';
        $coupon->for_user_name = 'Test Learner';
        $coupon->email_body = 'Here is your coupon.';
        $coupon->codeonly = 1;

        $sink = $this->redirectEmails();
        try {
            [$sent, $batchid] = helper::mail_personalized_coupon($coupon);
            $this->assertTrue($sent);
            $this->assertSame('0', $batchid);
            $this->assertCount(1, $sink->get_messages());
        } finally {
            $sink->close();
        }
        $this->assertSame('0', $DB->get_field('block_coupon', 'batchid', ['id' => $coupon->id]));
    }

    /** A remaining archive reserves its batch ID even when there are no coupon rows. */
    public function test_archive_reserves_batch_name_and_uses_portable_path(): void {
        global $CFG;
        $this->resetAfterTest();
        $batchid = 'Orphan PO?123';
        $timeid = '24112026150000';
        $relative = files::path($batchid, $timeid, 'pdf', true);
        $this->assertStringNotContainsString('?', $relative);
        $path = $CFG->dataroot . '/' . $relative;
        file_put_contents($path, '%PDF');
        try {
            $this->assertSame($relative, files::path($batchid, $timeid, 'pdf'));
            $this->assertSame($batchid . '-2', name::next_available($batchid));
        } finally {
            files::delete_archive($path);
        }
        $this->assertSame($batchid, name::next_available($batchid));
    }

    /** An emailed link authorizes a signed-in non-owner for its original archive only. */
    public function test_delivery_token_is_scoped_to_one_archive(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $first = $CFG->dataroot . '/coupons-Archive A-24112026150000.pdf';
        $second = $CFG->dataroot . '/coupons-Archive B-24112026150000.pdf';
        file_put_contents($first, '%PDF');
        file_put_contents($second, '%PDF');
        try {
            $token = files::issue_token($first);
            files::issue_token($second);
            ob_start();
            try {
                helper::download('Archive A', '24112026150000', false, $token);
                $this->assertStringContainsString('name="token" value="' . $token . '"', ob_get_contents());
            } finally {
                ob_end_clean();
            }
            $this->expectException(\required_capability_exception::class);
            helper::download('Archive B', '24112026150000', false, $token);
        } finally {
            files::delete_archive($first);
            files::delete_archive($second);
        }
    }

    /** Create the same DB record shape as the request controller. */
    private function create_request(generatoroptions $options, ?string $batchname, ?string $clientref = null): int {
        global $DB;
        return $DB->insert_record('block_coupon_requests', (object) [
            'userid' => $options->ownerid,
            'configuration' => serialize($options),
            'batchname' => $batchname,
            'clientref' => $clientref,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }
}
