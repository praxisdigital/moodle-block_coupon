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
    /** Accept the request and return its final batch ID and delivery status, or null if already finalized. */
    public static function process(int $id, string $batchname, string $message): ?array {
        return self::with_delivery_lock($id, static function () use ($id, $batchname, $message): ?array {
            $record = self::allocate($id, $batchname, $message);
            return $record === null ? null : self::deliver($record);
        });
    }

    /** Retry an unfinished delivery without allocating new coupon keys. */
    public static function retry(int $id): ?array {
        global $DB;
        return self::with_delivery_lock($id, static function () use ($DB, $id): ?array {
            $record = $DB->get_record('block_coupon_requests', ['id' => $id, 'finalized' => 1, 'denied' => 0]);
            if (!$record || $record->deliverydata === null) {
                return null;
            }
            return self::deliver($record);
        });
    }

    /** Reserve and generate the batch once, holding the global lock only for allocation. */
    private static function allocate(int $id, string $batchname, string $message): ?\stdClass {
        global $DB;

        $error = name::error($batchname);
        if ($error !== null) {
            throw new \block_coupon\exception($error, '', name::MAX_LENGTH);
        }
        $batchname = trim($batchname);

        return name::synchronise(static function () use ($DB, $id, $batchname, $message): ?\stdClass {
            $record = $DB->get_record('block_coupon_requests', ['id' => $id], '*', MUST_EXIST);
            if ($record->finalized) {
                return null;
            }
            \core_user::get_user($record->userid, '*', MUST_EXIST);
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
                $record->configuration = serialize($options);
                $record->deliverydata = json_encode([
                    'couponids' => $generator->get_generated_couponids(),
                    'message' => $message,
                ], JSON_THROW_ON_ERROR);
                $record->timemodified = time();
                $DB->update_record('block_coupon_requests', $record);

                $transaction->allow_commit();
            } catch (\Throwable $error) {
                $transaction->rollback($error);
            }
            return $record;
        });
    }

    /** Deliver the original keys and retain retry data only if delivery did not succeed. */
    private static function deliver(\stdClass $record): array {
        global $DB;
        $options = unserialize($record->configuration);
        $delivery = json_decode($record->deliverydata, true, 512, JSON_THROW_ON_ERROR);
        $couponids = $delivery['couponids'];
        $coupons = $DB->get_records_list('block_coupon', 'id', $couponids);
        $user = \core_user::get_user($record->userid);
        if (!$user || $user->deleted || !$coupons || count($coupons) !== count($couponids)) {
            throw new \block_coupon\exception('request:delivery:unavailable');
        }
        foreach ($coupons as $coupon) {
            if ((int) $coupon->ownerid !== (int) $record->userid || $coupon->batchid !== $options->batchid) {
                throw new \block_coupon\exception('request:delivery:unavailable');
            }
        }
        try {
            [$delivered] = helper::mail_requested_coupons($user, $coupons, $options, $delivery['message']);
        } catch (\Throwable $error) {
            // The request is already finalized. Record the failed delivery, not a second acceptance attempt.
            foreach ($couponids as $couponid) {
                $DB->insert_record('block_coupon_errors', (object) [
                    'couponid' => $couponid,
                    'errortype' => 'email',
                    'errormessage' => get_string('coupon:send:fail', 'block_coupon', s($error->getMessage())),
                    'timecreated' => time(),
                    'iserror' => 1,
                ]);
            }
            $delivered = false;
        }
        if ($delivered) {
            $DB->update_record('block_coupon_requests', (object) [
                'id' => $record->id,
                'deliverydata' => null,
                'timemodified' => time(),
            ]);
        }
        return ['batchid' => $options->batchid, 'delivered' => $delivered];
    }

    /** Serialize acceptance and retry for a single request without blocking other deliveries. */
    private static function with_delivery_lock(int $id, callable $callback): mixed {
        $factory = \core\lock\lock_config::get_lock_factory('block_coupon_request_delivery');
        $lock = $factory->get_lock('block_coupon_request_delivery_' . $id, 0);
        if (!$lock) {
            throw new \block_coupon\exception('request:delivery:busy');
        }
        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
