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

namespace block_coupon\app\request;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();
// @codeCoverageIgnoreEnd

use block_coupon\app\batch\name;
use block_coupon\coupon\generator;
use block_coupon\helper;

/** Allocate a batch and fulfil a coupon request.
 *
 * @package block_coupon
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class acceptance {
    /** Accept the pending request and return the final batch ID, or null if already finalized. */
    public static function process(int $id, string $batchname, string $message): ?string {
        global $DB;

        $error = name::error($batchname);
        if ($error !== null) {
            throw new \block_coupon\exception($error, '', name::MAX_LENGTH);
        }
        $batchname = trim($batchname);

        return name::synchronise(static function () use ($DB, $id, $batchname, $message): ?string {
            $record = $DB->get_record('block_coupon_requests', ['id' => $id], '*', MUST_EXIST);
            if ($record->finalized) {
                return null;
            }
            $user = \core_user::get_user($record->userid, '*', MUST_EXIST);
            $options = unserialize($record->configuration);
            if ($batchname !== '') {
                $options->batchid = name::next_available($batchname);
            } else if (name::exists($options->batchid)) {
                // Preserve the saved random ID unless another batch already claimed it.
                $options->batchid = name::next_available($options->batchid);
            }

            $transaction = $DB->start_delegated_transaction();
            try {
                $generator = new generator();
                $generator->generate_coupons($options);
                $record->denied = 0;
                $record->finalized = 1;
                $DB->update_record('block_coupon_requests', $record);

                $coupons = $DB->get_records_list('block_coupon', 'id', $generator->get_generated_couponids());
                helper::mail_requested_coupons($user, $coupons, $options, $message);
                $transaction->allow_commit();
            } catch (\Throwable $error) {
                $transaction->rollback($error);
            }
            return $options->batchid;
        });
    }
}
