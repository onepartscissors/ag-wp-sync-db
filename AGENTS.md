# AGENTS.md

Instructions for AI agents (Claude Code, Codex, Cursor and others) working on this plugin, or using it to sync a site.

## Running a sync (using the plugin)

Use WP-CLI. Run `wp help agsdb` for the full, current reference.

```
wp agsdb profiles                                  # saved profiles: id, name, direction, remote, live?
wp agsdb check <profile> [--direction=push]        # connect and report, changes nothing
wp agsdb pull <profile> --yes [--media] [--backup] [--keep-active-plugins] --format=json
wp agsdb push <profile> --yes [--media] [--no-backup] --format=json
wp agsdb export [--find=a,b --replace=c,d] [--output=file.sql.gz] --format=json
wp agsdb log --format=json                         # recent command-line syncs on this site
```

- `<profile>` is the profile number, its name, or a unique part of the remote URL (`dev`).
- Pull replaces THIS site's database. Push replaces the REMOTE site's database.
- Without `--yes` nothing changes: pull and push only do a dry run.
- With `--format=json` the command prints one JSON object. `status` is `success`, `error` or `dry-run`. The exit code is 0 on success and 1 on error.
- Pass `--agent=<your name>` so the sync log shows who ran it.
- On ddev: `ddev wp agsdb ...`. On a server, run as the site's own user: `sudo -u <site user> wp --path=<wordpress path> agsdb ...`.
- Profiles are created in the admin screen (Tools > Migrate DB). There is no command to create them, and the secret key never goes on the command line.

### Safety rules

- Overwriting a database that looks like production (anything not starting `dev.`, `staging.`, `test.` etc. and not `.ddev.site`, `.test`, `.local`, `localhost`) is refused unless `--allow-live` is passed. **Never pass `--allow-live` unless the human has asked for that exact sync in the current conversation.**
- Run `check` first when unsure what a profile does.
- Pulling copies the remote site's list of active plugins. Use `--keep-active-plugins` when the local site has a different plugin set.
- Backups (`--backup` on pull, on by default for push) go to `wp-content/uploads/wp-sync-db/` on the site being overwritten. These files are web-reachable on nginx. Delete them once they're no longer needed.
- Only one command-line sync runs per site at a time (file lock).

## Working on the code

### Do not break compatibility

Sites running this plugin sync with sites still running the original wp-sync-db 1.5. Never change these without a planned protocol bump (once every site runs AG 2.x):

- The option names `wpsdb_settings` and `wpsdb_error_log`, and the profile array format inside `wpsdb_settings`.
- The AJAX action names (`wpsdb_*`, `wpsdb_*_remote_*`, `wpsdbmf_*`) and the POST field names and order used to build signatures (`create_signature()` joins the values in order).
- The reported protocol versions: `AG_WPSDB_PROTOCOL_VERSION` (1.5) and the media addon's `$GLOBALS['wpsdb_meta']['wp-sync-db-media-files']['version']` (1.1.4b1). The admin JS refuses to sync when these differ between sites.
- `$GLOBALS['wpsdb_meta']['wp-sync-db']`, the class names `WPSDB`, `WPSDB_Base`, `WPSDB_Addon`, `WPSDB_Media_Files`, and the `wpsdb_*` filters and actions.

### Code paths

- The admin screen runs a migration as a series of AJAX requests driven by `asset/js/script.js`. Handlers end with `end_ajax()`, which `exit`s in a web request but returns in CLI mode (`set_cli_migration()`).
- Because web requests exit early, code after a `transfer_chunk()` or `end_ajax()` call never ran in the original. The CLI does reach it. Bugs hidden this way have been found before (`close()` on a closed file, empty `gzwrite()`). Test both paths.
- `class/wpsdb-cli.php` mirrors the JS flow: verify connection, initiate, backup stage, migrate stage, media (via the `wpsdb_cli_finalize_migration` filter), finalize.

### Versions and changelog

- Bump the version in `ag-wp-sync-db.php` (header and `AG_WPSDB_VERSION`) using semver, and add an entry to `CHANGELOG.md` for every change.
- The media addon has its own version in `ag-wp-sync-db-media-files.php` and `version.php` (`AG_WPSDB_MF_VERSION`).
- Tag releases `vX.Y.Z` and push the tag.

### Testing

Use a local WordPress site (for example ddev) and a remote development site, both running this plugin and the media addon, with a saved profile named `dev` that points at the remote site. Never use a production site for testing.

1. `php -l` every changed PHP file.
2. Admin screen: pull from dev, push to dev (with backup and media), export to file.
3. CLI: `ddev wp agsdb check dev`, `pull dev --media --backup --yes`, `push dev --media --yes`, `export`, and a failure case (bad profile).
4. Check the PHP logs (`ddev logs -s web`) for warnings from `ag-wp-sync-db`.

Before replacing a database, back it up. After testing, delete any backup files left in `wp-content/uploads/wp-sync-db/`.
