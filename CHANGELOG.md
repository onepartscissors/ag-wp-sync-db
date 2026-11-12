# Changelog

All notable changes to AG WP Sync DB. Versions follow [semver](https://semver.org/).

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
