=== SearchPilot Pro ===
Contributors: newsroomengine
Tags: seo, indexing, search console, sitemap, schema
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Autonomous technical SEO and indexing: official Google, Bing and IndexNow integrations, XML sitemaps, schema, internal links, audits and Search Console insights.

== Description ==

SearchPilot Pro keeps your whole site discoverable by search engines and shows you where the organic traffic opportunities are — automatically, and strictly inside Google and Bing webmaster guidelines.

It never uses spam techniques, fake backlinks, keyword stuffing, cloaking or automated search queries, and it never promises rankings. It uses only the official mechanisms search engines provide.

= Search engine integration =

* **Google Search Console** (official API, OAuth or service account): performance data, URL Inspection index status, sitemap submission.
* **Google Indexing API** — only for pages it officially supports (job postings and livestream videos). Never used for other content.
* **Bing Webmaster Tools** (API key): traffic, crawl statistics, URL and sitemap submission.
* **IndexNow**: instant notifications to Bing, Yandex, Seznam, Naver and Yep. The key file is served automatically.
* A **submission queue** with duplicate prevention, retries with exponential back-off, quota and rate-limit handling, failure logging and full submission history.
* Automatic decisions on every publish, significant update, deletion, unpublish, redirect or noindex change.

= Smart indexing engine =

A registry of every URL — posts, pages, custom post types, categories, tags, authors, archives, pagination and media — with Index status, Last submitted, Last crawled, Last modified, Canonical, Sitemap status, Indexability, Search engines, Errors and a Recommended action for each.

SearchPilot detects pages that are not indexed, crawled or discovered but not indexed, accidentally noindexed, blocked by robots.txt, canonicalized incorrectly, orphaned, redirected incorrectly, thin or duplicate, missing from the sitemap, or returning 4xx/5xx errors — fixes the safe ones automatically and asks before anything that changes how pages behave.

= Everything else =

* **Dynamic XML sitemaps** with image, video and Google News support, automatic lastmod, correct splitting and exclusion of noindexed/redirected/deleted URLs.
* **On-page analysis** with an internal 0–100 diagnostic score (clearly not a search-engine ranking score) covering titles, descriptions, topic coverage, headings, links, images, canonical, social tags, schema, freshness and duplicates. Works with every language and script, including Indic scripts such as Kannada.
* **Automatic internal linking** from a site-wide content graph: New → Existing and Existing → New suggestions, orphan detection, anchor diversity and strict limits. Optional automatic insertion, always undoable.
* **Schema engine**: Article, NewsArticle, BlogPosting, Organization, Person, WebSite, WebPage, BreadcrumbList, FAQ and VideoObject — only for content that is actually visible. Validation and conflict detection with other plugins and themes.
* **Search performance dashboard**: clicks, impressions, CTR, average position, indexed and non-indexed URLs, crawl errors and sitemap health with 7-day, 28-day, 3-month, 6-month and 12-month trends.
* **Content opportunity engine**: high impressions with low CTR, positions 4–15 and 11–30, rising and declining articles, keyword cannibalization, queries without a strong landing page and refresh candidates — with clear actions (update title, improve meta description, refresh content, add internal links, merge competing articles, create a supporting article, fix indexing, improve structured data). Content is never rewritten without your approval.
* **Monitoring & alerts**: accidental site-wide noindex, sitemap failures, robots.txt blocks, Search Console connection problems, indexing or traffic declines, broken canonicals, mass 404/5xx errors, schema failures and redirect loops — as dashboard notices and optional e-mails.
* **Redirect manager & 404 monitor** with automatic redirects for changed URLs, loop/chain detection and imports from other plugins.
* **Three automation modes** — Manual, Assisted and Autopilot — with a detailed activity log and one-click undo for every change SearchPilot makes.
* **Companion mode**: works alongside Yoast SEO, Rank Math, All in One SEO, SEOPress, The SEO Framework and others without ever duplicating canonical tags, meta descriptions, sitemaps or schema. Migration/import from those plugins included.
* Compatible with Multisite, the block editor and Classic Editor, WooCommerce, custom post types, Cloudflare and common caching plugins.

= Privacy & security =

API credentials are encrypted at rest, never shown again after saving, never included in page HTML, JavaScript, REST responses or logs, and always removed on uninstall. All endpoints use WordPress capabilities and nonces; all input is sanitized and all queries are prepared.

The plugin contacts Google, Bing and IndexNow endpoints only after you connect them in the setup wizard, and only from production sites.

== Installation ==

1. Upload the zip through **Plugins → Add Plugin → Upload Plugin** and activate it.
2. Follow the setup wizard: Website audit → Google Search Console → Bing Webmaster Tools → IndexNow → Sitemaps → Content types → Automation level → Notifications.
3. See `docs/INSTALLATION.md` and `docs/GOOGLE-SETUP.md` inside the plugin for details.

== Frequently Asked Questions ==

= Will SearchPilot improve my rankings? =

SearchPilot makes sure search engines can discover, crawl and understand your pages and shows you evidence-based opportunities. No plugin can guarantee rankings or traffic.

= Can I keep Yoast SEO or Rank Math? =

Yes. SearchPilot detects them and leaves every feature they already output to them. It adds indexing, submissions, monitoring, analytics, opportunities and internal links on top.

= Does it submit every page to the Google Indexing API? =

No. Google supports the Indexing API only for job postings and livestream videos. SearchPilot uses it only for such pages, and only if you enable it.

= Does it slow down my site? =

No. Front-end output uses cached data only; audits, crawls, submissions and analytics run in small background batches.

== Changelog ==

= 1.1.9 =
* Link quality: links that the "no internal links" and "no other article links to this page" fixes wrote inside the text are now reviewed like every other automatic link. Those with weak link text are listed and removed (the words stay); Autopilot does this on its own.

= 1.1.8 =
* Link quality: a single word is linked inside the text only when the linked article is really about it (one of its main terms or its focus keyword). Generic words that merely happen to be rare on a small site are no longer linked; such links already inserted are listed for removal.
* Link quality: articles that only share a category (no shared topic) are listed under "Related articles" and never linked from inside the text.

= 1.1.7 =
* Fix: related-article results cached before an update were kept for up to six hours, so link fixes still reported "no related article" after updating. An update now starts with fresh results.

= 1.1.6 =
* Fix: articles whose tags and sub-category are used by no other article found no related articles, so "no internal links" and "no other article links to this page" could not be fixed. Their shared category is used now.
* Fix: skipped heading levels are no longer reported on utility pages (About, Contact, legal pages).

= 1.1.5 =
* Fix: when the scaled copy of an image is damaged (cut off) on the server, the image fix works from the original upload instead of giving up. This also repairs the picture for visitors.
* Fix: image errors no longer show server file paths; details go to SearchPilot → Logs.

= 1.1.4 =
* Fix: converting a PNG to WebP ignored the lower quality steps, so detailed images were reported as "could not be brought under 300 KB". The steps are applied now, with 1280 px as a further last resort.
* Fix: when the server's image library fails, its real error message is shown.

= 1.1.3 =
* New: oversized PNG images are converted to WebP automatically. The article and the featured image switch to the smaller file, the old PNG files stay on the server so existing links keep working, and undo switches back.
* Fix: utility pages (terms, privacy policy, contact) are no longer asked to add subheadings.
* Fix: undoing a group of changes with an empty group id no longer touches unrelated entries.

= 1.1.2 =
* Fix: "large images" measured the size WordPress stored at upload instead of the real file, so images already compressed by Imagify or another optimiser were reported. The file on disk is measured now.
* Fix: titles slightly wider than the estimated space Google shows (up to 25%) are no longer reported; only titles well beyond it are.
* Fix: new pages that Google has not discovered or crawled yet are reported after 14 days instead of 3.

= 1.1.1 =
* Autopilot now applies the safe one-click fixes itself: ALT text, missing featured image, over-long meta descriptions, site name on short utility-page titles, removal of weak automatic links, and (when automatic related-articles lists are on) links for orphan pages. Each fix is checked on the live page, logged and can be undone; each task can be switched back to "ask first" under Automation.
* New: oversized JPEG/WebP images are re-compressed automatically (same address, the original file is kept, undo restores it).
* New: article pages or ads.txt that were submitted to Search Console as "sitemaps" are removed from Google's sitemap list through the official API.

= 1.1.0 =
* New: fixes, not just findings. Issues SearchPilot can repair safely show a preview of the exact change; every fix is re-checked against the live page, undone automatically if it creates another problem, and can be undone with one click (per fix or per bulk run). Issues it cannot fix show the concrete manual step instead of a fix button.
* New: automatic fixes for skipped heading levels, extra H1s and over-long SEO titles (Autopilot only, the headline is never changed; awkward word cuts always wait for approval). One-click fixes for short utility-page titles, over-long descriptions, missing ALT text, missing featured images, duplicate IndexNow senders and posts without internal links.
* New: review list for links SearchPilot added automatically with weak link text; remove them in one reviewed step (the words stay, undo available).
* New: internal-link engine that works on Kannada and other inflected languages (shared title words, categories, tags), with an optional related-articles block when no phrase in the text fits.
* Fix: lengths are measured in visible characters and estimated on-screen width, so Kannada and other Indic titles and descriptions can pass the checks.
* Fix: in companion mode the audit and the editor preview show the title and description the page really serves (Rank Math template, separator and excerpt rules).
* Fix: pages are re-checked from the live page after every change; "Run audit now" refreshes link and content checks; issue details no longer go stale.
* Fix: deleted posts and tags leave the URL index; Search Console sitemap submissions no longer fail with HTTP 411; the scheduled-job endpoint accepts its own job names; the issues CSV export decodes HTML entities.

= 1.0.2 =
* New: in Autopilot with automatic link insertion on, existing posts without internal links (or without links pointing to them) now get links too, a few posts per hour, never more than the per-post limit. Every link is logged and can be undone.

= 1.0.1 =
* Fix: tag and category archives that Rank Math, Yoast or SEOPress set to noindex by default are no longer reported as "Page is set to noindex" or submitted to search engines. Existing false reports close automatically after updating.
* New: detects WP Meta SEO and warns when two SEO plugins print duplicate canonical links and meta descriptions.

= 1.0.0 =
* First release.
