# Migrating from WP Sync DB to AG WP Sync DB

AG WP Sync DB is a drop-in replacement for [WP Sync DB](https://github.com/wp-sync-db/wp-sync-db) 1.5 and [WP Sync DB Media Files](https://github.com/wp-sync-db/wp-sync-db-media-files).

## What carries over

- Saved connection keys, migration profiles and settings (the `wpsdb_settings` option is shared).
- Syncing with sites still on WP Sync DB 1.5: both report the same protocol version, so you can switch sites one at a time.
- The compatibility mu-plugin (`mu-plugins/wp-sync-db-compatibility.php`), if you installed it.

## Steps for one site

1. Back up the database (`wp db export`).
2. Install both plugins next to the old ones:
   - git submodules:
     ```
     git rm wp-content/plugins/wp-sync-db wp-content/plugins/wp-sync-db-media-files
     git submodule add https://github.com/onepartscissors/ag-wp-sync-db.git wp-content/plugins/ag-wp-sync-db
     git submodule add https://github.com/onepartscissors/ag-wp-sync-db-media-files.git wp-content/plugins/ag-wp-sync-db-media-files
     ```
     Also update any `.gitignore` whitelist lines and build or deploy scripts that mention `wp-sync-db`.
   - or a plain `git clone` of both repos into `wp-content/plugins/`.
3. Activate the new plugins:
   ```
   wp plugin activate ag-wp-sync-db ag-wp-sync-db-media-files
   ```
   This deactivates WP Sync DB and WP Sync DB Media Files automatically. On multisite add `--network`.
4. Check that `wp agsdb profiles` lists your old profiles and that Tools > Migrate DB looks the same as before.
5. Test a pull into a development copy.
6. When everything works, delete the old `wp-sync-db` and `wp-sync-db-media-files` folders, plus old export and backup files in `wp-content/uploads/wp-sync-db/`.

## Tips

- Switch a development site and its live site in the same session. A pull copies the remote site's list of active plugins, so pulling from a site still on WP Sync DB turns the old plugin back on. Use `--keep-active-plugins` (CLI) or tick "Do not migrate the 'active_plugins' setting" if your sites have different plugin sets.
- To roll back, activate the old plugins again (`wp plugin activate wp-sync-db wp-sync-db-media-files`). The settings are shared, so nothing is lost.
- If you deploy with composer and `johnpbloch/wordpress-core`, a core update deletes and recreates the WordPress folder. Make sure your scripts restore plugins that aren't installed by composer.
