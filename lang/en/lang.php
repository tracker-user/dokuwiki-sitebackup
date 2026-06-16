<?php

$lang['menu'] = 'Site Backup';

// Admin page
$lang['intro']      = 'Select what to include, click <em>Preview</em> to see the file list and total size, then <em>Download tar.gz</em> to receive the archive in your browser.';
$lang['warn_title'] = 'Sensitive content warning.';
$lang['warn_body']  = 'The archive may contain password hashes (<code>conf/users.auth.php</code>), ACL rules, and any credentials stored in <code>conf/local.php</code> (DB connection strings, SMTP passwords, API keys). Treat the download like a credential.';

// Fieldset legends
$lang['fs_content'] = 'Wiki content';
$lang['fs_code']    = 'Configuration & code';

// Checkbox labels
$lang['opt_pages']       = 'Pages (data/pages)';
$lang['opt_media']       = 'Media files (data/media)';
$lang['opt_meta']        = 'Page metadata (data/meta)';
$lang['opt_media_meta']  = 'Media metadata (data/media_meta)';
$lang['opt_attic']       = 'Page revisions (data/attic) - can be large';
$lang['opt_media_attic'] = 'Media revisions (data/media_attic)';
$lang['opt_index']       = 'Search index (data/index) - rebuildable';
$lang['opt_conf']        = 'Configuration (conf/) - includes secrets';
$lang['opt_plugins']     = 'Plugins source (lib/plugins/)';
$lang['opt_tpl']         = 'Templates source (lib/tpl/)';

// Buttons
$lang['btn_preview']  = 'Preview';
$lang['btn_download'] = 'Download tar.gz';

// Preview section
$lang['preview_head']    = 'Preview';
$lang['preview_summary'] = '%d files, %s uncompressed.';
$lang['col_section']     = 'Section';
$lang['col_files']       = 'Files';
$lang['col_size']        = 'Size';
$lang['preview_hint']    = 'Click <em>Download tar.gz</em> above to create and download the archive (compressed size will typically be smaller).';

// Error messages
$lang['err_post']    = 'Site Backup: download must be submitted via POST.';
$lang['err_admin']   = 'Site Backup: admin access required.';
$lang['err_empty']   = 'Site Backup: nothing selected.';
$lang['err_tmp']     = 'Site Backup: temp directory is not writable: %s';
$lang['err_create']  = 'Site Backup: could not create archive: %s';
$lang['err_archive'] = 'Site Backup: archive was empty or could not be written.';
