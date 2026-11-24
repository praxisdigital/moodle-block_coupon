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

namespace block_coupon\app\batch;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();
// @codeCoverageIgnoreEnd

/** Batch name rules shared by request and generation workflows.
 *
 * @package block_coupon
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class name {
    /** @var int Maximum length of block_coupon.batchid. */
    public const MAX_LENGTH = 255;

    /** @var bool Whether this process already holds the batch write lock. */
    private static bool $locked = false;

    /** Return the validation string key, or null for a valid optional name. */
    public static function error(string $value): ?string {
        if (strpbrk($value, '/\\') !== false || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            return 'request:batchname:invalid';
        }
        if (\core_text::strlen(trim($value)) > self::MAX_LENGTH) {
            return 'request:batchname:toolong';
        }
        // The download URL uses PARAM_TEXT, so it must round-trip without changing the saved ID.
        if (trim($value) !== clean_param(trim($value), PARAM_TEXT)) {
            return 'request:batchname:plaintext';
        }
        return null;
    }

    /** Return the saved requested name or historical reference as the acceptance default. */
    public static function requested(\stdClass $request): string {
        return (string) ($request->batchname ?? $request->clientref ?? '');
    }

    /** Check whether coupons already belong to this ID, using the same equality as grouping queries. */
    public static function exists(string $value): bool {
        global $DB;
        return $DB->record_exists('block_coupon', ['batchid' => $value]);
    }

    /** Choose the first available ID. Call while holding the batch write lock. */
    public static function next_available(string $base): string {
        $candidate = $base;
        for ($number = 2; self::exists($candidate); $number++) {
            $suffix = '-' . $number;
            $candidate = \core_text::substr($base, 0, self::MAX_LENGTH - strlen($suffix)) . $suffix;
        }
        return $candidate;
    }

    /** Serialize batch allocation through the end of the calling workflow's database writes. */
    public static function synchronise(callable $callback): mixed {
        if (self::$locked) {
            return $callback();
        }
        $factory = \core\lock\lock_config::get_lock_factory('block_coupon');
        $lock = $factory->get_lock('new_batch', 60);
        if (!$lock) {
            throw new \moodle_exception('locktimeout', 'error');
        }
        self::$locked = true;
        try {
            return $callback();
        } finally {
            self::$locked = false;
            $lock->release();
        }
    }
}
