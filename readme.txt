=== WP2Git ===
Contributors: tungstendigital.net
Tags: github, backup, deploy, sync, version-control
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Two-way sync between your wp-content folder and a private GitHub repository.

== Description ==

WP2Git connects a WordPress site to a private GitHub repository and keeps
wp-content in sync in both directions:

* **Backup (WP to GitHub):** changed files in wp-content are committed to the
  repo on a schedule or on change, using incremental commits over the GitHub
  REST API (no git binary required). Large pushes are chunked across background
  jobs.
* **Update (GitHub to WP):** pushes to the configured branch are applied back to
  the live site automatically via a signed webhook, with three-way conflict
  resolution that never discards your local edits silently.

Authenticate with either a fine-grained Personal Access Token or a GitHub App
(short-lived installation tokens). Works on single-site and network-activated
multisite (one shared connection for the network's wp-content).

= Security notes =

Auto-apply means anyone who can push to the connected branch can run code on
your server. WP2Git enforces a private-repo-only policy, verifies every webhook
with an HMAC signature, and supports a pusher allowlist — but the most important
control is **GitHub branch protection with required reviews** on the synced
branch. Database content is never synced; only files under wp-content.

When files change on both sides, the GitHub version wins but your local copy is
preserved under wp-content/.wp2git/conflicts and can be restored from the
Conflicts admin screen.

== Installation ==

1. Upload the `wp2git` folder to `/wp-content/plugins/`.
2. (Optional but recommended) define a unique key in wp-config.php:
   `define('WP2GIT_KEY', '...a long random string...');`
3. Activate the plugin.
4. Go to the WP2Git admin screen and follow the connect wizard.

== Frequently Asked Questions ==

= Does this require a private repository? =

Yes. Because the plugin can apply commits to your live site, WP2Git refuses to
connect to a public repository. Use a private repo and a fine-grained token (or
a GitHub App installation) scoped to just that repository.

= Will GitHub commits really run on my site automatically? =

Only if auto-apply is on (the default). When it is, a push to the connected
branch is applied to wp-content automatically, which means anyone who can push
to that branch can run code on your server. Protect the branch on GitHub with
required reviews, and consider the pusher allowlist. If you only want backups,
turn auto-apply off under "Auto-apply security" — that switches the plugin to
backup-only mode and nothing is ever pulled from GitHub onto your site.

= Does it need the git binary or shell access? =

No. All GitHub access is over HTTPS via the REST Git Data API, so it works on
managed and locked-down hosts with no git, exec(), or SSH.

= My backups or updates don't run on their own. What's wrong? =

Scheduled backups and webhook-driven updates rely on WP-Cron, which only fires
when your site gets traffic (or when a real system cron hits wp-cron.php). On
low-traffic sites, or where loopback requests are blocked, use the "Back up now"
and "Check GitHub for updates now" buttons — they run immediately — or set up a
real cron. See Tools → Site Health for loopback issues.

= Is my access token stored safely? =

The token is encrypted at rest (AES-256-GCM) using a key derived from the
WP2GIT_KEY constant (or your WordPress salts as a fallback). It is never logged
and never returned through the REST API.

= Does it back up my database? =

No. WP2Git syncs files under wp-content only. Database content, secrets, and PII
stay out of the repository by design.

== Changelog ==

= 1.0.0 =
* First stable release.
* WP-Cron fallback now honors the configured backup interval (including Weekly
  and Every two weeks) instead of always running hourly.
* Deactivation now cancels pending Action Scheduler jobs as well as WP-Cron
  events, leaving no orphaned background work.

= 0.1.4 =
* Added Weekly and Every-two-weeks scheduled backup options. Changing the
  schedule now re-applies the new cadence immediately.
* The auto-apply / branch-protection notice now sits next to the sync buttons
  and reflects the current mode (it no longer implies auto-apply is on when the
  site is in backup-only mode).
* Clearer two-line explanation of the auto-apply toggle.
* Disconnect is now a red button under a "Danger zone" heading with a warning
  describing exactly what it removes.

= 0.1.3 =
* New auto-apply toggle (Auto-apply security → Sync direction). Turn it off for
  backup-only mode: wp-content is still backed up to GitHub, but nothing is ever
  pulled from GitHub onto the live site. Enforced at the apply layer, so the
  webhook receiver, manual "Check for updates" button, and any queued job all
  refuse to write incoming changes while backup-only mode is on. Defaults to on
  (two-way) so existing installs are unchanged.

= 0.1.2 =
* New "Check GitHub for updates now" button runs a pull synchronously: it fetches
  the current branch head and applies it to the site immediately, reporting how
  many files were updated/deleted and whether any conflicts were preserved. The
  inbound (GitHub to WP) direction no longer depends on a webhook or the
  background queue to be exercised manually.

= 0.1.1 =
* "Back up now" now runs synchronously within the request (draining batches up
  to a time budget) and reports the real result — files backed up and the commit
  hash, "already up to date", or the actual error — instead of only queuing a
  background job. A manual backup now completes in one click even when WP-Cron
  or loopback requests are not firing. Large imports that exceed the request
  budget still finish on the background queue.

= 0.1.0 =
* Two-way sync engine: incremental chunked push, webhook-driven pull, loop
  prevention, deterministic three-way conflict resolution.
* Authentication via fine-grained PAT or GitHub App (installation tokens).
* On-change backups, scheduled backups, busy-retry under lock contention.
* Conflict review/restore admin screen; pusher allowlist; private-repo
  enforcement; HMAC-verified webhooks.
* Single-site and network-activated multisite support; i18n-ready.
