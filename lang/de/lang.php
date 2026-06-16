<?php

$lang['menu'] = 'Website-Sicherung';

// Admin-Seite
$lang['intro']      = 'Wählen Sie die gewünschten Bestandteile aus. Klicken Sie auf <em>Vorschau</em>, um die Dateiliste und Gesamtgröße anzuzeigen, und dann auf <em>Tar.gz herunterladen</em>, um das Archiv zu erhalten.';
$lang['warn_title'] = 'Hinweis: vertrauliche Inhalte.';
$lang['warn_body']  = 'Das Archiv kann Passwort-Hashes (<code>conf/users.auth.php</code>), ACL-Regeln und alle in <code>conf/local.php</code> gespeicherten Zugangsdaten enthalten (Datenbankpasswörter, SMTP-Passwörter, API-Schlüssel). Behandeln Sie die heruntergeladene Datei wie einen Satz von Zugangsdaten.';

// Feldgruppen
$lang['fs_content'] = 'Wiki-Inhalte';
$lang['fs_code']    = 'Konfiguration & Code';

// Kontrollkästchen
$lang['opt_pages']       = 'Seiten (data/pages)';
$lang['opt_media']       = 'Mediendateien (data/media)';
$lang['opt_meta']        = 'Seiten-Metadaten (data/meta)';
$lang['opt_media_meta']  = 'Medien-Metadaten (data/media_meta)';
$lang['opt_attic']       = 'Seitenrevisionen (data/attic) – kann sehr groß sein';
$lang['opt_media_attic'] = 'Medienrevisionen (data/media_attic)';
$lang['opt_index']       = 'Suchindex (data/index) – kann neu aufgebaut werden';
$lang['opt_conf']        = 'Konfiguration (conf/) – enthält vertrauliche Daten';
$lang['opt_plugins']     = 'Plugin-Quellcode (lib/plugins/)';
$lang['opt_tpl']         = 'Template-Quellcode (lib/tpl/)';

// Schaltflächen
$lang['btn_preview']  = 'Vorschau';
$lang['btn_download'] = 'Tar.gz herunterladen';

// Vorschaubereich
$lang['preview_head']    = 'Vorschau';
$lang['preview_summary'] = '%d Dateien, %s unkomprimiert.';
$lang['col_section']     = 'Bereich';
$lang['col_files']       = 'Dateien';
$lang['col_size']        = 'Größe';
$lang['preview_hint']    = 'Klicken Sie oben auf <em>Tar.gz herunterladen</em>, um das Archiv zu erstellen und herunterzuladen (die komprimierte Größe ist in der Regel kleiner).';

// Fehlermeldungen
$lang['err_post']    = 'Site Backup: Der Download muss per POST übermittelt werden.';
$lang['err_admin']   = 'Site Backup: Administrator-Zugriff erforderlich.';
$lang['err_empty']   = 'Site Backup: Es wurden keine Bereiche ausgewählt.';
$lang['err_tmp']     = 'Site Backup: Das temporäre Verzeichnis ist nicht beschreibbar: %s';
$lang['err_create']  = 'Site Backup: Das Archiv konnte nicht erstellt werden: %s';
$lang['err_archive'] = 'Site Backup: Das Archiv war leer oder konnte nicht geschrieben werden.';
