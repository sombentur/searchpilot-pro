# SearchPilot Pro

A WordPress plugin that looks after the technical SEO of a site on its own: it tells
Google, Bing and IndexNow about new and changed pages through their **official** APIs,
keeps XML sitemaps and structured data healthy, watches how every page is indexed, finds
content opportunities in Search Console data, builds safe internal links, finds on-page
problems and fixes the safe ones itself — with a preview, a re-check and an undo for every
change.

It stays inside Google and Bing webmaster guidelines: no fake backlinks, no keyword
stuffing, no cloaking, no automated search queries. It never promises rankings and never
rewrites your writing without approval.

## Who it is for

- Site owners and small newsrooms who publish often and do not have an SEO specialist.
- People who already use Rank Math or Yoast: SearchPilot runs beside them in *companion
  mode* and only takes over the parts you hand to it.
- Non-English sites: titles and descriptions are measured by rendered width, so Kannada,
  Hindi and other Indic scripts are not wrongly reported as "too long".

## Requirements

| | Minimum |
|---|---|
| WordPress | 6.2 (tested up to 7.1) |
| PHP | 7.4 (8.2 or newer recommended) |
| PHP extensions | json, mbstring, dom, and openssl or sodium |
| Background jobs | WP-Cron (default) or a server cron |

No build step, no Composer, no external services other than the search-engine APIs you
choose to connect.

## Install

1. Download `searchpilot-pro.zip` from the **Releases** page of this repository (or build
   it yourself, see below).
2. In WordPress go to **Plugins → Add Plugin → Upload Plugin**, choose the zip, click
   **Install Now**, then **Activate**.
3. The **Setup Wizard** opens. Follow it step by step:
   - **Website audit** — fixes anything red first (search engines blocked, permalinks,
     HTTPS, sitemaps).
   - **Google Search Console** — optional but recommended. You create a free OAuth client
     in your own Google Cloud project and paste its ID and secret; the exact clicks are in
     [docs/GOOGLE-SETUP.md](docs/GOOGLE-SETUP.md). Nothing is built in: SearchPilot uses
     *your* Google project.
   - **Bing Webmaster** — paste your API key from Bing Webmaster Tools.
   - **IndexNow** — generated for you, nothing to sign up for.
   - **Automation mode** — *Manual* (SearchPilot only reports), *Assisted* (it prepares a
     fix, you click once) or *Autopilot* (safe fixes run on their own; risky ones still wait
     for you).
4. Open **SearchPilot → SEO Audit**. Every issue says what it is, why it matters and what
   will be changed; the activity log and **Undo** are under **SearchPilot → Automation**.

The full guide with screenshots of every screen is in
[docs/INSTALLATION.md](docs/INSTALLATION.md).

## What you must set up yourself

| Item | Where |
|---|---|
| Google OAuth client ID and secret (free) | Google Cloud Console → see docs/GOOGLE-SETUP.md |
| Bing Webmaster API key (free) | Bing Webmaster Tools → Settings → API access |
| Which plugin owns titles, descriptions, schema, sitemaps | SearchPilot → Settings → Companion mode |

Keys are stored encrypted in your WordPress database (or, if you prefer, as constants in
`wp-config.php`; see docs/INSTALLATION.md). They are never sent anywhere except to Google
and Microsoft.

## Known limits

- Pages built with page builders (Elementor, Divi, …) are audited but not edited; fixes
  for them show a manual step.
- "Crawled — currently not indexed" and similar statuses are Google's decisions; the
  plugin reports and resubmits, it cannot force indexing.
- Image fixes need the GD or Imagick PHP extension with WebP support (most hosts have it).
- ALT text is filled only from captions, titles or descriptive file names; the plugin never
  invents descriptions.
- Tested on single sites and Multisite with MySQL/MariaDB; SQLite installs are not
  supported.

## Development

```bash
composer install          # PHPUnit, WordPress Coding Standards
composer test             # unit tests (tests/unit)
php tests/run.php         # the same tests without Composer
composer lint             # coding standards
python bin/make-pot.py    # regenerate languages/searchpilot-pro.pot
python bin/build-zip.py   # build ../dist/searchpilot-pro-<version>.zip
```

Architecture, hooks and the REST API are described in [docs/DEVELOPER.md](docs/DEVELOPER.md).

## Credits

Author: [sombentur](https://github.com/sombentur)

Built with AI-assisted development (Claude).

## License

MIT — see [LICENSE](LICENSE).
