# Changelog

## 1.1.9 — 2026-10-04

- `LinksModule::weak_auto_links()` also checks the in-text links written by link fixes (marker
  `fix-{issue id}`; found with `AutoLinks::fix_links()`, related-list items excluded). Their
  marker is shared by all links of a fix, so they are removed one by one with
  `AutoLinks::unwrap()` (item key `f{post}-{hash}`, also accepted by `POST /links/weak/remove`).

## 1.1.8 — 2026-10-04

Anchor quality on small sites.

- `Suggester::phrases_for()`: single-word anchors must be central to the target — among its
  `CENTRAL_TERMS` (12) strongest index terms or part of its focus keyword — in addition to
  being rare on the site. On a 110-post site every adjective used in three posts was "rare".
  `anchor_fits()` uses the same rule, so existing links of that kind show up in `weak_auto_links`.
- `Suggester::room_for_tier()`: partners from the `recent` tier (shared category only) get a
  related-list item, never an in-text anchor (suggestions, fix plans and Autopilot).

## 1.1.7 — 2026-10-04

- `ContentIndex::related_cache_key()` includes the plugin version, so cached related posts and
  ranked link partners (`spp_rel_*`, `spp_tf_*`) are not reused across an update.

## 1.1.6 — 2026-10-04

- `TargetFinder::taxonomy_neighbours()` skips terms only the post itself uses. They filled all
  `MAX_TERMS` (6) slots — smallest terms first — on posts with a handful of new tags, so the
  shared category was never queried and the candidate pool stayed empty.
- Heading checks: skipped levels and "no subheadings" are not reported for `utility` post types.

## 1.1.5 — 2026-10-04

- `ImageCompressor::source_of()`: when the attached file cannot be opened by the image editor
  (two truncated `-scaled.png` files on a live site), the fix encodes from
  `wp_get_original_image_path()`. `MAX_WIDTHS` starts at 2560 px for such untouched uploads.
- `ImageCompressor::unreadable()`: library errors are logged; the message names the file only.

## 1.1.4 — 2026-10-04

- `ImageCompressor::encode()` pins the quality with the `wp_editor_set_quality` filter while
  saving: `WP_Image_Editor::get_output_format()` resets it to the default when the output
  format differs from the source, so PNG → WebP always used quality 86.
- `MAX_WIDTHS` gains 1280 px; an encoder error is returned instead of the "under 300 KB" text.

## 1.1.3 — 2026-10-04

The image fix covers PNG files.

- `ImageCompressor::convert()`: PNG originals above 300 KB become WebP (same quality/width steps
  as compression, shared in `encode()`). The attachment points to the new `.webp` file and its
  regenerated sizes; the PNG files stay on disk (old addresses keep working) and are listed in
  the `_searchpilot_image_old_files` attachment meta. Image addresses in the content of the
  post that raised the issue are rewritten (`image_addresses_updated`), except in page-builder
  posts. Activity action `image_converted`; undo points the attachment back at the PNG. The
  kept PNG files are deleted with the attachment.
- "No subheadings" is not reported for post types whose rule is `utility` (pages by default).
- `ActivityLog::undo_group()` returns an empty result for an empty group id (it matched every
  ungrouped entry).

## 1.1.2 — 2026-10-04

Three checks reported healthy pages on the live sites.

- `Analyzer::attachment_info()` measures the file on disk; the `filesize` WordPress stores at
  upload is only a fallback (optimisers such as Imagify shrink files without updating it — 68
  false `large_images` issues on one site).
- `Analyzer::TITLE_LONG` (1.25): a title is reported as long only beyond 125% of the estimated
  width (widths are estimates and Google cuts at a word boundary).
- `IssueDetector::NEW_URL_GRACE` (14 days, was 3) before "unknown to Google" and "discovered,
  not indexed" become issues.

## 1.1.1 — 2026-10-04

Autopilot resolves more on its own.

- **`auto_ok`** (issue definition key, bool or `callable( $issue )`): a one-click (assisted) fix
  marked this way also runs unattended when the automation mode allows its task. Set for ALT
  text, missing featured image, over-long descriptions, site name on short utility-page titles,
  removal of weak automatic links, and link fixes for orphan pages / posts without links when
  `links.auto_related_block` is on. Fixed-text title cuts and the IndexNow takeover never run alone.
- New task **`page_repairs`** (approval / approval / auto): descriptions, featured images and
  image files. `description_length` and `missing_featured_image` moved to it from `content`.
- **Image compression** (`Fixes\Fixers\ImageCompressor`): `large_images` is fixed by re-encoding
  JPEG/WebP originals above 300 KB with the WordPress image editor (quality steps 82/74/66, then
  1920/1600 px as a last resort). Same file name and address; the original is kept as
  `*-spp-original.*` and restored by undo (activity action `image_compressed`). Other formats and
  unwritable files keep the manual step.
- **Stray Search Console "sitemaps"** (`Fixes\Fixers\StraySitemapFixer`): entries that are pages
  of this site, ads.txt or robots.txt are removed with `SearchConsole::delete_sitemap()`
  (sitemaps.delete); real sitemaps with errors are never removed.
- Site-level legacy fixers now count as fixed in the automatic pass (they were logged as failed).

## 1.1.0 — 2026-10-01

Auto-fix engine and the fixes from live testing on a 111-post Kannada/English news site.

- **Fix engine** (`src/Fixes`): planners return a previewable plan (before/after per change);
  apply writes through `Changes`/`MetaStore` with one undo group per batch, refuses stale plans,
  fields owned by another plugin and page-builder content, re-scans every touched post before and
  after writing, rolls the group back automatically when a new issue type appears, and marks an
  issue fixed only when the re-scan confirms it. Unverifiable fixes stay "verification pending"
  and are re-checked by an hourly sweep. Bulk runs execute on the scheduler and resume after
  failures. Issue types declare `mode` (deterministic | assisted | advisory, may be per issue),
  `planner`, `manual_step` and `scope`; the fix button appears only when a planner exists.
- **Fixers**: heading levels and extra H1s (new task `seo_structure`, automatic in Autopilot only);
  over-long SEO titles (site-suffix removal or leading clause automatic, word cuts assisted, never
  the post title, collision and series-prefix guards); utility-page titles; over-long
  descriptions; ALT text from caption → figure caption → title → cleaned file name; featured
  image promotion; Rank Math Instant Indexing takeover; advisory steps for large images, thin
  content and crawled-not-indexed.
- **Measurement**: one unit (graphemes) everywhere; `Text::truncate`, `Text::serp_fit`,
  `Text::serp_truncate` with per-script width estimates; Analyzer length rules are public and
  shared with the fixers; per-post-type rules (`analysis.post_type_rules`).
- **Companion resolution**: `MetaStore::effective_*_details()` resolve Rank Math/Yoast/AIOSEO the
  way those plugins do; `rendered_head()` exposes what the last crawl saw; the editor preview and
  the audit use the served values.
- **Re-scan**: `Audit\Rescanner` re-fetches pages past page caches, re-runs analysis and link checks
  and rewrites issue data; `/analysis/run`, `/indexing/rescan`, `/analysis/post/{id}/rescan`;
  "Run audit now" refreshes link and content checks.
- **Link engine**: ranking on title terms, slug tokens, taxonomy and body similarity with
  Kannada word-form matching; related-articles block (`links.auto_related_block`, default off);
  link issues follow the live link graph; planners for orphan pages and posts without links.
- **Index hygiene**: registry rows and issues of deleted/trashed/unpublished posts and deleted
  terms/users are removed; the 1.1.0 upgrade cleans up leftovers in the background.
- **Safeguards for unattended fixes**: values are re-read from the database right before each
  write (an editor's save is never overwritten) and posts open in the editor are skipped; a fix a
  person undid or reverted is not re-applied automatically; heading edits that would unbalance
  tags or mismatch block attributes are refused; automatic fixes are postponed while self-checks
  cannot run and undone when the re-check still finds the problem; SEO-title fixes that remove the
  site name write the plugin's headline variable so later headline corrections still show; fixed
  text titles always need approval; bulk runs apply only previewed changes; "Undo this run" works
  in the background.
- **Quiet writes**: SearchPilot's own markup edits (links, heading levels, ALT text, its blocks) no
  longer schedule pingbacks/enclosures or store a revision per write (the activity log holds the
  undo).
- **In-text link anchors** consist only of the target's title/focus words (no padding with
  neighbouring words; a site-dominant word such as the city name never stands alone); padded
  suggestions stored by 1.0.x are discarded.
- **Review automatic links**: links SearchPilot inserted automatically whose text fails the new
  rules (or whose target was unpublished) are listed on Internal Links → Review automatic links
  and as one audit issue with a preview; removing keeps the words, only the link goes, and can be
  undone. Links people added are never touched.
- **Fixes**: HTTP 411 on Search Console sitemap submission (Content-Length on body-less PUT);
  `/system/run` accepts `searchpilot_*` job names; CSV export decodes HTML entities.

## 1.0.2 — 2026-09-30

- Autopilot link backlog: with automatic insertion on, existing posts that have no
  internal links, or no links pointing to them, receive suggestions and automatic
  links (10 posts per run, one full pass per week). A post never receives more than
  `links.max_auto_per_post` automatic links in total. Every insertion is logged in
  the activity log and can be undone.

## 1.0.1 — 2026-09-30

- Fix: in companion mode, tag and category archives that the SEO plugin sets to
  noindex by default (Rank Math "Tag archives: noindex", Yoast, SEOPress) are
  treated as noindex by design. They are no longer reported as critical
  "Page is set to noindex" issues, listed as indexable, or submitted to IndexNow.
- The update re-checks the URL registry in the background and closes the false
  reports without waiting for a re-crawl.
- Changing the SEO plugin's title/robots settings now re-checks indexability.
- Detects WP Meta SEO. Duplicate canonical/description reports now name it, and
  the setup audit warns when two SEO plugins print the same tags.
- Heading-structure reports are opportunities (they do not affect rankings);
  open heading and multiple-H1 reports are reclassified on update.

## 1.0.0 — 2026-09-30

First release.

- Official integrations: Google Search Console (OAuth 2.0 with PKCE or service account),
  Google Indexing API (job postings and livestreams only), Bing Webmaster API, IndexNow.
- Submission queue with deduplication, retries with exponential back-off, quota handling,
  failure logging and history; automatic change detection for publish, update, delete,
  unpublish, redirect and noindex events.
- URL registry and smart indexing engine: incremental self-crawl, Search Console URL
  Inspection sync, issue detection (not indexed, noindex, robots.txt, canonicals, redirects,
  4xx/5xx, soft 404, sitemap coverage) with safe automatic fixes.
- Dynamic XML sitemaps (posts, pages, custom post types, taxonomies, authors, images,
  video, Google News) with caching, splitting and change-driven submission.
- On-page analysis and internal diagnostic score, Unicode-aware for every script.
- Content graph and internal link suggestions (both directions), orphan detection,
  optional automatic insertion with limits and undo.
- JSON-LD schema graph with validation and conflict detection.
- Search performance dashboard and content opportunity engine.
- Scheduled monitoring, dashboard notices and e-mail alerts; weekly/monthly reports.
- Redirect manager, 404 monitor, robots.txt management.
- Manual / Assisted / Autopilot modes, activity log with undo.
- Companion mode with Yoast SEO, Rank Math, AIOSEO, SEOPress, TSF and others; importers.
- Setup wizard, WP-CLI commands, multisite support.
