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

/** Authorization and links for batch downloads.
 *
 * @package block_coupon
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class access {
    /** Allow administrators, batch owners or signed-in holders of a valid archive token. */
    public static function require_download(string $batchid, ?string $archive = null, string $token = ''): void {
        global $USER;

        $context = \context_system::instance();
        $capability = 'block/coupon:viewallreports';
        if (isloggedin() && !isguestuser()) {
            if (has_capability($capability, $context) || self::is_owner($batchid, $USER->id)) {
                return;
            }
            if ($archive !== null && download_token::is_valid($archive, $token)) {
                return;
            }
        }
        throw new \required_capability_exception($context, $capability, 'nopermissions', '');
    }

    /** Make sure every coupon with this batch ID belongs to the same user. */
    private static function is_owner(string $batchid, int $userid): bool {
        global $DB;

        $storedid = $DB->get_field('block_coupon', 'batchid', ['batchid' => $batchid, 'ownerid' => $userid], IGNORE_MULTIPLE);
        return $storedid === $batchid && !$DB->record_exists_select(
            'block_coupon',
            'batchid = ? AND (ownerid <> ? OR ownerid IS NULL)',
            [$batchid, $userid]
        );
    }

    /** Build the link. Specify the archive only when emailing a recipient who may not own the batch. */
    public static function url(
        string $batchid,
        string $timeid,
        ?string $archive = null,
        string $token = '',
        bool $download = false
    ): \moodle_url {
        $params = ['bid' => $batchid, 't' => $timeid];
        if ($archive !== null) {
            $token = download_token::issue($archive);
        }
        if ($token !== '') {
            $params['token'] = $token;
        }
        if ($download) {
            $params['dl'] = 1;
        }
        return new \moodle_url('/blocks/coupon/download.php', $params);
    }
}
