<?php

namespace dokuwiki\plugin\sitebackup;

use splitbrain\PHPArchive\Tar as UpstreamTar;

/**
 * Tar with the upstream mtime bug patched.
 *
 * DokuWiki 2025-05-14b "Librarian" ships an old version of
 * splitbrain/php-archive whose Tar::writeRawFileHeader() contains:
 *     $size  = self::numberEncode($size, 12);
 *     $mtime = self::numberEncode($size, 12);   // <-- $size, not $mtime
 * So every file's mtime field ends up holding its size, octal-encoded. GNU tar
 * dutifully reads the value as a Unix timestamp and shows 1970-01-01 with a
 * size-derived seconds offset.
 *
 * Fixed upstream in splitbrain/php-archive PR #38. This subclass copies the
 * method verbatim and corrects the one line so existing DokuWiki installs
 * don't have to wait for the vendored library to be bumped.
 */
class PatchedTar extends UpstreamTar
{
    /**
     * @inheritdoc
     */
    protected function writeRawFileHeader($name, $uid, $gid, $perm, $size, $mtime, $typeflag = '')
    {
        // handle filename length restrictions
        $prefix  = '';
        $namelen = strlen($name);
        if ($namelen > 100) {
            $file = basename($name);
            $dir  = dirname($name);
            if (strlen($file) > 100 || strlen($dir) > 155) {
                // we're still too large, let's use GNU longlink
                $this->writeRawFileHeader('././@LongLink', 0, 0, 0, $namelen, 0, 'L');
                for ($s = 0; $s < $namelen; $s += 512) {
                    $this->writebytes(pack("a512", substr($name, $s, 512)));
                }
                $name = substr($name, 0, 100); // cut off name
            } else {
                // we're fine when splitting, use POSIX ustar
                $prefix = $dir;
                $name   = $file;
            }
        }

        // values are needed in octal
        $uid   = sprintf("%6s ", decoct($uid));
        $gid   = sprintf("%6s ", decoct($gid));
        $perm  = sprintf("%6s ", decoct($perm));
        $size  = self::numberEncode($size, 12);
        $mtime = self::numberEncode($mtime, 12);   // patched: was numberEncode($size, 12)

        $data_first = pack("a100a8a8a8a12A12", $name, $perm, $uid, $gid, $size, $mtime);
        $data_last  = pack("a1a100a6a2a32a32a8a8a155a12", $typeflag, '', 'ustar', '', '', '', '', '', $prefix, "");

        for ($i = 0, $chks = 0; $i < 148; $i++) {
            $chks += ord($data_first[$i]);
        }

        for ($i = 156, $chks += 256, $j = 0; $i < 512; $i++, $j++) {
            $chks += ord($data_last[$j]);
        }

        $this->writebytes($data_first);

        $chks = pack("a8", sprintf("%6s ", decoct($chks)));
        $this->writebytes($chks.$data_last);
    }
}
