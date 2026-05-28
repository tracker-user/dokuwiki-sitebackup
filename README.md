# Site Backup plugin for DokuWiki

An admin plugin for [DokuWiki](https://www.dokuwiki.org/) that builds a `tar.gz` of selected wiki content and code, streams it to your browser, and deletes it from the server. Nothing persists on disk after the download finishes.

Built for and tested against DokuWiki `2025-05-14b "Librarian"`.

## Why another backup plugin?

DokuWiki has the well-known [`backup`](https://www.dokuwiki.org/plugin:backup) plugin by Terence J. Grant, which solves the same problem. This plugin exists because:

- **The archive is never left on the server.** The `backup` plugin writes the tar to `data/media/wiki/backup/` and relies on the admin correctly configuring an ACL on that namespace; the plugin page warns that "It is important to secure the backup namespace by ACLs otherwise your backup files can be downloaded by anyone". Site Backup instead writes to `data/tmp/` (which DokuWiki already protects with a deny-all `.htaccess`), with a random filename, and `unlink()`s the file as soon as the download completes (or the connection is aborted, or PHP crashes).
- **The upstream `backup` plugin's author [retired](https://www.freelists.org/post/dokuwiki/Fwd-Retiring-my-DW-plugins) and archived the repo in 2020.** It still works via DokuWiki's legacy class alias, but it's no longer maintained.
- **Minimal footprint.** `admin.php` plus a small `PatchedTar.php` shim and language files for four locales (en, de, ru, ja). No external assets, no preference store. Easy to audit before installing on a wiki you share with other admins.

If you don't share the "ephemeral archive" requirement and you prefer something with a longer track record, the upstream `backup` plugin is a perfectly reasonable choice.

## What it backs up

Each option is an independent checkbox. The first three columns are usually all you want; the rest are situational.

| Option | Path inside archive | Notes |
| --- | --- | --- |
| Pages | `data/pages/` | Current wiki text. |
| Media | `data/media/` | Uploaded files. |
| Page metadata | `data/meta/` | Last-edit, subscriptions, changelogs. |
| Media metadata | `data/media_meta/` | |
| Page revisions | `data/attic/` | Old versions of pages. Can be large. |
| Media revisions | `data/media_attic/` | |
| Search index | `data/index/` | Rebuildable; off by default. |
| Configuration | `conf/` | Includes secrets — see below. `*.dist`, `*.example`, `*.bak` are filtered. |
| Plugins source | `lib/plugins/` | Useful for capturing local modifications. |
| Templates source | `lib/tpl/` | |

Always excluded: `data/cache/`, `data/tmp/`, `data/locks/`, `data/log/`, `_dummy` placeholders, `.DS_Store`, `Thumbs.db`, and `.git` / `.svn` / `.hg` directories anywhere.

To restore from an archive, follow the standard DokuWiki procedure: install a fresh DokuWiki of the same version, then untar your backup over the install root, overwriting existing files. You should be able to log in immediately with your previous credentials.

## Security model

- **Admin-only.** DokuWiki's `AdminPlugin` framework enforces `auth_isadmin()` before `handle()` or `html()` is invoked, because the plugin declares `forAdminOnly()`. A second explicit check in the streaming method catches any framework bypass.
- **CSRF-protected.** Every action submission is validated with DokuWiki's `checkSecurityToken()`.
- **POST-only downloads.** The Download button submits as POST; GET / HEAD / pre-fetch requests are rejected. A stray link or curious co-admin pasting a URL cannot trigger a backup.
- **Random filename.** Temp archives are named `sitebackup_tmp_<16 hex chars>.tar.gz` — 64 bits of CSPRNG entropy.
- **Locked-down storage.** The archive is written to `$conf['tmpdir']` (default `data/tmp/`), which DokuWiki protects with a deny-all `.htaccess`. The file is `chmod 0600` immediately after creation, with a `umask(0077)` set during writes.
- **Auto-deleted.** The temp file is removed both at the end of the stream and via a `register_shutdown_function` callback, so it disappears even on connection abort or fatal PHP error.
- **Stale sweep.** Every page load deletes any leftover `sitebackup_tmp_*` files older than 1 hour, in case a previous run died before reaching its shutdown handler.

**The archive itself is sensitive.** If you include `conf/`, it may contain password hashes (`conf/users.auth.php`), ACL rules, and any credentials stored in `conf/local.php` (DB connection strings, SMTP passwords, API keys). Treat the downloaded file the same way you would treat your wiki's credentials and delete it from your local machine when you're done.

## Install

In your wiki:

1. **Admin → Extension Manager → Manual Install**
2. Upload `sitebackup.zip`, click **Install**
3. Refresh the Admin page; a **Site Backup** entry appears in the Additional Plugins section

Or extract the zip into `lib/plugins/sitebackup/` directly if you have file access.

## Use

1. Open **Site Backup** from the Admin menu.
2. Tick the components you want.
3. Click **Preview** to see the file list and total size per section.
4. Click **Download tar.gz** to receive the archive in your browser. The download filename is `<hostname>-backup-<timestamp>.tar.gz`; the same directory structure appears as a single top-level directory inside the archive.

For very large wikis where the request might time out, run two passes — for example, one without `attic`/`media_attic`, then a second pass with only those checked.

## Uninstall

Same path: **Admin → Extension Manager**, find **Site Backup** under Installed Extensions, click **Uninstall**.

## Compatibility

Tested on DokuWiki `2025-05-14b "Librarian"`. Should work on Greebo / Hogfather / Igor / Jack Jackrum / Kaos as well — the only DokuWiki APIs used are `AdminPlugin`, `dokuwiki\Form\Form`, `auth_isadmin()`, `checkSecurityToken()`, `msg()`, and the bundled `splitbrain\PHPArchive\Tar`, all of which have been stable. No external dependencies; uses only what ships with DokuWiki.

PHP 7.4 or newer (uses array destructuring in `foreach` and `??`).

## Notes on bundled patches

`PatchedTar.php` is a 30-line subclass of `splitbrain\PHPArchive\Tar` that fixes a bug in the version of `splitbrain/php-archive` vendored with DokuWiki Librarian: `Tar::writeRawFileHeader()` overwrites the mtime with the size, so every file in a generated tar reads as 1970-01-01 with a size-derived offset. Fixed in [splitbrain/php-archive PR #38](https://github.com/splitbrain/php-archive/pull/38) but DokuWiki hasn't bumped the vendor lib yet. Once it does, `PatchedTar.php` can be deleted and `admin.php` switched back to using `splitbrain\PHPArchive\Tar` directly.

## License

GPL-2.0-or-later, matching DokuWiki itself.
