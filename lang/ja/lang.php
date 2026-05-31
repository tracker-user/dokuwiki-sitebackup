<?php

$lang['menu'] = 'サイトバックアップ';

// 管理ページ
$lang['intro']      = '含めるコンポーネントを選択し、<em>プレビュー</em>をクリックしてファイル一覧と合計サイズを確認した後、<em>tar.gz をダウンロード</em>をクリックしてアーカイブを取得してください。';
$lang['warn_title'] = '注意：機密データが含まれる場合があります。';
$lang['warn_body']  = 'アーカイブにはパスワードハッシュ（<code>conf/users.auth.php</code>）、ACL ルール、<code>conf/local.php</code> に保存された認証情報（DB パスワード、SMTP パスワード、API キーなど）が含まれることがあります。ダウンロードしたファイルは認証情報と同様に取り扱ってください。';

// フィールドセット
$lang['fs_content'] = 'Wiki コンテンツ';
$lang['fs_code']    = '設定とコード';

// チェックボックス
$lang['opt_pages']       = 'ページ (data/pages)';
$lang['opt_media']       = 'メディアファイル (data/media)';
$lang['opt_meta']        = 'ページメタデータ (data/meta)';
$lang['opt_media_meta']  = 'メディアメタデータ (data/media_meta)';
$lang['opt_attic']       = 'ページ履歴 (data/attic) — 大容量になる可能性あり';
$lang['opt_media_attic'] = 'メディア履歴 (data/media_attic)';
$lang['opt_index']       = '検索インデックス (data/index) — 再構築可能';
$lang['opt_conf']        = '設定ファイル (conf/) — 機密情報を含む';
$lang['opt_plugins']     = 'プラグインのソースコード (lib/plugins/)';
$lang['opt_tpl']         = 'テンプレートのソースコード (lib/tpl/)';

// ボタン
$lang['btn_preview']  = 'プレビュー';
$lang['btn_download'] = 'tar.gz をダウンロード';

// プレビューセクション
$lang['preview_head']    = 'プレビュー';
$lang['preview_summary'] = '%d ファイル、未圧縮 %s。';
$lang['col_section']     = 'セクション';
$lang['col_files']       = 'ファイル数';
$lang['col_size']        = 'サイズ';
$lang['preview_hint']    = '上の <em>tar.gz をダウンロード</em> をクリックしてアーカイブを作成・ダウンロードしてください（圧縮後のサイズは通常より小さくなります）。';

// エラーメッセージ
$lang['err_post']    = 'Site Backup: ダウンロードは POST で送信する必要があります。';
$lang['err_admin']   = 'Site Backup: 管理者権限が必要です。';
$lang['err_empty']   = 'Site Backup: 何も選択されていません。';
$lang['err_tmp']     = 'Site Backup: 一時ディレクトリに書き込みできません: %s';
$lang['err_create']  = 'Site Backup: アーカイブを作成できませんでした: %s';
$lang['err_archive'] = 'Site Backup: アーカイブが空か、書き込みに失敗しました。';
