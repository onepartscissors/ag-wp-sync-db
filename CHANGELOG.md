# Changelog

All notable changes to AG WP Sync DB. Versions follow [semver](https://semver.org/).

## [2.1.0] - 2026-11-12

### Added
- WP-CLI command `wp agsdb` with `profiles`, `check`, `pull`, `push`, `export` and `log` subcommands, so syncs can be run from scripts, Jenkins and AI agents. Built on the flow of the original wp-sync-db-cli addon.
  - Pull and push only run with `--yes`. Without it they do a dry run.
  - Overwriting a database on a site that looks like production needs `--allow-live`.
  - `--format=json` output with exit code 1 on errors.
  - Connection errors during a table transfer are retried twice.
  - One command-line sync per site at a time (file lock).
  - Each run is logged in the `ag_wpsdb_cli_log` option (kept across pulls) and can be posted to `AG_WPSDB_NOTIFY_URL`.
  - `export` writes to `--output` (default: current folder), not the public uploads folder.
- AGENTS.md with instructions for AI agents, and CLAUDE.md pointing to it.

### Security
- No more silent retry over plain http when an https connection check fails. Before, the whole migration could then run unencrypted. Can be turned back on with the `ag_wpsdb_allow_http_fallback` filter or `AG_WPSDB_ALLOW_HTTP_FALLBACK`.
- Data received from the other site is unserialized with `allowed_classes => false`.

### Fixed
- The error log (`wpsdb_error_log`) grew without limit and was loaded on every page. It is now capped at 100 KB and no longer autoloaded (existing logs are trimmed once).
- Writing an empty string to a gzip export was reported as a write failure (hidden in the admin screen).
- `close()` on an already closed export file (hidden in the admin screen).

## [2.0.1] - 2026-11-12

### Fixed
- False "Media Files addon is out-of-date" notice when AG WP Sync DB Media Files is active.

### Security
- Export and backup files in `uploads/wp-sync-db/` now use a 32-character random part in the file name instead of 5. On nginx servers the `.htaccess` deny file has no effect, so these files were reachable by URL if the name could be guessed.

## [2.0.0] - 2026-11-12

First release of the fork, based on [wp-sync-db](https://github.com/wp-sync-db/wp-sync-db) 1.5.

### Changed
- Renamed to AG WP Sync DB (folder and main file `ag-wp-sync-db/ag-wp-sync-db.php`, text domain `ag-wp-sync-db`).
- The plugin now loads on `init` instead of `plugins_loaded`, which fixes the WordPress 6.7 "translation loading triggered too early" notice.
- Activating the plugin deactivates WP Sync DB and WP Sync DB Media Files. If both are active, this plugin stays off and shows a notice instead of causing a fatal error.
- Still reports protocol version 1.5 to remote sites so it can sync with sites running WP Sync DB 1.5. The real version is sent as `ag_plugin_version`.

### Performance
- Default maximum request size raised from 1 MB to 8 MB, capped by `post_max_size`. Sites on the old 1 MB default are raised once.
- Find and replace skips values that don't contain any find string, instead of trying `unserialize()` and `json_decode()` on every value.
- SQL chunks are imported inside a transaction.
- Maximum INSERT statement size raised from 50 KB to 1 MB.
- Rows read per SELECT raised from 100 to 1,000, adjusted down automatically for tables with large rows.
- Pull responses are gzip compressed when the requesting site supports it.

### Fixed
- PHP 8.2 deprecation: dynamic property `WPSDB::$checkbox_options`.
- PHP 8 fatal errors: `each()` in `backquote()`, `mysql_get_server_info()` in the diagnostic log, and a call to the undefined function `e()` when an export file can't be deleted.
- PHP 8: cancelling a migration with an empty temporary prefix could drop every table in the database, because `strpos()` now matches an empty string.
- PHP 8: find and replace on serialized data containing classes that aren't loaded threw an uncaught `Error`.
- Undefined variable warnings (`$safe_mode`, `$destination`, `$clean_tables`) and null-argument deprecations.
- Push errors were added to the response twice.
- Subdomain multisite check used a bitwise `&` instead of `&&`.

### Security
- Signatures are compared with `hash_equals()`.
- Secret keys are generated with `random_int()` instead of `rand()`.
- The plugin compatibility, plugin blacklist and cancel migration AJAX actions now check user capability.
- `wpsdb-profile` URL parameter is cast to an integer before being printed into JavaScript.
- An `.htaccess` deny file is added to the export folder in uploads when it is created.

### Removed
- `test.sh` (PHP lint script).
