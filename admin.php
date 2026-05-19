<?php
/**
 * Site Backup admin plugin for DokuWiki.
 *
 * Renders a small form letting an admin pick which parts of the wiki to
 * include in a tar.gz, builds it on the server side, then streams it to
 * the browser as a download. Uses DokuWiki's bundled splitbrain/php-archive
 * (no external dependencies).
 *
 * Intentionally admin-only (forAdminOnly() = true). The archive can contain
 * password hashes (conf/users.auth.php), ACLs, and any credentials stored in
 * conf/local.php (DB, SMTP, etc.), so treat the download as sensitive.
 */

use dokuwiki\Extension\AdminPlugin;
use dokuwiki\Form\Form;
use splitbrain\PHPArchive\Tar;
use splitbrain\PHPArchive\Archive;
use splitbrain\PHPArchive\ArchiveIOException;

class admin_plugin_sitebackup extends AdminPlugin
{
    /** @var array list of [absolute path, archive-relative path] of files to include */
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
     * Actions: "preview" (default - show file list + sizes), "download" (stream tar.gz).
     */
    public function handle()
    {
        global $INPUT;
        if (!$INPUT->has('sitebackup_action')) return;
        if (!checkSecurityToken()) return;

        $action = $INPUT->str('sitebackup_action');
        if ($action !== 'preview' && $action !== 'download') return;

        $this->collectFiles();

        if ($action === 'download') {
            $this->streamArchive();
            // streamArchive exits when successful; if it returns, fall through to html()
        }
        // For 'preview', html() will render the file list + a download button.
    }

    public function html()
    {
        global $INPUT;

        echo '<h1>Site Backup</h1>';
        echo '<p>Select what to include, click <em>Preview</em> to see the file list and total size, '
            . 'then <em>Download</em> to get a tar.gz archive.</p>';
        echo '<p style="background:#fff3cd;border:1px solid #ffeeba;padding:8px;border-radius:4px;">'
            . '<strong>Sensitive content warning.</strong> The archive can contain password hashes '
            . '(<code>conf/users.auth.php</code>), ACL rules, and any secrets stored in '
            . '<code>conf/local.php</code> (DB, SMTP, API keys). Treat it like a credential.'
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

        // Read current selections (defaulting to "everything sensible" on first load).
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
        $this->addCheckboxRow($form, 'sb_pages',       'Pages (data/pages)',                       $sel['pages']);
        $this->addCheckboxRow($form, 'sb_media',       'Media files (data/media)',                 $sel['media']);
        $this->addCheckboxRow($form, 'sb_meta',        'Page metadata (data/meta)',                $sel['meta']);
        $this->addCheckboxRow($form, 'sb_media_meta',  'Media metadata (data/media_meta)',         $sel['media_meta']);
        $this->addCheckboxRow($form, 'sb_attic',       'Page revisions (data/attic) - can be large', $sel['attic']);
        $this->addCheckboxRow($form, 'sb_media_attic', 'Media revisions (data/media_attic)',       $sel['media_attic']);
        $this->addCheckboxRow($form, 'sb_index',       'Search index (data/index) - rebuildable',  $sel['index']);
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

    /**
     * Walk every selected root and build $this->fileList + $this->totalBytes.
     */
    protected function collectFiles()
    {
        global $INPUT, $conf;

        // Map of (form-field => [absolute source path, archive-relative path]).
        // Use $conf[...] for data dirs so we handle relocated savedir installs correctly.
        $roots = [
            'sb_pages'       => [$conf['datadir'],      'data/pages'],
            'sb_media'       => [$conf['mediadir'],     'data/media'],
            'sb_meta'        => [$conf['metadir'],      'data/meta'],
            'sb_media_meta'  => [$conf['mediametadir'], 'data/media_meta'],
            'sb_attic'       => [$conf['olddir'],       'data/attic'],
            'sb_media_attic' => [$conf['mediaolddir'],  'data/media_attic'],
            'sb_index'       => [$conf['indexdir'],     'data/index'],
            'sb_conf'        => [rtrim(DOKU_CONF, '/'), 'conf'],
            'sb_plugins'     => [rtrim(DOKU_PLUGIN, '/'), 'lib/plugins'],
            'sb_tpl'         => [DOKU_INC . 'lib/tpl',  'lib/tpl'],
        ];

        foreach ($roots as $field => $pair) {
            if (!$INPUT->bool($field, false)) continue;
            [$srcAbs, $archiveRel] = $pair;
            $this->walkInto($srcAbs, $archiveRel);
        }
    }

    /**
     * Recursively walk a directory (or single file) and append to $fileList.
     */
    protected function walkInto($srcAbs, $archiveRel)
    {
        if (!file_exists($srcAbs)) return;

        if (is_file($srcAbs)) {
            $this->appendFile($srcAbs, $archiveRel);
            return;
        }

        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcAbs, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
        } catch (Exception $e) {
            return;
        }

        $srcRoot = rtrim($srcAbs, '/');
        $rootLen = strlen($srcRoot) + 1;
        foreach ($it as $info) {
            try {
                if (!$info->isFile()) continue;
                if (!$info->isReadable()) continue;
                $abs = $info->getPathname();
                $rel = substr($abs, $rootLen);
                // Normalize Windows-style separators just in case.
                $rel = str_replace('\\', '/', $rel);

                if ($this->isIgnored($rel)) continue;

                $this->appendFile($abs, $archiveRel . '/' . $rel);
            } catch (Exception $e) {
                // Skip unreadable / vanished files silently.
                continue;
            }
        }
    }

    /**
     * Per-tree filename ignores. Cache/lock/tmp/log are noisy and not useful for a restore.
     * `_dummy` are placeholder files DokuWiki ships to keep empty dirs in tarballs.
     */
    protected function isIgnored($relPath)
    {
        $base = basename($relPath);
        if ($base === '_dummy') return true;
        if ($base === '.DS_Store') return true;
        if ($base === 'Thumbs.db') return true;
        // The plugin's own scratch file - shouldn't exist, but belt and suspenders.
        if (strpos($base, 'sitebackup_tmp_') === 0) return true;
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

        // Per-top-level summary so the user can see what each section costs.
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
            . '(the compressed size will typically be smaller).</p>';
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

        if (!$this->fileList) {
            // Fall through to html() which will just show the form again.
            return;
        }

        @set_time_limit(0);
        @ignore_user_abort(true);
        // PHP 8.x: gzopen and tar building don't need huge memory; bump modestly just in case.
        @ini_set('memory_limit', '256M');

        $tmpDir = $conf['tmpdir'];
        if (!is_dir($tmpDir) || !is_writable($tmpDir)) {
            msg('Site Backup: temp directory is not writable: ' . hsc($tmpDir), -1);
            return;
        }

        // Hostname for filename - sanitize aggressively.
        $host = $_SERVER['HTTP_HOST'] ?? 'wiki';
        $host = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $host);
        $stamp = date('Ymd-His');
        $prefix = $host . '-backup-' . $stamp;       // dir name inside the archive
        $filename = $prefix . '.tar.gz';             // download filename
        $tmpFile = $tmpDir . '/sitebackup_tmp_' . bin2hex(random_bytes(8)) . '.tar.gz';

        try {
            $tar = new Tar();
            $tar->setCompression(6, Archive::COMPRESS_GZIP);
            $tar->create($tmpFile);

            foreach ($this->fileList as [$abs, $rel, $size]) {
                try {
                    $tar->addFile($abs, $prefix . '/' . $rel);
                } catch (Exception $e) {
                    // Skip individual broken files rather than aborting the whole backup.
                    continue;
                }
            }
            $tar->close();
        } catch (ArchiveIOException $e) {
            @unlink($tmpFile);
            msg('Site Backup: could not create archive: ' . hsc($e->getMessage()), -1);
            return;
        }

        if (!is_file($tmpFile) || filesize($tmpFile) === 0) {
            @unlink($tmpFile);
            msg('Site Backup: archive was empty or could not be written.', -1);
            return;
        }

        // Stream out. We bypass DokuWiki's normal output by sending headers and
        // exiting after writing the file body.
        $size = filesize($tmpFile);

        // Clear any output buffering DokuWiki / extensions may have started.
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . $size);
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');

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
}
