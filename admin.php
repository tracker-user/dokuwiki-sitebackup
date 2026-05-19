<?php
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
require_once __DIR__ . '/PatchedTar.php';
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

    public function forAdminOnly()
    {
        return true;
    }

    public function getMenuSort()
    {
        return 1000;
    }

    public function getMenuText($language)
    {
        return 'Site Backup';
    }

    /**
     * Dispatch based on the submitted action.
     * Valid actions: "preview" (build file list, render summary table),
     *                "download" (build archive, stream as tar.gz).
     */
    public function handle()
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
        if ($action === 'download' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            msg('Site Backup: download must be submitted via POST.', -1);
            return;
        }

        $this->collectFiles();

        if ($action === 'download') {
            $this->streamArchive();
            // streamArchive() exits on success. If it returns, an error was shown
            // via msg() and we fall through to html() so the user sees the form.
        }
    }

    public function html()
    {
        echo '<h1>Site Backup</h1>';
        echo '<p>Select what to include, click <em>Preview</em> to see the file list and total size, '
            . 'then <em>Download tar.gz</em> to receive the archive in your browser.</p>';
        echo '<p style="background:#fff3cd;border:1px solid #ffeeba;padding:8px;border-radius:4px;">'
            . '<strong>Sensitive content warning.</strong> The archive may contain password hashes '
            . '(<code>conf/users.auth.php</code>), ACL rules, and any secrets stored in '
            . '<code>conf/local.php</code> (DB credentials, SMTP passwords, API keys). '
            . 'Treat the download like a credential.'
            . '</p>';

        $this->renderForm();

        if ($this->fileList) {
            $this->renderPreview();
        }
    }

    /* ----------------------------------------------------------------- *
     *  Form
     * ----------------------------------------------------------------- */

    protected function renderForm()
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

        $form->addFieldsetOpen('Wiki content');
        $this->addCheckboxRow($form, 'sb_pages',       'Pages (data/pages)',                          $sel['pages']);
        $this->addCheckboxRow($form, 'sb_media',       'Media files (data/media)',                    $sel['media']);
        $this->addCheckboxRow($form, 'sb_meta',        'Page metadata (data/meta)',                   $sel['meta']);
        $this->addCheckboxRow($form, 'sb_media_meta',  'Media metadata (data/media_meta)',            $sel['media_meta']);
        $this->addCheckboxRow($form, 'sb_attic',       'Page revisions (data/attic) - can be large',  $sel['attic']);
        $this->addCheckboxRow($form, 'sb_media_attic', 'Media revisions (data/media_attic)',          $sel['media_attic']);
        $this->addCheckboxRow($form, 'sb_index',       'Search index (data/index) - rebuildable',     $sel['index']);
        $form->addFieldsetClose();

        $form->addFieldsetOpen('Configuration & code');
        $this->addCheckboxRow($form, 'sb_conf',    'Configuration (conf/) - includes secrets',  $sel['conf']);
        $this->addCheckboxRow($form, 'sb_plugins', 'Plugins source (lib/plugins/)',             $sel['plugins']);
        $this->addCheckboxRow($form, 'sb_tpl',     'Templates source (lib/tpl/)',               $sel['tpl']);
        $form->addFieldsetClose();

        $form->addTagOpen('p');
        $form->addButton('sitebackup_action', 'Preview')->val('preview');
        $form->addHTML(' ');
        $form->addButton('sitebackup_action', 'Download tar.gz')->val('download');
        $form->addTagClose('p');

        echo $form->toHTML();
    }

    protected function addCheckboxRow(Form $form, $name, $label, $checked)
    {
        $form->addTagOpen('div')->attr('style', 'margin:4px 0;');
        $cb = $form->addCheckbox($name, ' ' . $label);
        $cb->val('1');
        if ($checked) $cb->attr('checked', 'checked');
        $form->addTagClose('div');
    }

    /* ----------------------------------------------------------------- *
     *  File collection
     * ----------------------------------------------------------------- */

    protected function collectFiles()
    {
        global $INPUT, $conf;

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

    protected function walkInto($srcAbs, $archiveRel)
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
     * Filename / path-segment ignores. Hardcoded (no config) to keep the plugin small.
     *
     * @param string $archiveRel  e.g. "conf" or "lib/plugins" - the top-level branch
     * @param string $rel         path within that branch
     */
    protected function isIgnored($archiveRel, $rel)
    {
        $base = basename($rel);

        // Universal noise.
        if ($base === '_dummy') return true;
        if ($base === '.DS_Store') return true;
        if ($base === 'Thumbs.db') return true;

        // Belt-and-suspenders: never include our own scratch files even if
        // someone pointed savedir at an unusual location.
        if (strpos($base, self::TMP_PREFIX) === 0) return true;

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

    protected function appendFile($abs, $archiveRel)
    {
        $size = @filesize($abs);
        if ($size === false) $size = 0;
        $this->fileList[] = [$abs, $archiveRel, $size];
        $this->totalBytes += $size;
    }

    /* ----------------------------------------------------------------- *
     *  Preview
     * ----------------------------------------------------------------- */

    protected function renderPreview()
    {
        echo '<h2>Preview</h2>';
        echo '<p>' . count($this->fileList) . ' files, '
            . hsc($this->humanBytes($this->totalBytes)) . ' uncompressed.</p>';

        $perRoot = [];
        foreach ($this->fileList as [$abs, $rel, $size]) {
            $parts = explode('/', $rel, 4);
            $top = isset($parts[1]) ? ($parts[0] . '/' . $parts[1]) : $parts[0];
            if (!isset($perRoot[$top])) $perRoot[$top] = ['count' => 0, 'bytes' => 0];
            $perRoot[$top]['count']++;
            $perRoot[$top]['bytes'] += $size;
        }
        ksort($perRoot);

        echo '<table class="inline"><thead><tr><th>Section</th><th>Files</th><th>Size</th></tr></thead><tbody>';
        foreach ($perRoot as $section => $stats) {
            echo '<tr><td><code>' . hsc($section) . '</code></td>'
                . '<td style="text-align:right;">' . (int)$stats['count'] . '</td>'
                . '<td style="text-align:right;">' . hsc($this->humanBytes($stats['bytes'])) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p>Click <em>Download tar.gz</em> above to create and download the archive '
            . '(compressed size will typically be smaller).</p>';
    }

    protected function humanBytes($bytes)
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

    protected function streamArchive()
    {
        global $conf;

        // Defense-in-depth: AdminPlugin framework should have blocked non-admins
        // before we got here, but verify directly anyway.
        if (!auth_isadmin()) {
            msg('Site Backup: admin access required.', -1);
            return;
        }

        if (!$this->fileList) {
            msg('Site Backup: nothing selected.', -1);
            return;
        }

        @set_time_limit(0);
        @ignore_user_abort(true);
        @ini_set('memory_limit', '256M');

        $tmpDir = $conf['tmpdir'];
        if (!is_dir($tmpDir) || !is_writable($tmpDir)) {
            msg('Site Backup: temp directory is not writable: ' . hsc($tmpDir), -1);
            return;
        }

        // Build a hard-to-guess filename. 16 hex chars = 64 bits of entropy from
        // a CSPRNG. The file also lives under data/.htaccess deny-all so even a
        // guess wouldn't be enough.
        $host = $_SERVER['HTTP_HOST'] ?? 'wiki';
        $host = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $host);
        $stamp = date('Ymd-His');
        $archiveDir = $host . '-backup-' . $stamp;             // dir inside the tar
        $downloadName = $archiveDir . '.tar.gz';               // browser filename
        $tmpFile = $tmpDir . '/' . self::TMP_PREFIX . bin2hex(random_bytes(8)) . '.tar.gz';

        // Guarantee the temp file is deleted even on connection abort, fatal
        // error, or `exit` from within the streaming loop.
        register_shutdown_function(function () use ($tmpFile) {
            if (is_file($tmpFile)) @unlink($tmpFile);
        });

        $oldUmask = @umask(0077);

        try {
            $tar = new Tar();
            $tar->setCompression(6, Archive::COMPRESS_GZIP);
            $tar->create($tmpFile);

            // Belt-and-suspenders: explicitly chmod once created, in case the
            // umask wasn't honored (some filesystems / wrappers ignore it).
            @chmod($tmpFile, 0600);

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
            @umask($oldUmask);
            @unlink($tmpFile);
            msg('Site Backup: could not create archive: ' . hsc($e->getMessage()), -1);
            return;
        }

        @umask($oldUmask);

        if (!is_file($tmpFile) || filesize($tmpFile) === 0) {
            @unlink($tmpFile);
            msg('Site Backup: archive was empty or could not be written.', -1);
            return;
        }

        $size = filesize($tmpFile);

        // Clear any output buffering DokuWiki / extensions may have started so
        // headers + binary body go out cleanly.
        while (ob_get_level() > 0) {
            @ob_end_clean();
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
                @flush();
            }
            fclose($fp);
        }
        @unlink($tmpFile);
        exit;
    }

    /**
     * Remove leftover temp archives from prior runs that died before unlink.
     * Anything matching our prefix older than TMP_STALE_AGE is fair game.
     */
    protected function sweepStaleTempFiles()
    {
        global $conf;
        $tmpDir = $conf['tmpdir'] ?? null;
        if (!$tmpDir || !is_dir($tmpDir)) return;

        $cutoff = time() - self::TMP_STALE_AGE;
        $pattern = $tmpDir . '/' . self::TMP_PREFIX . '*';
        foreach ((array) @glob($pattern) as $stale) {
            if (!is_file($stale)) continue;
            $mtime = @filemtime($stale);
            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($stale);
            }
        }
    }
}
