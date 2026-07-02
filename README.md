# WP2Git

Two-way sync between your WordPress `wp-content` folder (and, optionally, your
Posts/Pages) and a private GitHub repository.

Developed by **[Tungsten Digital](https://tungstendigital.net/)**. Need a
custom WordPress plugin built for your project? [Get in touch](https://tungstendigital.net/).

> **Requires:** WordPress 6.4+, PHP 8.1+ · **License:** [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html)

---

## Table of contents

- [What it does](#what-it-does)
- [Security model](#security-model)
- [Installation](#installation)
- [Getting a GitHub token](#getting-a-github-token)
- [Usage](#usage)
- [FAQ](#faq)
- [Development](#development)
- [Changelog](#changelog)

## What it does

WP2Git connects a WordPress site to a private GitHub repository and keeps
content in sync in both directions:

- **Backup (WordPress → GitHub)** — changed files in `wp-content` are
  committed to the repo on a schedule or on change, using incremental commits
  over the GitHub REST API. No `git` binary or shell access required, so it
  works on locked-down managed hosts. Large pushes are chunked across
  background jobs so they don't time out.
- **Update (GitHub → WordPress)** — pushes to the configured branch are
  applied back to the live site automatically via a signed webhook, with
  three-way conflict resolution that never silently discards a local edit.
- **Posts & Pages (optional)** — published posts/pages can also be exported to
  GitHub as readable Markdown files with YAML frontmatter, and edits made to
  those files on GitHub can be applied back to the matching post.
- **Manual, synchronous controls** — "Back up now" and "Check GitHub for
  updates now" (with a force-resync option) run immediately in the request and
  report a real result, rather than only queuing background work.

Authenticate with either a fine-grained Personal Access Token or a GitHub App
(short-lived installation tokens). Works on single-site and network-activated
multisite installs (one shared connection for the network's `wp-content`).

## Security model

Auto-apply means anyone who can push to the connected branch can run code on
your server — that's an accepted tradeoff for a deploy-style workflow, and it
shapes every design decision in this plugin:

| Control | What it does |
|---|---|
| **Private-repo enforcement** | Refuses to connect to a public repository. |
| **HMAC-verified webhooks** | Every webhook payload's `X-Hub-Signature-256` is checked with a constant-time comparison; unsigned or mismatched requests are rejected. |
| **Branch pinning** | Only pushes to the configured branch trigger an apply. |
| **Pusher allowlist** | Optionally restrict auto-apply to specific GitHub usernames (checked against both the pusher and every commit author). |
| **Backup-only mode** | Turn off auto-apply entirely — `wp-content` and content still back up to GitHub, but nothing is ever pulled or written back to the site. |
| **Path containment** | Every incoming write is validated against the in-scope directories with traversal/symlink protection before it touches disk. |
| **Conflict preservation** | When a file changed on both sides, the GitHub version wins but your local copy is saved under `wp-content/.wp2git/conflicts` and restorable from the Conflicts admin screen — nothing is silently lost. |
| **Encrypted credentials** | Tokens/keys are encrypted at rest (AES-256-GCM), never logged, never returned via the REST API. |

The most important control is still external: **GitHub branch protection with
required reviews** on the synced branch. WP2Git's gates keep the site safe from
malformed or unauthorized input — human review of *what* gets merged is still
on you.

Database content is never synced except for the optional, explicit Posts/Pages
export — settings, users, secrets, and PII stay out of the repository by
design.

## Installation

1. Download the latest release ZIP (or build one — see [Development](#development)).
2. In WordPress: **Plugins → Add New → Upload Plugin**, and upload the ZIP.
3. *(Optional but recommended)* Define a unique encryption key in
   `wp-config.php` before connecting:
   ```php
   define('WP2GIT_KEY', '...a long random string...');
   ```
4. Activate the plugin.
5. Go to **WP2Git** in the admin menu and follow the connect wizard.

## Getting a GitHub token

WP2Git needs a **private** repository and a **fine-grained Personal Access
Token** scoped to just that repo:

1. Create a private GitHub repository (it can be empty).
2. Go to **GitHub → Settings → Developer settings → Personal access tokens →
   Fine-grained tokens** and generate a new token.
3. Set **Repository access** to "Only select repositories" and pick your repo.
4. Grant these **Repository permissions** (leave everything else at "No access"):

   | Permission | Access |
   |---|---|
   | Contents | Read and write |
   | Webhooks | Read and write |
   | Metadata | Read-only *(granted automatically)* |

5. Generate the token and paste it into the WP2Git connect wizard.

Prefer not to manage token expiry? Use the **GitHub App** auth option instead
— it mints short-lived installation tokens automatically.

## Usage

- **Back up now** — pushes local changes to GitHub immediately (drains in
  batches within the request, so a manual backup completes and reports a
  result even if WP-Cron isn't running).
- **Check GitHub for updates now** — pulls and applies the latest commit
  immediately. Tick **Force re-apply every file** to resync the whole branch
  against disk, ignoring the cursor — useful if local files have drifted.
- **Scheduled backups** — choose Manual, 15 minutes, Hourly, Daily, Weekly, or
  Every two weeks under **Backup**.
- **Database content** — enable Posts and/or Pages under **Backup → Database
  content** to export them to GitHub as Markdown files under
  `wp2git-content/`. With auto-apply on, edits to those files on GitHub are
  applied back to the matching post (title, excerpt, body). Removing a file on
  GitHub never deletes a post — it's simply re-exported.
- **Conflicts** — review and restore preserved local copies from the
  **Conflicts** admin screen.

## FAQ

**Does this require a private repository?**
Yes. Because the plugin can apply commits to your live site, WP2Git refuses to
connect to a public repository.

**Will GitHub commits really run on my site automatically?**
Only if auto-apply is on (the default). Protect the branch on GitHub with
required reviews, and consider the pusher allowlist. Turn auto-apply off for
backup-only mode, where nothing is ever pulled from GitHub onto your site.

**Does it need the `git` binary or shell access?**
No. All GitHub access is over HTTPS via the REST Git Data API, so it works on
managed and locked-down hosts.

**My backups or updates don't run on their own. What's wrong?**
Scheduled backups and webhook-driven updates rely on WP-Cron, which only fires
on site traffic (or a real system cron hitting `wp-cron.php`). Use the manual
"Back up now" / "Check GitHub for updates now" buttons, which run immediately,
or set up a real cron. Check **Tools → Site Health** for loopback issues.

**Is my access token stored safely?**
It's encrypted at rest (AES-256-GCM) with a key derived from `WP2GIT_KEY` (or
your WordPress salts as a fallback). Never logged, never returned via REST.

**Does it back up my database?**
Files under `wp-content` are the core sync. Posts and Pages are an optional,
explicit opt-in (see [Usage](#usage)). Everything else in the database stays
out of the repository by design.

## Development

```bash
composer install        # pulls dev deps: phpunit, brain/monkey, phpcs, etc.
composer test:unit       # fast unit suite (no WordPress boot required)
composer test:integration  # needs a WP test environment, e.g. wp-env
composer lint             # WordPress Coding Standards (project ruleset in .phpcs.xml.dist)
composer lint:fix         # auto-fix what phpcbf can
```

See [`tests/README.md`](tests/README.md) for what each suite covers.

To build a distributable plugin ZIP: run `composer install --no-dev` in a
clean copy of the repo (so `vendor/` only contains runtime dependencies), then
ZIP the working directory. **Use a ZIP tool that writes forward-slash paths**
— on Windows, PowerShell's `Compress-Archive` writes backslash-separated
entries that WordPress cannot install.

## Changelog

See [`readme.txt`](readme.txt) for the full version history (WordPress.org
plugin readme format) or the [Releases](../../releases) page.
