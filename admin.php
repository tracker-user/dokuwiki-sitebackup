<?php
if (!defined('DOKU_INC')) die();

/**
 * Site Backup admin plugin for DokuWiki.
 *
 * Streams a tar.gz of selected wiki parts (pages, media, conf, lib/plugins, lib/tpl)
 * to the admin's browser. The archive is built in data/tmp/ with a random filename,
 * streamed out, and deleted immediately. Nothing persists on the server.
 *
 * Security model:
 *  - Admin-only: DokuWiki's AdminPlugin framework enforces auth_isadmin() before
 *    handle()/html() are invoked because forAdminOnly() returns true. A second
 *    explicit check inside streamArchive() guards against any framework bypass.
 *  - The temp archive lives in $conf['tmpdir'] (data/tmp/), which DokuWiki ships
 *    with a deny-all .htaccess; it cannot be fetched directly even if the path
 *    were known.
 *  - Filename uses 64 bits of CSPRNG randomness, file is chmod'd to 0600, and is
 *    deleted both at the natural end of streamArchive() and via a shutdown
 *    function in case the connection is aborted partway.
 *  - Stale temp files from previous runs (older than 1 hour) are swept on each
 *    invocation, so even a crash-during-stream leaves nothing for long.
 *
 * Treat downloaded archives as credentials: they may include conf/users.auth.php
 * (password hashes), ACL rules, and any secrets stored in conf/local.php.
 */

use dokuwiki\Extension\AdminPlugin;
use dokuwiki\Form\Form;
use splitbrain\PHPArchive\Archive;
use splitbrain\PHPArchive\ArchiveIOException;

// PatchedTar fixes splitbrain/php-archive PR #38 (mtime bug) for the version
// of the library vendored with DokuWiki Librarian.
// The class lives in PatchedTar.php and is autoloaded via DokuWiki's PSR-4 loader
// (dokuwiki\plugin\sitebackup namespace -> lib/plugins/sitebackup/).
use dokuwiki\plugin\sitebackup\PatchedTar as Tar;

class admin_plugin_sitebackup extends AdminPlugin
{
    /** Prefix used for the temp archive filename in data/tmp/. */
    const TMP_PREFIX = 'sitebackup_tmp_';

    /** Max age (seconds) of leftover temp files before sweep removes them. */
    const TMP_STALE_AGE = 3600;

    /** @var array list of [absolute path, archive-relative path, size] of files to include */
    protected $fileList = [];

    /** @var int total uncompressed size of selected files */
    protected $totalBytes = 0;

    /**
     * Tracks real paths already added to the archive to prevent double-inclusion
     * via symlinks pointing to the same file.
     *
     * @var array<string, true>
     */
    protected $visitedPaths = [];

    /**
     * @return bool
     */
    public function forAdminOnly(): bool
    {
        return true;
    }

    /**
     * @return int
     */
    public function getMenuSort(): int
    {
        return 1000;
    }

    /**
     * Dispatch based on the submitted action.
     * Valid actions: "preview" (build file list, render summary table),
     *                "download" (build archive, stream as tar.gz).
     */
    public function handle(): void
    {
        global $INPUT;

        // Sweep stale temp files from previous runs every time we enter the page.
        $this->sweepStaleTempFiles();

        if (!$INPUT->has('sitebackup_action')) return;
        if (!checkSecurityToken()) return;

        $action = $INPUT->str('sitebackup_action');
        if ($action !== 'preview' && $action !== 'download') return;

        // Download MUST be POST. Refuse GET / HEAD / etc. so a stray link, browser
        // prefetch, or curious co-admin pasting a URL can't trigger a backup.
        if ($action === 'download' && $INPUT->server->str('REQUEST_METHOD', 'GET') !== 'POST') {
            msg($this->getLang('err_post'), -1);
            return;
        }

        $this->collectFiles();

        if ($action === 'download') {
            $this->streamArchive();
            // streamArchive() exits on success. If it returns, an error was shown
            // via msg() and we fall through to html() so the user sees the form.
        }
    }

    /**
     * Render the admin page: intro, form, and (if $fileList is populated) preview table.
     */
    public function html(): void
    {
        echo '<h1>' . hsc($this->getLang('menu')) . '</h1>';
        echo '<p>' . $this->getLang('intro') . '</p>';
        echo '<p style="background:#fff3cd; border:1px solid #ffeeba; padding:8px; border-radius:4px;">'
            . '<strong>' . hsc($this->getLang('warn_title')) . '</strong> '
            . $this->getLang('warn_body')
            . '</p>';

        $this->renderForm();

        if ($this->fileList) {
            $this->renderPreview();
        }
    }

    /* ----------------------------------------------------------------- *
     *  Form
     * ----------------------------------------------------------------- */

    /**
     * Render the selection form with checkboxes for each backup section.
     */
    protected function renderForm(): void
    {
        global $INPUT;

        $hasSubmitted = $INPUT->has('sitebackup_action');
        $defaults = [
            'pages'       => true,
            'media'       => true,
            'meta'        => true,
            'media_meta'  => true,
            'attic'       => false,
            'media_attic' => false,
            'index'       => false,
            'conf'        => true,
            'plugins'     => true,
            'tpl'         => true,
        ];
        $sel = [];
        foreach ($defaults as $k => $def) {
            $sel[$k] = $hasSubmitted ? $INPUT->bool('sb_' . $k, false) : $def;
        }

        $form = new Form(['method' => 'POST', 'id' => 'sitebackup_form']);
        $form->setHiddenField('do', 'admin');
        $form->setHiddenField('page', 'sitebackup');

        $style = 'text-align: left; padding: 0 1em .5em 1em; margin: 1em 0;';

        $form->addFieldsetOpen($this->getLang('fs_content'))->attr('style', $style);
        $this->addCheckboxRow($form, 'sb_pages',       $this->getLang('opt_pages'),       $sel['pages']);
        $this->addCheckboxRow($form, 'sb_media',       $this->getLang('opt_media'),       $sel['media']);
        $this->addCheckboxRow($form, 'sb_meta',        $this->getLang('opt_meta'),        $sel['meta']);
        $this->addCheckboxRow($form, 'sb_media_meta',  $this->getLang('opt_media_meta'),  $sel['media_meta']);
        $this->addCheckboxRow($form, 'sb_attic',       $this->getLang('opt_attic'),       $sel['attic']);
        $this->addCheckboxRow($form, 'sb_media_attic', $this->getLang('opt_media_attic'), $sel['media_attic']);
        $this->addCheckboxRow($form, 'sb_index',       $this->getLang('opt_index'),       $sel['index']);
        $form->addFieldsetClose();

        $form->addFieldsetOpen($this->getLang('fs_code'))->attr('style', $style);
        $this->addCheckboxRow($form, 'sb_conf',    $this->getLang('opt_conf'),    $sel['conf']);
        $this->addCheckboxRow($form, 'sb_plugins', $this->getLang('opt_plugins'), $sel['plugins']);
        $this->addCheckboxRow($form, 'sb_tpl',     $this->getLang('opt_tpl'),     $sel['tpl']);
        $form->addFieldsetClose();

        $form->addTagOpen('p');
        $form->addButton('sitebackup_action', $this->getLang('btn_preview'))->val('preview');
        $form->addHTML(' &nbsp;&nbsp; ');
        $form->addButton('sitebackup_action', $this->getLang('btn_download'))->val('download');
        $form->addTagClose('p');

        echo $form->toHTML();
    }

    /**
     * Add a labelled checkbox row to the form.
     *
     * @param Form   $form
     * @param string $name    field name
     * @param string $label   display label
     * @param bool   $checked whether the checkbox is pre-checked
     */
    protected function addCheckboxRow(Form $form, string $name, string $label, bool $checked): void
    {
        $form->addTagOpen('div')->attr('style', 'margin:.4em 0;');
        $cb = $form->addCheckbox($name, ' ' . $label);
        $cb->val('1');
        if ($checked) $cb->attr('checked', 'checked');
        $form->addTagClose('div');
    }

    /* ----------------------------------------------------------------- *
     *  File collection
     * ----------------------------------------------------------------- */

    /**
     * Build $this->fileList from the selected checkboxes in the current request.
     */
    protected function collectFiles(): void
    {
        global $INPUT, $conf;

        $this->fileList     = [];
        $this->totalBytes   = 0;
        $this->visitedPaths = [];

        // Use $conf[...] for the data dirs so relocated savedir installs still work.
        $roots = [
            'sb_pages'       => [$conf['datadir'],        'data/pages'],
            'sb_media'       => [$conf['mediadir'],       'data/media'],
            'sb_meta'        => [$conf['metadir'],        'data/meta'],
            'sb_media_meta'  => [$conf['mediametadir'],   'data/media_meta'],
            'sb_attic'       => [$conf['olddir'],         'data/attic'],
            'sb_media_attic' => [$conf['mediaolddir'],    'data/media_attic'],
            'sb_index'       => [$conf['indexdir'],       'data/index'],
            'sb_conf'        => [rtrim(DOKU_CONF, '/'),   'conf'],
            'sb_plugins'     => [rtrim(DOKU_PLUGIN, '/'), 'lib/plugins'],
            'sb_tpl'         => [DOKU_INC . 'lib/tpl',    'lib/tpl'],
        ];

        foreach ($roots as $field => $pair) {
            if (!$INPUT->bool($field, false)) continue;
            [$srcAbs, $archiveRel] = $pair;
            $this->walkInto($srcAbs, $archiveRel);
        }
    }

    /**
     * Recursively enumerate all readable files under $srcAbs and append them to $this->fileList.
     *
     * @param string $srcAbs     absolute filesystem path (file or directory)
     * @param string $archiveRel path prefix to use inside the archive
     */
    protected function walkInto(string $srcAbs, string $archiveRel): void
    {
        if (!file_exists($srcAbs)) return;

        if (is_file($srcAbs)) {
            $this->appendFile($srcAbs, $archiveRel);
            return;
        }

        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $srcAbs,
                    FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS
                ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
        } catch (Exception $e) {
            return;
        }

        $srcRoot = rtrim($srcAbs, '/');
        $rootLen = strlen($srcRoot) + 1;
        foreach ($it as $info) {
            try {
                if (!$info->isFile() || !$info->isReadable()) continue;

                // Skip files already included via a different symlink path.
                $realPath = $info->getRealPath();
                if ($realPath === false) continue;
                if (isset($this->visitedPaths[$realPath])) continue;
                $this->visitedPaths[$realPath] = true;

                $abs = $info->getPathname();
                $rel = str_replace('\\', '/', substr($abs, $rootLen));

                if ($this->isIgnored($archiveRel, $rel)) continue;

                $this->appendFile($abs, $archiveRel . '/' . $rel);
            } catch (Exception $e) {
                continue;
            }
        }
    }

    /**
     * Return true if a file should be excluded from the archive.
     * Hardcoded (no config) to keep the plugin small.
     *
     * @param string $archiveRel top-level archive branch, e.g. "conf" or "lib/plugins"
     * @param string $rel        path within that branch
     * @return bool
     */
    protected function isIgnored(string $archiveRel, string $rel): bool
    {
        $base = basename($rel);

        // Universal noise.
        if ($base === '_dummy') return true;
        if ($base === '.DS_Store') return true;
        if ($base === 'Thumbs.db') return true;

        // Belt-and-suspenders: never include our own scratch files even if
        // someone pointed savedir at an unusual location.
        if (str_starts_with($base, self::TMP_PREFIX)) return true;

        // Skip VCS metadata anywhere in any branch. Local clones / checkouts
        // can be huge and aren't part of "live" state.
        $segments = explode('/', $rel);
        foreach ($segments as $seg) {
            if ($seg === '.git') return true;
            if ($seg === '.svn') return true;
            if ($seg === '.hg') return true;
        }

        // conf/ branch: drop *.dist / *.example / *.bak sample files. They're
        // shipped with DokuWiki and templates, not real configuration.
        if ($archiveRel === 'conf') {
            if (preg_match('/\.(dist|example|bak)$/i', $base)) return true;
        }

        return false;
    }

    /**
     * Append a single file entry to the file list.
     *
     * @param string $abs        absolute filesystem path
     * @param string $archiveRel path inside the archive
     */
    protected function appendFile(string $abs, string $archiveRel): void
    {
        $size = filesize($abs);
        if ($size === false) $size = 0;
        $this->fileList[] = [$abs, $archiveRel, $size];
        $this->totalBytes += $size;
    }

    /* ----------------------------------------------------------------- *
     *  Preview
     * ----------------------------------------------------------------- */

    /**
     * Render a summary table grouping files by top-level archive section.
     */
    protected function renderPreview(): void
    {
        echo '<h2>' . hsc($this->getLang('preview_head')) . '</h2>';
        echo '<p>' . sprintf(
            $this->getLang('preview_summary'),
            count($this->fileList),
            hsc($this->humanBytes($this->totalBytes))
        ) . '</p>';

        $perRoot = [];
        foreach ($this->fileList as [$abs, $rel, $size]) {
            $parts = explode('/', $rel, 4);
            $top = isset($parts[1]) ? ($parts[0] . '/' . $parts[1]) : $parts[0];
            if (!isset($perRoot[$top])) $perRoot[$top] = ['count' => 0, 'bytes' => 0];
            $perRoot[$top]['count']++;
            $perRoot[$top]['bytes'] += $size;
        }
        ksort($perRoot);

        echo '<table class="inline"><thead><tr>'
            . '<th>' . hsc($this->getLang('col_section')) . '</th>'
            . '<th style="text-align:right;">' . hsc($this->getLang('col_files')) . '</th>'
            . '<th style="text-align:right;">' . hsc($this->getLang('col_size')) . '</th>'
            . '</tr></thead><tbody>';
        foreach ($perRoot as $section => $stats) {
            echo '<tr><td><code>' . hsc($section) . '</code></td>'
                . '<td style="text-align:right;">' . (int)$stats['count'] . '</td>'
                . '<td style="text-align:right;">' . hsc($this->humanBytes($stats['bytes'])) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p>' . $this->getLang('preview_hint') . '</p>';
    }

    /**
     * Format a byte count as a human-readable string (B, KiB, MiB, GiB, TiB).
     *
     * @param int $bytes
     * @return string
     */
    protected function humanBytes(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $i = 0;
        $n = (float)$bytes;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }
        return sprintf($i === 0 ? '%d %s' : '%.2f %s', $n, $units[$i]);
    }

    /* ----------------------------------------------------------------- *
     *  Archive creation + streaming
     * ----------------------------------------------------------------- */

    /**
     * Build the archive in data/tmp/, stream it to the browser as a tar.gz download,
     * and exit. Returns without exiting only when an error prevents streaming, so the
     * caller can fall through to html() and display the form again.
     */
    protected function streamArchive(): void
    {
        global $conf, $INPUT;

        // Defense-in-depth: AdminPlugin framework should have blocked non-admins
        // before we got here, but verify directly anyway.
        if (!auth_isadmin()) {
            msg($this->getLang('err_admin'), -1);
            return;
        }

        if (!$this->fileList) {
            msg($this->getLang('err_empty'), -1);
            return;
        }

        set_time_limit(0);
        ignore_user_abort(true);

        // Only raise the memory limit, never lower it.
        $rawLimit = ini_get('memory_limit');
        $unit     = strtolower(substr($rawLimit, -1));
        $limitVal = (int)$rawLimit;
        switch ($unit) {
            case 'g': $limitBytes = $limitVal * 1073741824; break;
            case 'm': $limitBytes = $limitVal * 1048576;    break;
            case 'k': $limitBytes = $limitVal * 1024;       break;
            default:  $limitBytes = $limitVal;              break;
        }
        if ($limitBytes !== -1 && $limitBytes < 268435456) {
            ini_set('memory_limit', '256M');
        }

        $tmpDir = $conf['tmpdir'];
        if (!is_dir($tmpDir) || !is_writable($tmpDir)) {
            msg(sprintf($this->getLang('err_tmp'), hsc($tmpDir)), -1);
            return;
        }

        // Build a hard-to-guess filename. 16 hex chars = 64 bits of entropy from
        // a CSPRNG. The file also lives under data/.htaccess deny-all so even a
        // guess wouldn't be enough.
        $host = $INPUT->server->str('HTTP_HOST', 'wiki');
        $host = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $host);
        $stamp = date('Ymd-His');
        $archiveDir = $host . '-backup-' . $stamp;             // dir inside the tar
        $downloadName = $archiveDir . '.tar.gz';               // browser filename
        $tmpFile = $tmpDir . '/' . self::TMP_PREFIX . bin2hex(random_bytes(8)) . '.tar.gz';

        // Guarantee the temp file is deleted even on connection abort, fatal
        // error, or `exit` from within the streaming loop.
        register_shutdown_function(function () use ($tmpFile) {
            if (is_file($tmpFile)) unlink($tmpFile);
        });

        $oldUmask = umask(0077);

        try {
            $tar = new Tar();
            $tar->setCompression(6, Archive::COMPRESS_GZIP);
            $tar->create($tmpFile);

            // Belt-and-suspenders: explicitly chmod once created, in case the
            // umask wasn't honored (some filesystems / wrappers ignore it).
            chmod($tmpFile, 0600);

            foreach ($this->fileList as [$abs, $rel, $size]) {
                try {
                    $tar->addFile($abs, $archiveDir . '/' . $rel);
                } catch (Exception $e) {
                    // Skip individual broken files rather than failing the whole backup.
                    continue;
                }
            }
            $tar->close();
        } catch (ArchiveIOException $e) {
            umask($oldUmask);
            if (is_file($tmpFile)) unlink($tmpFile);
            msg(sprintf($this->getLang('err_create'), hsc($e->getMessage())), -1);
            return;
        }

        umask($oldUmask);

        if (!is_file($tmpFile) || filesize($tmpFile) === 0) {
            if (is_file($tmpFile)) unlink($tmpFile);
            msg($this->getLang('err_archive'), -1);
            return;
        }

        $size = filesize($tmpFile);

        // Clear any output buffering DokuWiki / extensions may have started so
        // headers + binary body go out cleanly.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . $size);
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');

        $fp = fopen($tmpFile, 'rb');
        if ($fp) {
            while (!feof($fp)) {
                $chunk = fread($fp, 1024 * 256);
                if ($chunk === false) break;
                echo $chunk;
                flush();
            }
            fclose($fp);
        }
        unlink($tmpFile);
        exit;
    }

    /**
     * Remove leftover temp archives from prior runs that died before unlink.
     * Anything matching our prefix older than TMP_STALE_AGE is fair game.
     */
    protected function sweepStaleTempFiles(): void
    {
        global $conf;
        $tmpDir = $conf['tmpdir'] ?? null;
        if (!$tmpDir || !is_dir($tmpDir)) return;

        $cutoff = time() - self::TMP_STALE_AGE;
        $pattern = $tmpDir . '/' . self::TMP_PREFIX . '*';
        foreach ((array) glob($pattern) as $stale) {
            if (!is_file($stale)) continue;
            $mtime = filemtime($stale);
            if ($mtime !== false && $mtime < $cutoff) {
                unlink($stale);
            }
        }
    }
}
