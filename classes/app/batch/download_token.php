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

/** Time-limited bearer links to a single coupon archive.
 *
 * @package block_coupon
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class download_token {
    /** @var int Seven days in seconds. */
    public const LIFETIME = 7 * DAYSECS;

    /** @var string Local token metadata filename prefix. */
    private const PREFIX = '.coupon-token-';

    /** Issue a link token; store only its digest and expiration time. */
    public static function issue(string $archive): string {
        $token = bin2hex(random_bytes(32));
        $metadata = json_encode(['digest' => hash('sha256', $token), 'expires' => time() + self::LIFETIME]);
        if (!is_file($archive) || file_put_contents(self::path($archive), $metadata, LOCK_EX) !== strlen($metadata)) {
            throw new \moodle_exception('cannotwritefile', 'error');
        }
        return $token;
    }

    /** Return whether the token authorizes this archive and has not expired. */
    public static function is_valid(string $archive, string $token): bool {
        if (!preg_match('/\A[0-9a-f]{64}\z/', $token) || !is_file($archive)) {
            return false;
        }
        $metadata = self::read(self::path($archive));
        return $metadata !== null && $metadata['expires'] > time() && hash_equals($metadata['digest'], hash('sha256', $token));
    }

    /** Remove the grant when an archive is consumed. */
    public static function revoke(string $archive): void {
        $path = self::path($archive);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** Prune expired metadata during scheduled cleanup, outside the batch allocation lock. */
    public static function prune(): void {
        global $CFG;
        self::prune_files(new \FilesystemIterator($CFG->dataroot));
        $root = files::encoded_root();
        if (is_dir($root)) {
            self::prune_files(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)));
        }
    }

    /** Remove only token files whose recorded lifetime has ended. */
    private static function prune_files(\Traversable $entries): void {
        $now = time();
        foreach ($entries as $entry) {
            $filename = $entry->getFilename();
            if (
                !str_starts_with($filename, self::PREFIX) ||
                !preg_match('/\A[0-9a-f]{64}\z/', substr($filename, strlen(self::PREFIX)))
            ) {
                continue;
            }
            $path = $entry->getPathname();
            $metadata = self::read($path);
            // Age out malformed or pre-release metadata, without touching a concurrent write.
            if (($metadata['expires'] ?? ($entry->getMTime() + self::LIFETIME)) <= $now && @unlink($path)) {
                files::prune_empty_parents(dirname($path));
            }
        }
    }

    /** Read a small grant file. A missing, incomplete or malformed grant never authorizes a download. */
    private static function read(string $path): ?array {
        $content = is_file($path) ? @file_get_contents($path, false, null, 0, 256) : false;
        $data = $content === false ? null : json_decode($content, true);
        if (!is_int($data['expires'] ?? null) || !is_string($data['digest'] ?? null)) {
            return null;
        }
        return $data;
    }

    /** Use a bounded metadata filename even when the archive name is long. */
    private static function path(string $archive): string {
        return dirname($archive) . '/' . self::PREFIX . hash('sha256', basename($archive));
    }
}
