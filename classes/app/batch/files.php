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

/** Coupon artifact paths and discovery, independent of the database batch ID.
 *
 * @package block_coupon
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class files {
    /** @var string Directory for IDs that don't fit in the legacy filename. */
    private const EXTENDED_DIRECTORY = 'block_coupon_batch_files';

    /** Require the batch owner or a user allowed to view all coupon reports. */
    public static function require_download_access(string $batchid): void {
        global $DB, $USER;

        if (has_capability('block/coupon:viewallreports', \context_system::instance())) {
            return;
        }
        if (
            !isloggedin() || isguestuser() ||
            !$DB->record_exists('block_coupon', ['batchid' => $batchid, 'ownerid' => $USER->id]) ||
            $DB->record_exists_select('block_coupon', 'batchid = ? AND (ownerid <> ? OR ownerid IS NULL)', [$batchid, $USER->id])
        ) {
            throw new \required_capability_exception(\context_system::instance(), 'block/coupon:viewallreports', 'nopermissions', '');
        }
    }

    /** Return the relative path, preparing the directory if a writer needs it. */
    public static function path(string $batchid, string $timeid, string $extension, bool $write = false): string {
        if (
            $batchid === '' || str_contains($batchid, '/') ||
            (DIRECTORY_SEPARATOR === '\\' && str_contains($batchid, '\\')) ||
            preg_match('/[\x00-\x1f\x7f]/', $batchid) || !ctype_digit($timeid) ||
            !in_array($extension, ['pdf', 'zip'], true)
        ) {
            throw new \block_coupon\exception('err:download-not-exists', '', (object) [
                'batchid' => s($batchid),
                'timeid' => s($timeid),
            ]);
        }

        $legacy = "coupons-{$batchid}-{$timeid}.{$extension}";
        if (strlen($legacy) <= 255) {
            return $legacy;
        }

        // Historical short filenames use filesystem rules rather than the new-name validator.
        if (name::error($batchid) !== null) {
            throw new \block_coupon\exception('err:download-not-exists', '', (object) [
                'batchid' => s($batchid),
                'timeid' => s($timeid),
            ]);
        }

        // Encode reversibly so the download list can recover the full ID without a new table or sidecar.
        $directory = self::EXTENDED_DIRECTORY . '/' . implode('/', str_split(bin2hex($batchid), 120));
        if ($write) {
            make_upload_directory($directory);
        }
        return "$directory/coupons-{$timeid}.{$extension}";
    }

    /** Find generated files under moodledata and recover their batch IDs. */
    public static function list_batches(string $root): \Generator {
        foreach (new \FilesystemIterator($root) as $file) {
            if ($file->isFile() && preg_match('/\Acoupons-(.+)-(\d+)\.(?:pdf|zip)\z/', $file->getFilename(), $match)) {
                yield (object) ['batchid' => $match[1], 'tid' => $match[2]];
            }
        }

        $directory = $root . '/' . self::EXTENDED_DIRECTORY;
        if (!is_dir($directory)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file->isFile() || !preg_match('/\Acoupons-(\d+)\.(pdf|zip)\z/', $file->getFilename(), $match)) {
                continue;
            }
            $key = str_replace(DIRECTORY_SEPARATOR, '', substr($file->getPath(), strlen($directory) + 1));
            if ($key === '' || strlen($key) % 2 !== 0 || !ctype_xdigit($key)) {
                continue;
            }
            $batchid = hex2bin($key);
            if ($batchid !== false && name::error($batchid) === null) {
                yield (object) ['batchid' => $batchid, 'tid' => $match[1]];
            }
        }
    }
}
