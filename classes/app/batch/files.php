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

    /** Remove the one-time archive and revoke any emailed link. */
    public static function delete_archive(string $archive): void {
        download_token::revoke($archive);
        if (unlink($archive)) {
            self::prune_empty_parents(dirname($archive));
        }
    }

    /** Return the full directory for encoded archives. */
    public static function encoded_root(): string {
        global $CFG;
        return $CFG->dataroot . '/' . self::EXTENDED_DIRECTORY;
    }

    /** Remove empty encoded directories, without touching the root or other moodledata directories. */
    public static function prune_empty_parents(string $path): void {
        $root = self::encoded_root() . '/';
        while (str_starts_with($path, $root)) {
            // rmdir also checks atomically that no other archive exists in the directory.
            if (!@rmdir($path)) {
                break;
            }
            $path = dirname($path);
        }
    }

    /** Return the relative path, preparing the directory if a writer needs it. */
    public static function path(string $batchid, string $timeid, string $extension, bool $write = false): string {
        global $CFG;
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
        $portable = strpbrk($batchid, '<>:"|?*') === false;
        if (strlen($legacy) <= 255 && $portable) {
            return $legacy;
        }
        // Existing Unix archives may use characters that are not portable to Windows.
        if (!$write && DIRECTORY_SEPARATOR === '/' && strlen($legacy) <= 255 && is_file($CFG->dataroot . '/' . $legacy)) {
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
        $directory = self::encoded_directory($batchid);
        if ($write) {
            make_upload_directory($directory);
        }
        return "$directory/coupons-{$timeid}.{$extension}";
    }

    /** Return the relative directory for an encoded batch name. */
    private static function encoded_directory(string $batchid): string {
        return self::EXTENDED_DIRECTORY . '/' . implode('/', str_split(bin2hex($batchid), 120));
    }

    /** Reserve names while any downloadable artifact remains. */
    public static function archive_names(): array {
        global $CFG;
        $names = [];
        foreach (self::list_batches($CFG->dataroot) as $archive) {
            $names[\core_text::strtolower($archive->batchid)] = true;
        }
        return $names;
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
