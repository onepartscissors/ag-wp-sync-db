# AG WP Sync DB

Push, pull and export WordPress databases between sites from the WordPress dashboard. Find and replace on URLs and file paths happens during the transfer, including inside serialized data.

Forked from [wp-sync-db/wp-sync-db](https://github.com/wp-sync-db/wp-sync-db) (version 1.5, last updated 2018).

## Why this fork exists

The original plugin is no longer maintained. Its README asks for a new maintainer. We use it on many sites and needed it to keep working. This fork exists to:

1. **Fix the errors.** On current PHP and WordPress versions the original shows notices and deprecation warnings on every admin page, for example:
   - `Function _load_textdomain_just_in_time was called incorrectly. Translation loading for the wp-sync-db domain was triggered too early` (WordPress 6.7+)
   - `Creation of dynamic property WPSDB::$checkbox_options is deprecated` (PHP 8.2+)

   It also contained code that is a fatal error on PHP 8 (`each()`, `mysql_get_server_info()`) in some code paths.
2. **Improve sync speed where we can.** See "What's faster" below.
3. **Make minor improvements.** Small security hardening and bug fixes, listed in [CHANGELOG.md](CHANGELOG.md).

How the plugin works has not changed. The screens, settings, migration profiles and the push/pull process are the same.

### Why not the Delicious Brains code?

The original README suggests rebuilding from the `delicious-brains` branch. That branch is WP Migrate DB Pro 1.6.1 from 2016. It is not much newer than 1.5. It also uses different option names and a different request format, so existing connections would have to be set up again. Current WP Migrate (2.x) is a full rewrite with push/pull only in the paid version. So this fork builds on wp-sync-db 1.5.

## Compatible with WP Sync DB

AG WP Sync DB keeps the same database option (`wpsdb_settings`) and the same request format as WP Sync DB 1.5. This means:

- Saved connection keys, migration profiles and settings carry over. Nothing needs to be set up again.
- A site running AG WP Sync DB can push to and pull from a site still running WP Sync DB 1.5, and the other way round. Sites can be switched over one at a time.
- Only one of the two can be active on a site. Activating AG WP Sync DB deactivates WP Sync DB and WP Sync DB Media Files automatically. If both end up active anyway, AG WP Sync DB stays off and shows an admin notice.

## What's faster

- **Fewer, larger requests.** The default maximum request size goes from 1 MB to 8 MB (capped by the server's `post_max_size`). Sites still on the old 1 MB default are raised once. A custom value set on the Settings tab is not changed.
- **Find and replace skips values that don't need it.** Before, every text value in the database went through `unserialize()` and `json_decode()`. Now values that don't contain any of the find strings are passed through unchanged.
- **Faster imports.** Each chunk of SQL is run inside one transaction instead of committing after every statement, and multi-row INSERT statements can be up to 1 MB instead of 50 KB.
- **Bigger read batches.** Rows are read 1,000 at a time instead of 100. Tables with large rows (post content, page builder data) automatically use smaller batches to limit memory use.
- **Compressed pull responses.** SQL sent back during a pull is gzip compressed when the requesting site supports it.
- **Faster media syncs** (with [AG WP Sync DB Media Files](https://github.com/onepartscissors/ag-wp-sync-db-media-files)): working out which files to copy no longer slows down sharply on large media libraries, and files in a pull are downloaded in parallel.

Each change can be turned off with a filter if a server has trouble with it:

| Filter | Default | What it controls |
|---|---|---|
| `ag_wpsdb_default_max_request` | `8388608` | Default maximum request size in bytes |
| `ag_wpsdb_use_transactions` | `true` | Run each SQL chunk in a transaction |
| `ag_wpsdb_compress_responses` | `true` | Gzip pull responses |
| `wpsdb_max_insert_string_len` | `1048576` | Maximum size of one INSERT statement |
| `wpsdb_rows_per_segment` | `1000` | Rows read per SELECT |
| `ag_wpsdb_parallel_media_downloads` | `true` | Parallel media downloads on pull |
| `ag_wpsdb_allow_http_fallback` | `false` | Retry over plain http when https fails (or define `AG_WPSDB_ALLOW_HTTP_FALLBACK`) |
| `ag_wpsdb_is_live_url` | | Decide whether a URL counts as production for `--allow-live` |

## Requirements

- PHP 7.4 or later (tested on PHP 8.x)
- WordPress 5.6 or later

## Installation

As a git submodule (how we install it):

```
git submodule add https://github.com/onepartscissors/ag-wp-sync-db.git web/wp-content/plugins/ag-wp-sync-db
git submodule add https://github.com/onepartscissors/ag-wp-sync-db-media-files.git web/wp-content/plugins/ag-wp-sync-db-media-files
```

Then activate AG WP Sync DB first, then AG WP Sync DB Media Files. With WP-CLI:

```
wp plugin activate ag-wp-sync-db ag-wp-sync-db-media-files
```

The plugin is under Tools > Migrate DB (Network Admin > Settings > Migrate DB on multisite).

## Usage

1. On the remote site, open Tools > Migrate DB > Settings, tick "Accept pull requests" and/or "Accept push requests", and copy the connection info.
2. On the local site, open Tools > Migrate DB, choose Pull or Push, and paste the connection info.
3. Check the find and replace values and the table selection, then click Migrate DB.

## Command line and agents

Syncs can be run with WP-CLI, which also lets scripts, Jenkins and AI agents run them. Run `wp help agsdb` for the full reference.

```
wp agsdb profiles                         # list saved profiles
wp agsdb check dev                        # connect and report, changes nothing
wp agsdb pull dev --media --yes           # replace this site's database (and media) with the remote one
wp agsdb push dev --media --yes           # replace the remote database; backs it up first
wp agsdb export --find=//old.test --replace=//new.example --output=site.sql.gz
wp agsdb log                              # recent command-line syncs
```

- Profiles are the ones saved under Tools > Migrate DB, picked by number, name or part of the remote URL.
- Nothing changes without `--yes`. Without it, pull and push only check the connection.
- Overwriting a database on a site that looks like production needs `--allow-live`.
- `--format=json` prints one result object and exits with code 1 on errors.
- `--agent=<name>` records who ran the sync in `wp agsdb log`.
- Set `AG_WPSDB_NOTIFY_URL` in `wp-config.php` to have each command-line sync result posted there as JSON.

Instructions for AI agents are in [AGENTS.md](AGENTS.md). Moving a site from WP Sync DB: see [MIGRATING.md](MIGRATING.md).

## Credits

- Original plugin: [WP Sync DB](https://github.com/wp-sync-db/wp-sync-db) by Sean Lang, based on WP Migrate DB Pro by Delicious Brains.
- This fork: [Ash Glover](https://www.ashglover.co.za).

## License

GPL v2 or later. See [LICENSE](LICENSE).
