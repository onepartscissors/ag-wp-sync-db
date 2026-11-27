<?php
$t = 'ag-wp-sync-db';
?>
<div class="help-tab content-tab">
	<div class="support">
		<h3><?php _e( 'Support', $t ); ?></h3>
		<p><?php printf( __( 'Please report bugs or ask questions in the <a href="%s" target="_blank">GitHub issue tracker</a>.', $t ), 'https://github.com/onepartscissors/ag-wp-sync-db/issues' ); ?></p>
	</div>

	<div class="guide">
		<h3><?php _e( 'How to use AG WP Sync DB', $t ); ?></h3>

		<h4><?php _e( 'Words used below', $t ); ?></h4>
		<ul>
			<li><?php _e( '<strong>This site</strong>: the site whose dashboard you are using now.', $t ); ?></li>
			<li><?php _e( '<strong>Remote site</strong>: the other site you sync with.', $t ); ?></li>
			<li><?php _e( '<strong>Pull</strong>: copy the remote database into this site. This site\'s database is replaced.', $t ); ?></li>
			<li><?php _e( '<strong>Push</strong>: copy this site\'s database to the remote site. The remote database is replaced.', $t ); ?></li>
			<li><?php _e( '<strong>Export</strong>: save this site\'s database as an SQL file. Nothing is replaced.', $t ); ?></li>
		</ul>

		<h4><?php _e( '1. Install on both sites', $t ); ?></h4>
		<ol>
			<li><?php _e( 'Install and activate AG WP Sync DB on this site and on the remote site. A site still running WP Sync DB 1.5 also works as the remote site.', $t ); ?></li>
			<li><?php _e( 'To copy uploads as well, install and activate AG WP Sync DB Media Files on both sites.', $t ); ?></li>
		</ol>

		<h4><?php _e( '2. Allow the remote site to accept requests', $t ); ?></h4>
		<ol>
			<li><?php _e( 'On the remote site, go to Tools &gt; Migrate DB and click the <strong>Settings</strong> tab.', $t ); ?></li>
			<li><?php _e( 'Tick <strong>Accept pull requests</strong> if you will pull from it.', $t ); ?></li>
			<li><?php _e( 'Tick <strong>Accept push requests</strong> only if you will push to it. Leave this off on live sites unless you mean to overwrite them.', $t ); ?></li>
			<li><?php _e( 'Copy everything in the <strong>Connection Info</strong> box: the site URL on the first line and the secret key on the second.', $t ); ?></li>
		</ol>
		<p><?php _e( 'Anyone with the connection info can sync with that site. Click <strong>Reset API Key</strong> to make the old connection info stop working; saved profiles on other sites then need the new connection info.', $t ); ?></p>

		<h4><?php _e( '3. Pull the remote database into this site', $t ); ?></h4>
		<ol>
			<li><?php _e( 'On this site, go to Tools &gt; Migrate DB. If you have saved profiles, click <strong>Nope, let\'s start fresh...</strong> or pick a profile.', $t ); ?></li>
			<li><?php _e( 'Choose <strong>Pull</strong>.', $t ); ?></li>
			<li><?php _e( 'Paste the remote site\'s connection info into the box and click <strong>Connect</strong>. If the remote site is behind a server password, <strong>Username</strong> and <strong>Password</strong> boxes appear: fill them in and click <strong>Connect</strong> again.', $t ); ?></li>
			<li><?php _e( 'Check <strong>Find</strong> and <strong>Replace</strong>. The first row changes the remote URL to this site\'s URL, the second the remote file path to this site\'s file path. They are filled in for you; correct them if needed, and click <strong>Add Row</strong> for any other text to change.', $t ); ?></li>
			<li><?php _e( 'Choose the tables: <strong>Migrate all tables with prefix</strong>, or <strong>Migrate only selected tables below</strong> and select them.', $t ); ?></li>
			<li><?php _e( 'Optional: tick <strong>Exclude Post Types</strong> and select the post types to leave out (for example revisions).', $t ); ?></li>
			<li><?php _e( 'Optional, under <strong>Advanced Options</strong>: <strong>Replace GUIDs</strong> (only for a site that has never been live), <strong>Exclude spam comments</strong>, keep this site\'s active plugins (<strong>Do not migrate the \'active_plugins\' setting</strong>), and exclude transients.', $t ); ?></li>
			<li><?php _e( 'Tick <strong>Backup the local database before replacing it</strong> unless you are sure you will not need it.', $t ); ?></li>
			<li><?php _e( 'With the Media Files addon, tick <strong>Media Files</strong> to copy uploads that are missing or changed. Tick <strong>Remove local media files that are not found on the remote site</strong> only if this site should end up with exactly the remote site\'s uploads.', $t ); ?></li>
			<li><?php _e( 'Click <strong>Migrate DB</strong> and keep the page open until it says the migration is complete.', $t ); ?></li>
			<li><?php _e( 'Log in again if you are asked to: you now have the remote site\'s users and passwords.', $t ); ?></li>
		</ol>

		<h4><?php _e( '4. Push this site\'s database to the remote site', $t ); ?></h4>
		<ol>
			<li><?php _e( 'Make sure the remote site has <strong>Accept push requests</strong> ticked (step 2).', $t ); ?></li>
			<li><?php _e( 'Go to Tools &gt; Migrate DB on this site and choose <strong>Push</strong>.', $t ); ?></li>
			<li><?php _e( 'Paste the remote site\'s connection info and click <strong>Connect</strong>.', $t ); ?></li>
			<li><?php _e( 'Check <strong>Find</strong> and <strong>Replace</strong>: this site\'s URL and file path become the remote site\'s.', $t ); ?></li>
			<li><?php _e( 'Choose tables, post types and advanced options as for a pull.', $t ); ?></li>
			<li><?php _e( 'Tick <strong>Backup the remote database before replacing it</strong>. The backup is saved in the remote site\'s uploads folder.', $t ); ?></li>
			<li><?php _e( 'Click <strong>Migrate DB</strong> and wait until it says the migration is complete.', $t ); ?></li>
		</ol>

		<h4><?php _e( '5. Export the database to a file', $t ); ?></h4>
		<ol>
			<li><?php _e( 'Go to Tools &gt; Migrate DB and choose <strong>Export File</strong>.', $t ); ?></li>
			<li><?php _e( 'Tick <strong>Save as file to your computer</strong> to download it, and <strong>Compress file with gzip</strong> for a smaller file. Without the first option the file is saved in this site\'s uploads folder.', $t ); ?></li>
			<li><?php _e( 'Fill in <strong>Find</strong> and <strong>Replace</strong> if the file is for a site with another URL or file path.', $t ); ?></li>
			<li><?php _e( 'Choose the tables and click <strong>Migrate DB</strong>.', $t ); ?></li>
		</ol>

		<h4><?php _e( '6. Save a profile to repeat a sync', $t ); ?></h4>
		<ol>
			<li><?php _e( 'Before you click <strong>Migrate DB</strong>, tick <strong>Save Migration Profile</strong> and choose <strong>Create new profile</strong> (or an existing profile to update it). The button changes to <strong>Migrate DB &amp; Save</strong>.', $t ); ?></li>
			<li><?php _e( 'To save without running, click <strong>Save Profile</strong>.', $t ); ?></li>
			<li><?php _e( 'Next time, open Tools &gt; Migrate DB, click the profile under <strong>Would you like to use a saved migration profile?</strong>, check the settings and click <strong>Migrate DB</strong>.', $t ); ?></li>
		</ol>

		<h4><?php _e( '7. Run a sync from the command line', $t ); ?></h4>
		<p><?php _e( 'Saved profiles can be run with WP-CLI, for scripts and deploys. Run <code>wp help agsdb</code> for the full reference.', $t ); ?></p>
		<pre><code>wp agsdb profiles
wp agsdb check &lt;profile&gt;
wp agsdb pull &lt;profile&gt; --yes [--media] [--backup]
wp agsdb push &lt;profile&gt; --yes [--media]
wp agsdb export --output=file.sql.gz</code></pre>
		<p><?php _e( 'Without <code>--yes</code>, pull and push only do a dry run. Overwriting a site that looks like a live site also needs <code>--allow-live</code>.', $t ); ?></p>

		<h4><?php _e( 'After a sync', $t ); ?></h4>
		<ul>
			<li><?php _e( 'Clear any page or object cache on the site that was replaced.', $t ); ?></li>
			<li><?php _e( 'Delete backup and export files in <code>wp-content/uploads/wp-sync-db/</code> once you no longer need them. On some servers anyone can download them.', $t ); ?></li>
		</ul>

		<h4><?php _e( 'If something goes wrong', $t ); ?></h4>
		<ul>
			<li><?php _e( '<strong>Version Mismatch</strong>: update the plugin (and the Media Files addon) on both sites.', $t ); ?></li>
			<li><?php _e( '<strong>Addon Missing</strong>: activate AG WP Sync DB Media Files on the remote site, or untick Media Files.', $t ); ?></li>
			<li><?php _e( 'The connection is refused: check that the remote site accepts pull or push requests, and that the connection info was copied in full.', $t ); ?></li>
			<li><?php _e( 'A sync stops part way: tick <strong>Improve performance and reliability by not loading the following plugins for migration requests</strong> on the Settings tab of both sites, or lower the <strong>Maximum Request Size</strong>.', $t ); ?></li>
			<li><?php _e( 'Send the diagnostic info below with any bug report.', $t ); ?></li>
		</ul>
	</div>

	<div class="debug">
		<h3><?php _e( 'Diagnostic Info &amp; Error Log', $t ); ?></h3>
		<textarea class="debug-log-textarea" autocomplete="off" readonly></textarea>
		<a class="button clear-log js-action-link"><?php _e( 'Clear Error Log', $t ); ?></a>
	</div>
</div>
