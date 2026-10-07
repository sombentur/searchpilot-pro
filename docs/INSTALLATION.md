# SearchPilot Pro — Installation & Configuration

## Requirements

| | Minimum | Recommended |
|---|---|---|
| WordPress | 6.2 | latest (tested with 7.1) |
| PHP | 7.4 | 8.2 – 8.4 |
| PHP extensions | json, mbstring, dom, openssl **or** sodium | + curl |
| Database | MySQL 5.7 / MariaDB 10.3 | MySQL 8 / MariaDB 10.6 |
| Background jobs | WP-Cron (or a real server cron) | Server cron every 5 minutes, or Action Scheduler (bundled with WooCommerce) |

SearchPilot works on single sites and WordPress Multisite (network activation
installs it on every site; new sites are set up automatically).

## 1. Install

1. In WordPress go to **Plugins → Add Plugin → Upload Plugin**.
2. Choose the `searchpilot-pro-<version>.zip` you downloaded and click **Install Now**, then **Activate**.
3. You are taken to the **Setup Wizard** (you can reopen it any time from the
   notice on the SearchPilot screens or the *Run setup* link on the Plugins page).

> Test on a staging copy first if you can. SearchPilot never sends anything to search
> engines from a staging/development site (`wp_get_environment_type()` other than
> `production`), from a local address, from a site that discourages search engines, or from
> a copy whose address differs from the one where setup was finished (cloned staging sites
> stay silent even though they carry your credentials). Nothing is sent until the wizard is
> finished.

## 2. Setup wizard

1. **Website audit** — checks search-engine visibility (Settings → Reading), permalinks,
   HTTPS, the home page's robots directives and canonical, robots.txt, sitemaps, other SEO
   plugins, background jobs and credential encryption. Fix anything marked red first.
2. **Google Search Console** — optional but strongly recommended (clicks, impressions,
   queries, index status, sitemap submission). See [GOOGLE-SETUP.md](GOOGLE-SETUP.md).
   Two options:
   - *OAuth (recommended)*: create an OAuth client (Web application) in your own Google
     Cloud project, paste the Client ID/Secret, click **Connect with Google** and approve.
     SearchPilot asks only for the Search Console scope (plus the Indexing API scope if you
     enable it).
   - *Service account*: paste the JSON key and add the service-account e-mail as a user
     (Owner for the Indexing API) of your Search Console property.
3. **Bing Webmaster Tools** — optional. Paste the API key from Bing Webmaster Tools →
   Settings → API access. Your site must already be verified in Bing (you can import it from
   Search Console there).
4. **IndexNow** — on by default. SearchPilot generates the key and serves the key file at
   `https://your-site/<key>.txt` automatically (no file upload). Bing, Yandex, Seznam, Naver
   and Yep share IndexNow submissions. Google does not use IndexNow.
5. **Sitemaps** — images, video, Google News (news publishers), URLs per file. If Yoast,
   Rank Math, AIOSEO, SEOPress or another plugin already makes your sitemap, SearchPilot
   does **not** create a second one — it monitors and submits the existing one.
6. **Content types** — which post types and taxonomies should be indexed and listed in
   sitemaps, and who publishes the site (Organization/Person for structured data).
7. **Automation level** — Manual, Assisted (recommended) or Autopilot (see below).
8. **Notifications** — dashboard notices, e-mail alerts, weekly/monthly reports.

Click **Finish setup**. SearchPilot then builds its URL registry, checks your pages,
imports search data and starts monitoring in the background.

## 3. Automation modes

| | Manual | Assisted | Autopilot |
|---|---|---|---|
| Notify search engines of new/changed/removed URLs | asks (held in queue) | automatic | automatic |
| Submit sitemaps after changes | asks | automatic | automatic |
| Safe technical fixes (sitemap hygiene, cache purges, re-checks) | asks | automatic | automatic |
| 301 redirect when a published URL changes | asks | automatic | automatic |
| Fill **empty** meta descriptions / **empty** image ALT text | asks | asks | automatic |
| Insert internal links (within your limits, needs “Automatic insertion”) | asks | asks | automatic |
| Fix skipped heading levels and extra H1s; shorten over-long SEO titles (the headline is never changed; awkward word cuts always ask) | asks | asks | automatic |
| ALT text, featured image from the first image, over-long descriptions, site name on short page titles, removal of weak automatic links | asks | asks | automatic |
| Re-compress oversized JPEG/WebP images, convert oversized PNG images to WebP (original kept, undoable) | asks | asks | automatic |
| Remove article pages / ads.txt wrongly submitted as sitemaps from Search Console | asks | automatic | automatic |
| Related-articles list for pages nobody links to (needs “Add related-articles lists automatically”) | asks | asks | automatic |
| SEO titles that would become fixed text; switching off another plugin's IndexNow | asks | asks | asks |
| Canonical/robots/redirect changes on existing content | asks | asks | asks |
| Editorial content (titles, body copy) | asks | asks | asks |

Every change SearchPilot makes is recorded under **SearchPilot → Automation → Activity log**
and can be undone. Each task can also be overridden individually on that screen.

**How fixes work (1.1).** An issue shows **Preview fix** only when SearchPilot can repair it
safely; otherwise it shows the concrete manual step. The preview lists every change (before →
after). After applying, SearchPilot re-checks the live page: the issue is marked fixed only when
the problem is gone, and the change is undone automatically if it created a different problem
(for example two articles ending up with the same title). **Review fixes** on the SEO Audit screen
applies many fixes in the background with one **Undo this run** button. If a page cannot be
re-checked right away (the site was slow), the change is kept and re-checked within the hour.

## 4. Using SearchPilot alongside another SEO plugin (companion mode)

SearchPilot detects Yoast SEO, Rank Math, All in One SEO, SEOPress, The SEO Framework,
Slim SEO, Squirrly, SmartCrawl, Schema Pro, Schema & Structured Data for WP, Redirection,
Jetpack and others. For every front-end feature (title, meta description, canonical,
robots, Open Graph/Twitter, JSON-LD, sitemap, breadcrumbs, redirects, 404 monitor,
IndexNow) exactly one plugin is the owner:

- **Automatic (default):** a feature your SEO plugin already outputs stays with it. SearchPilot
  adds what it lacks — official search-engine submissions, URL inspection and indexing
  monitoring, Search Console/Bing analytics, content opportunities, internal-link
  suggestions, audits and alerts — and reads/writes SEO fields in *that* plugin (e.g. the
  “Update title” action writes Rank Math's title field).
- **SearchPilot:** hands a feature to SearchPilot. Only allowed where the other plugin's
  output can be switched off safely (Yoast and Rank Math for titles, descriptions, canonical,
  robots, social tags and schema); otherwise switch the feature off in that plugin first.

Change this under **Settings → SEO plugin compatibility**. To migrate completely, use
**Settings → Import & migration** (Yoast, Rank Math, AIOSEO, SEOPress, TSF — posts, terms,
settings; redirects from Redirection, Rank Math, Yoast Premium, AIOSEO and SEOPress are
imported on the Redirects screen), then deactivate the old plugin. Imports are undoable.

## 5. Caching, CDN and hosting notes

- **Page caches** (LiteSpeed Cache, WP Rocket, W3 Total Cache, WP Super Cache, SiteGround,
  Nginx Helper, Vayu Speed): SearchPilot purges its sitemaps and changed pages automatically.
- **Cloudflare / bot protection:** SearchPilot checks your own pages over HTTP. If Cloudflare
  challenges those requests, add your server's IP under **Settings → Indexing → Origin server
  IP**, or allow the `SearchPilotPro/…` user agent in your firewall. Do not cache
  `/sitemap*.xml` for long at the edge (a few minutes is fine).
- **WP-Cron:** on low-traffic sites or with `DISABLE_WP_CRON`, run a real cron job every 5
  minutes: `*/5 * * * * wget -q -O - https://your-site/wp-cron.php?doing_wp_cron >/dev/null 2>&1`
  (or `wp cron event run --due-now`).
- **robots.txt:** SearchPilot edits the *virtual* robots.txt. If your host has a physical
  `robots.txt` file, WordPress cannot change it — SearchPilot shows a warning and you
  edit that file yourself.

## 6. Security

- API credentials are encrypted (libsodium or AES-256-GCM) before they are stored, are never
  shown again in the admin, in REST responses, page HTML/JavaScript or logs, and are always
  deleted on uninstall. For extra protection define a dedicated key in `wp-config.php`:
  `define( 'SEARCHPILOT_ENCRYPTION_KEY', 'a-long-random-string' );` (set it *before* connecting).
- Credentials can also be defined as constants (never stored in the database):
  `SEARCHPILOT_BING_API_KEY`, `SEARCHPILOT_GOOGLE_CLIENT_ID`, `SEARCHPILOT_GOOGLE_CLIENT_SECRET`,
  `SEARCHPILOT_GOOGLE_SERVICE_ACCOUNT` (JSON string or absolute path to the key file).
- Capabilities: `searchpilot_manage` (administrators), `searchpilot_view` and
  `searchpilot_edit` (administrators and editors). Editors can only change posts they may edit.

## 7. WP-CLI

```
wp searchpilot status
wp searchpilot queue process|release|retry-failed|stats
wp searchpilot sitemap regenerate|submit|health
wp searchpilot audit run
wp searchpilot registry rebuild|crawl
wp searchpilot analytics
wp searchpilot import rank_math [--overwrite]
wp searchpilot setting get general.mode
wp searchpilot setting set general.mode autopilot
wp searchpilot run hourly
wp searchpilot undo 123 [--force]
```

## 8. Troubleshooting

| Symptom | What to check |
|---|---|
| “Search-engine submissions held” badge | Hover it for the reason: the site has a local address, setup isn't finished, Settings → Reading discourages search engines, the site's address changed since setup (a staging copy or a migration — finish the setup wizard again on the new address), or it is not `production` while Settings → General → Environment guard is on. |
| Nothing happens in the background | Settings → Advanced → Background jobs shows the last runs. Set up a server cron (above). |
| “Stored credentials could not be decrypted” | The WordPress salts or `SEARCHPILOT_ENCRYPTION_KEY` changed (e.g. after a migration). Reconnect Google/Bing. |
| Self-checks fail (“loopback blocked”) | Firewall, basic auth or CDN bot protection. Set the origin IP or allow the user agent. |
| Search Console shows “No data” | Data appears after the first sync (up to a day) and is typically 2–3 days behind. |
| Sitemap 404 | Settings → Permalinks → Save (flushes rewrite rules), or Settings → Advanced → Repair installation. |

Logs: **SearchPilot → System Logs** (secrets are always redacted).

## 9. Uninstall

Deactivating stops all background jobs and keeps your data. Deleting the plugin always
removes API credentials, schedules and temporary data; tables, settings and SEO fields are
removed only when **Settings → General → Delete all data on uninstall** is on.
