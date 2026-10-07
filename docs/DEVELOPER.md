# SearchPilot Pro — Developer Guide

This guide describes the architecture, the extension points and the rules every
module follows. It is the reference for anyone extending or maintaining the plugin.

- PHP 7.4 – 8.4, WordPress 6.2+, single site and multisite.
- No Composer or npm step at runtime: the plugin ships a PSR-4 autoloader and
  build-free JavaScript that uses the scripts WordPress already bundles
  (`wp-api-fetch`, `wp-i18n`, `wp-element`, `wp-components`, …).
- WordPress Coding Standards (tabs, spaces inside parentheses, Yoda conditions,
  `array()` syntax, snake_case methods/variables, full docblocks). `phpcs.xml.dist`
  configures WPCS 3 (the PSR-4 file-name sniffs are disabled).

## 1. Layout

```
searchpilot-pro.php        Bootstrap: constants, autoloader, activation hooks
uninstall.php              Uninstall entry point → Core\Uninstaller
src/
  Plugin.php               Boots modules (Plugin::modules()) through the container
  Container.php            Auto-wiring service container (constructor type hints)
  functions.php            searchpilot(), searchpilot_breadcrumbs()
  Contracts/Module.php     interface Module { public function register(); }
  Core/                    Settings, State, Logger, Credentials, Crypto, Http/, Scheduler,
                           Lock, TimeBudget, AutomationPolicy, ActivityLog, Changes,
                           ContentTypes, Environment, Capabilities, SetupAudit,
                           Compat/ConflictDetector, Compat/CachePurger, Activator, …
  Database/                Tables, Schema (dbDelta), Migrator
  Support/                 Url, Text, HtmlDocument (pure helpers)
  Issues/                  IssueRegistry, IssueRepository, Issues (fix/approve/ignore)
  Indexer/                 UrlRepository (+ registry sync, crawler, inspections)
  Seo/                     MetaStore, front-end output, breadcrumbs, analysis
  Api/                     Google (OAuth, service account, Search Console, Indexing API), Bing, IndexNow
  Queue/                   Submission queue, change detection, worker
  Sitemap/                 XML sitemaps, news/image/video, robots.txt
  Schema/                  JSON-LD graph, validator, conflict detection
  Links/                   Content graph, suggestions, insertion, orphans
  Redirects/               Redirect manager, 404 monitor
  Audit/                   Scheduled monitoring and alerts
  Analytics/               Search Console / Bing performance sync, opportunities
  Reports/                 Scheduled e-mail reports, exports
  Editor/                  Gutenberg sidebar, classic meta box, term fields
  Import/                  Migration from Yoast, Rank Math, AIOSEO, SEOPress, TSF, Redirection
  Rest/                    RestServer + one controller per area (namespace searchpilot/v1)
  Admin/                   Admin (menu/assets), Notices, AdminBar
  Cli/                     WP-CLI commands
assets/css/admin.css       Design system (tokens + components)
assets/js/core.js          UI framework (window.SPP)
assets/js/views/*.js       One file per admin screen
assets/js/editor/*.js      Gutenberg sidebar / classic meta box scripts
tests/                     PHPUnit tests for the pure services
docs/                      Installation, developer and Google setup guides
```

## 2. Boot sequence and modules

`plugins_loaded` (priority 5) → `Plugin::boot()` → requirement check →
`Migrator::maybe_upgrade()` → every class in `Plugin::modules()` is resolved from
the container and its `register()` called. Missing classes are skipped, and an
exception inside one module is logged without breaking the site.

Rules for modules:

1. **Constructors are cheap.** No queries, no remote calls, no `__()` (translations
   are not loaded yet). Store dependencies only.
2. **`register()` only adds hooks.** Decide ownership (`ConflictDetector::owns()`)
   *inside* callbacks (on `wp`, `template_redirect`, `wp_head`, admin screens) —
   other plugins' settings are not final at `plugins_loaded`.
3. **The front end stays fast.** Front-end hooks may read post meta/options that
   WordPress already caches. Anything heavier runs in background jobs.
4. **Never duplicate output.** Only print a canonical, meta description, robots,
   Open Graph/Twitter tags, JSON-LD, sitemap or breadcrumbs when
   `ConflictDetector::owns( $feature )` is true.

Dependency injection: type-hint services in the constructor; the container builds
them (singletons). Scalar parameters need defaults. Access elsewhere with
`searchpilot( Some\Class::class )` or `\SearchPilot\Plugin::instance()->get()`.

## 3. Shared services (use these — do not re-implement)

| Service | Purpose |
|---|---|
| `Core\Settings` | `get( 'section.key' )`, `update( array )`. Schema in `Settings::schema()`; unknown keys are dropped, values sanitized by type (`bool, int, float, string, text, template` (single-line text that keeps `%var%` placeholders), `template_text`, `raw_text` (URL paths/robots rules: keeps percent-encoded octets such as `%E0%B2%95`), `url, url_list, email_list` (invalid addresses → error), `enum, int_list, string_list, map`). Never use `sanitize_text_field()` for templates or paths: it deletes `%XX`, so `%category%` and Kannada paths are destroyed. Autoloaded option `searchpilot_settings`. |
| `Core\State` | Runtime state (`get/set/set_many/increment`, `refresh()` to drop the in-memory copy in long-running jobs), non-autoloaded option `searchpilot_state`. |
| `Core\Credentials` | Encrypted secrets: `get( 'google', 'refresh_token' )`, `set( 'bing', array( 'api_key' => … ) )`, `masked()`. wp-config constants override. **Never** return secrets from REST, print them in HTML/JS or log them. |
| `Core\Logger` | `info/warning/error/debug( $channel, $message, $context )`. Redacts keys like token/secret/key/password and token-shaped strings. Channels: `google`, `bing`, `indexnow`, `queue`, `sitemap`, `indexer`, `crawler`, `audit`, `analytics`, `links`, `schema`, `redirects`, `import`, `rest`, `automation`, `reports`. |
| `Core\Http\HttpClient` | `request( $method, $url, $args )` → `HttpResponse` (`ok()`, `json()`, `code`, `is_retryable()`, `is_rate_limited()`, `retry_after()`, `error_message()`). Args: `json`, `body`, `query`, `headers`, `timeout`, `channel`, `resolve_to` (origin IP for self-audits), `sslverify`. Injectable transport for tests. |
| `Core\Http\RateLimiter` | `attempt( $key, $cost )` before each external call; `block( $key, $until )` / `block_until_reset( $key )` after 429/quota errors; `remaining_today()`. Keys: `google_inspection`, `google_indexing`, `google_analytics`, `google_sitemaps`, `google_oauth`, `bing_api`, `bing_url`, `indexnow`, `self_audit`. |
| `Core\Scheduler` | Recurring hooks `searchpilot_every_5min`, `searchpilot_hourly`, `searchpilot_daily`, `searchpilot_weekly` (Action Scheduler when available, else WP-Cron). `enqueue_async( $hook, $args, $delay )` for one-off background work (deduplicated against *pending* copies, so a job may re-queue itself). Heavy periodic work should run as its own async event scheduled from the recurring hook, so it gets a fresh request. `next_run( $hook )` works on both backends. Also `searchpilot_initial_sync` (fired once after the wizard). Never schedule one event per post from save hooks: queue IDs and drain them with one event. |
| `Core\Lock` | `Lock::run( 'name', $ttl, fn )` — every background job takes a lock. Atomic (INSERT IGNORE / conditional UPDATE, database reads, owner token), so it is safe inside long-running cron processes. `acquire()`/`held()`/`release()` for marker locks such as cancel flags. |
| `Core\TimeBudget` | `new TimeBudget( 20 )`, `exhausted()` — stop batches before PHP times out. Request-relative: the deadline is never later than 60% of `max_execution_time` after the request started (≈50 s when PHP is unlimited outside WP-CLI), so jobs sharing a cron request cannot add up past the limit. |
| `Core\AutomationPolicy` | `allows( $task )`, `needs_approval( $task )`, `disabled( $task )`, `source()`. Tasks: `submit_urls`, `submit_sitemaps`, `safe_fixes`, `slug_redirects`, `fill_meta`, `fill_alt`, `internal_links`, `seo_structure` (heading levels, extra H1s, SEO-title trims — automatic in Autopilot only), `page_repairs` (description trims, featured image, image compression — automatic in Autopilot only), `technical_changes`, `content` (always approval). |
| `Core\Changes` | **All plugin-made site changes go through here** so they appear in the activity log and can be undone: `update_post_meta`, `update_term_meta`, `update_post_content` (with `marker` for surgical undo, keeps `post_modified` by default), `update_option`, `record( $handler, $args )` for custom revert handlers. `Changes::is_internal_write()` is true while SearchPilot itself saves a post. |
| `Core\ActivityLog` | Query/undo. Register custom revert handlers on `searchpilot_register_revert_handlers`: `$log->register_handler( 'redirect_created', callable( $before, $after, $row, $force ) )`. |
| `Core\ContentTypes` | `post_types()`, `taxonomies()`, `post_indexability( $post )` (`{indexable, reason}`), `is_post_indexable()`, `post_in_sitemap()`, `term_indexability()`, `is_author_indexable()`, `schema_type( $post_type )`. The sitemap, the queue and the audits all use it, so they never disagree. |
| `Core\Environment` | `submissions_allowed()`; `submission_block_reason()` → `local` (local/private host), `setup` (wizard not finished), `discouraged` (Settings → Reading discourages search engines), `moved` (address differs from the one recorded when the wizard finished — cloned staging copies stay silent), `environment` (not `production` while the guard is on), or `''`; `submission_block_message()`; `record_site_address()`; filter `searchpilot_submission_block_reason`. Also `is_local_host()`, `search_engines_discouraged()`, `summary()`. |
| `Core\Compat\ConflictDetector` | `detect()`, `owner( $feature )`, `owns( $feature )`, `must_suppress( $plugin, $feature )`, `primary_seo_plugin()`, `report()`. Features: `title, description, canonical, robots, social, schema, sitemap, news_sitemap, breadcrumbs, redirects, not_found, indexnow, product_schema`. |
| `Core\Compat\CachePurger` | `purge_urls( array )`, `purge_post( $id )` across LiteSpeed, WP Rocket, W3TC, WP Super Cache, SiteGround, Nginx Helper, Vayu Speed. Purges requested after headers were sent (e.g. on `shutdown`) are queued and delivered by a background request, because header-based caches (LiteSpeed) could not receive them. |
| `Seo\MetaStore` | Per-post/term SEO fields through the owning plugin: `get_post_field( $id, $field )`, `set_post_field( $id, $field, $value, $opts )` (logged/undoable), `effective_title()`, `effective_description()` (what the page serves: the companion plugin's own resolution — Rank Math via `Helper::replace_vars()` with its separator and `%excerpt%` rules, Yoast/AIOSEO via their APIs), `effective_title_details()` / `effective_description_details()` (`{title|description, source, origin}`), `rendered_head( $post_id )` (last crawl's served title/description with a `fresh` flag), `head_inputs_hash()`, `field_owner( $field )` (`{owner, owner_name, writable, reason}` — fixers refuse fields they cannot write), `explicit_noindex()`, `canonical()`, `focus()`, `primary_term()`, `resolve_vars()`. Fields: `title, description, focus, canonical, noindex, nofollow, og_title, og_description, og_image, twitter_title, twitter_description, schema_type, breadcrumb_title`; SearchPilot-only: `indexing_api, faq, sitemap_exclude, links_exclude, cornerstone, primary_term`. SearchPilot's own meta keys are `_searchpilot_{field}`. |
| `Indexer\UrlRepository` | URL registry rows (`upsert( $url, $fields )`, `get_by_url`, `get_by_object`, `query`, `summary`, `count_by_status`, `due_for_check`, `due_for_inspection`, `mark_removed_for_object`). |
| `Issues\IssueRegistry` / `IssueRepository` / `Issues` | Issue types, storage and the fix/approval flow (see §6). |
| `Fixes\FixEngine` | `plan( $issue_id )`, `apply( $plan, $source, $group_id )` — see §6. `ContentEditor` (static): `headings()`, `set_heading_levels( $content, $map )` (block attribute and tag together, classic too), `replace_marked_block( $content, $marker, $html )` (a `core/group` with class `searchpilot-{marker}` in block content, `<div class="searchpilot-{marker}">` in classic; re-runs replace, `''` removes), `fragments()`. `Plan::hash()` / `Plan::create()` / `Plan::change()`. |
| `Audit\Rescanner` | `rescan_post( $post_id, $opts )` → `{checked, types, resolved, opened, error, stages…}`: re-fetches the live page past page caches (`?nocache=` + no-cache headers, stripped from everything stored), re-runs content analysis and link checks and rewrites issue data. `rescan_objects()`, `queue_rescan()` (background, deduplicated, resumes), `queue_all()`, `cancel()`. `checked` is false when any stage could not run (e.g. crawler paused) — callers must not treat that as verified. |
| `Indexer\Crawler` | `check_row( $row, $budget, $opts )` (`bust_cache` option), `rendered_head( $post_id )` (post meta `_searchpilot_rendered_head`). |
| `Support\Url` | `normalize()`, `hash()`, `absolute()`, `is_internal()` (same host, www-insensitive, and the site's port — other ports on the same host are never "internal"), `to_path()`, `display()` — percent-encoding normalized (Kannada/Unicode slugs match between WordPress and Search Console). |
| `Support\Text` | Unicode-aware `clean( $value, $multiline )` (template-safe sanitizer), `strip()`, `words()`, `word_count()`, `sentences()`, `contains()`, `count_phrase()`, `phrase_regex()`, `keywords()`, `stem()`, `is_stopword()`, `is_latin()`, `replace_vars()`. **Lengths:** `graphemes()`, `length()` (grapheme count — the only unit for user-visible lengths), `truncate( $s, $max, $ellipsis )` (graphemes incl. ellipsis, word boundary, never splits a cluster), `serp_width()` / `serp_fit( $s, 'title'|'description' )` / `serp_truncate()` (estimated pixel width against Google's limits, per-script width tables; `SERP_LIMITS`). Never use `strlen`/`mb_strlen`/`mb_substr` for lengths people see. Word characters include combining marks (`\p{M}`), required for Indic scripts. |
| `Support\HtmlDocument` | `parse( $html, $base )` → `titles()`, `metas()`, `meta()`, `link_rel()`, `robots()`, `headings()`, `links()`, `images()`, `json_ld()`, `schema_types()`, `microdata_types()`, `main_node()`, `text()`, `hreflang()`. |

## 4. Database

Tables (per site, `{$wpdb->prefix}searchpilot_*`, created by `Database\Schema` with
`dbDelta`, names from `Database\Tables`): `urls`, `queue`, `issues`, `logs`,
`activity`, `redirects`, `not_found`, `links`, `link_suggestions`, `terms`,
`term_stats`, `content`, `perf_daily`, `perf_rows`, `reports`. All DATETIME
columns are **UTC** (`current_time( 'mysql', true )`); REST responses convert
them with `mysql_to_rfc3339()`.

Rules: always `$wpdb->prepare()` user-influenced values; table names come from
`Tables::…()` at query time (never cached — `switch_to_blog()` must work);
index every column used in a WHERE of a recurring query.

Migrations: bump `SEARCHPILOT_DB_VERSION`, change `Schema::statements()`
(dbDelta applies column/index changes) and add data migrations to
`Migrator::migrations()` keyed by version. They run once per site on the next load.

## 5. REST API

Namespace `searchpilot/v1`. Controllers extend `Rest\Controller` and are listed in
`RestServer::controllers()`. Register routes with
`$this->route( $path, $methods, $callback, $level, $args )`, where `$level` is
`manage` (settings, connections, automation), `view` (dashboards, reports) or
`edit` (apply SEO changes — also check `Capabilities::can_edit( $post_id )` for
object-level rights). Exceptions become a generic 500; details go to the log.
List endpoints accept `page` / `per_page` and return
`{ items, total, page, per_page, pages }` (`$this->paged()`).

Responses never contain secrets: use `Credentials::masked()`.

## 6. Issues: What happened → Why it matters → Recommended fix → Fix automatically

Register types lazily:

```php
add_action( 'searchpilot_register_issue_types', function ( IssueRegistry $r ) {
	$r->register( 'post_noindexed', array(
		'label'    => __( 'Published page is set to noindex', 'searchpilot-pro' ),
		'category' => 'indexing',            // indexing|technical|sitemap|content|links|schema|performance|opportunity|security
		'severity' => 'high',                // critical|high|medium|opportunity
		'what'     => function ( $issue ) { return …; },
		'why'      => __( '…', 'searchpilot-pro' ),
		'fix'      => __( '…', 'searchpilot-pro' ),
		'fixer'    => array( $this, 'fix_noindex' ), // optional: callable( array $issue ) : true|WP_Error
		'task'     => AutomationPolicy::TASK_TECHNICAL_CHANGES,
		'destructive' => true,               // always ask first
		'action'   => 'fix_indexing',
	) );
} );
```

### Fixes (1.1)

A type is fixable when it declares a **planner** (preferred) or a legacy `fixer`. Extra keys:

| Key | Meaning |
|---|---|
| `planner` | `callable( array $issue ): array\|WP_Error` — returns a Plan (below); never writes. |
| `mode` | `deterministic` (may run unattended when the task's policy allows), `assisted` (preview + one click), `advisory` (no fix button; show `manual_step`). May be a callable `( array $issue ): string` — resolved per issue in lists, `auto_fix()` and bulk planning, so keep it cheap (read cached issue data). |
| `manual_step` | string or `callable( array $issue )`: the concrete thing a person does when no fix exists. |
| `scope` | `incoming` for types that depend on other posts (e.g. `orphan_page`): never counted as "introduced" by a fix that does not edit other posts. |
| `task` | AutomationPolicy task that governs unattended runs. |
| `auto_ok` | bool or `callable( array $issue ): bool` — an *assisted* fix that may also run unattended when the mode allows its task (Autopilot applies it itself). Never set it for fixes that write fixed editorial text or switch off another plugin's feature. |

Add planners to types you do not own with `IssueRegistry::extend( $type, $def )` on
`searchpilot_register_issue_types` at priority 20.

Plan (JSON-serialisable; built with `Plan::create()` / `Plan::change()`):

```php
array(
	'issue_id' => 12, 'type' => 'heading_structure', 'object_type' => 'post', 'object_id' => 34,
	'summary'  => 'Clamp 2 headings',
	'changes'  => array( array(
		'kind'      => 'seo_field|post_content|post_meta|attachment_alt|featured_image|option',
		'object_id' => 34, 'field' => 'title', 'label' => 'SEO title',
		'before'    => '…', 'after' => '…',   // display strings (content: changed fragments)
		'value'     => '…',                   // exact value written
		'expect'    => Plan::hash( $current ), // apply refuses when the value changed since planning
	) ),
	'verify'   => array( 'types' => array( 'heading_structure' ), 'objects' => array( /* extra post ids to re-scan */ ) ),
	'warnings' => array(),
)
```

`FixEngine::apply()` checks ownership (`MetaStore::field_owner()`), page builders and `expect`,
re-scans the touched posts before writing (baseline), writes every change through
`Changes`/`MetaStore` with one `group_id`, re-scans afterwards and returns
`{applied, verified: true|false|null, introduced, error, code, group_id}`. A new issue type on a
touched post → the group is undone automatically. `verified = null` (re-scan could not run) →
the change is kept, the activity row says "verification pending" and an hourly sweep re-checks
it (then "verified", "not confirmed" or "could not verify"). The issue is marked fixed only when
verified. Plans sent by a browser are never trusted: the server re-plans and compares
fingerprints. Bulk runs (`BulkRunner`) execute on the scheduler, resume after failures, skip
items edited after review and use one undo group per run.

REST: `GET /issues/{id}/plan`, `POST /issues/{id}/apply`, `GET /issues/fixable-types`,
`POST /issues/plan-bulk`, `POST /issues/apply-bulk`, `GET /issues/runs[/{id}]`,
`POST /issues/runs/{id}/cancel|undo`, `POST /issues/fix-groups/{group}/undo`.
Re-scan: `POST /analysis/run`, `POST /analysis/post/{id}/rescan`, `GET|POST|DELETE /indexing/rescan`.

Report with `IssueRepository::upsert( $type, array( 'object_type' => 'post', 'object_id' => 12, 'url' => …, 'title' => …, 'data' => array( … ) ) )`
and clear with `resolve()` / `resolve_stale_for_object()` / `resolve_unseen()`. `update_data( $id, $patch )`
merges keys into an issue's data without counting a new occurrence. `searchpilot_issue_detected`
also fires when a fixed or resolved issue comes back (`$args['reopened'] = true`).
The hourly automation pass (`Issues::auto_fix()`) applies **deterministic** planners whose
task the current mode allows and flags assisted ones `pending_approval`; a failed automatic
attempt is not retried for a week. Every change goes through `Changes`/`MetaStore` so it can be
undone. Issue `data` is rewritten on every re-evaluation — never treat it as a snapshot.

Opportunities (from Search Console data) are issues with `category = opportunity`
and `severity = opportunity` (or higher when warranted).

## 7. Hooks

Actions: `searchpilot_loaded`, `searchpilot_settings_updated( $new, $old )`,
`searchpilot_register_issue_types`, `searchpilot_register_revert_handlers`,
`searchpilot_issue_detected`, `searchpilot_issue_fixed`, `searchpilot_activity_recorded`,
`searchpilot_activity_undone`, `searchpilot_url_changed( $change )`,
`searchpilot_sitemap_changed( $types )`, `searchpilot_submitted( $service, $urls, $result )`,
`searchpilot_setup_completed`, `searchpilot_initial_sync`, `searchpilot_maintenance`,
`searchpilot_purge_urls`, `searchpilot_every_5min|hourly|daily|weekly`,
`searchpilot_fix_applied( $plan, $activity_ids, $group_id )`, `searchpilot_fix_rescanned( $post_id, $scan )`,
`searchpilot_fix_run_finished( $run )`, `searchpilot_links_graph_changed( $source_id, $targets )`,
`searchpilot_audit_refresh`; scheduled one-offs `searchpilot_rescan_drain`, `searchpilot_fix_run`,
`searchpilot_fix_verify`.

Filters: `searchpilot_modules`, `searchpilot_rest_controllers`,
`searchpilot_admin_pages`, `searchpilot_post_types`, `searchpilot_taxonomies`,
`searchpilot_post_indexability`, `searchpilot_feature_owner`,
`searchpilot_detected_plugins`, `searchpilot_automation_policy`,
`searchpilot_rate_limits`, `searchpilot_stopwords`, `searchpilot_dashboard_summary`,
`searchpilot_schema_graph`, `searchpilot_sitemap_entry`, `searchpilot_http_sslverify`,
`searchpilot_use_action_scheduler`, `searchpilot_log_row_cap`, `searchpilot_fix_prescan`,
`searchpilot_fix_rescan_opts`, `searchpilot_related_links_max`, `searchpilot_related_links_heading`.

`searchpilot_url_changed` payload (fired by the change detector for every
meaningful URL event):

```php
array(
	'event'       => 'publish|update|delete|unpublish|redirect|noindex|index|term_create|term_update|term_delete',
	'url'         => 'https://…',        // current URL
	'old_url'     => 'https://…',        // previous URL for redirect/slug changes, else ''
	'object_type' => 'post|term|author',
	'object_id'   => 123,
	'subtype'     => 'post',
	'significant' => true,               // content changed enough to re-notify
)
```

## 8. Admin UI (build-free JavaScript)

Every screen is `<div id="spp-app" data-view="…">`, rendered by
`assets/js/core.js` + `assets/js/views/{view}.js`. A view registers itself:

```js
( function ( wp, SPP ) {
	const { __, sprintf } = wp.i18n;
	const { h, ui, fmt, api } = SPP;
	SPP.registerView( 'sitemaps', {
		mount( el, ctx ) {
			SPP.mount( el, ui.pageHeader( { title: __( 'Sitemaps', 'searchpilot-pro' ) } ), … );
		},
	} );
} )( window.wp, window.SPP );
```

- `h( tag, props, ...children )` creates DOM; text is always escaped. Never set
  `innerHTML` from data.
- `SPP.api.get/post/put/del( path, data )` calls `searchpilot/v1` with the REST nonce.
- Components in `SPP.ui`: `pageHeader, card, grid(cols, …), stack, row, kpi, trend,
  badge, severity, dot, button (Promise-aware), buttonGroup, rangePicker, tabs,
  toolbar, search, select, link, urlCell, table, pagination, dataTable (server
  paging), loading, skeleton, empty, notice, errorBox, progress, dl, code,
  copyField, modal (focus-trapped; Escape closes only the top-most), confirm,
  toast( msg, tone, { action: { label, onClick } } ), field.{text,textarea,number,select,toggle,
  checklist,radioCards (onChange may return a Promise; reject/false reverts),media},
  issue (What/Why/Fix/Fix automatically card),
  passed, checkRow, scoreRing, sparkline, lineChart, barList, donut, serpPreview,
  stepper`.
- `SPP.fmt`: `number, compact, percent (fraction → %), position, date, datetime,
  relative, delta(current, previous), bytes, duration, url (decode), path`.
  Server timestamps are UTC without a zone marker: always parse them with
  `SPP.parseDate()` (or the fmt helpers), never `new Date( string )`.
- `ui.kpi( { …, trend: { good: 'down', absolute } } )` passes options to the trend chip;
  `ui.stepper` steps may carry `done`; `SPP.refreshTopbar()` re-renders the mode pill.
- `SPP.query.get/set` keeps filters in the URL; `SPP.util.debounce, download,
  toCsv (formula-injection safe), copy`.
- Strings: `__( 'Text', 'searchpilot-pro' )` literally in each file (for
  `wp i18n make-pot` / `wp_set_script_translations`).
- Severity order everywhere: Critical → High → Medium → Opportunity → Passed.
- Scores are always labelled as internal diagnostics, never as rankings.

`window.SearchPilotBoot` (from `Admin::boot_data()`) holds non-secret context:
`pages` (view → URL), `mode`, `caps`, `companion`, `submissions`, `homeUrl`,
`siteName`, `locale`, `timezone`, `wizardCompleted`.

## 9. Search engine rules (non-negotiable)

- Only official APIs: Search Console API (Search Analytics, Sitemaps, URL
  Inspection), Google Indexing API **only** for pages with `JobPosting` or
  `BroadcastEvent` (livestream `VideoObject`) structured data, Bing Webmaster API,
  IndexNow. The deprecated Google/Bing sitemap “ping” endpoints are not used.
- Never scrape search results, run automated queries, create links off-site,
  cloak, or stuff keywords. Schema only describes content visible on the page.
- Honour quotas (`RateLimiter`), back off on 429/5xx, never retry 4xx blindly.
- Hold every submission on non-production or local sites (`Environment::submissions_allowed()`).

## 10. Testing

`composer install && vendor/bin/phpunit` runs the unit tests in `tests/unit`
(pure services — URL normalization, tokenizer, robots.txt parsing, sitemap XML,
schema validation, JWT signing, crypto, queue back-off, link insertion). The
bootstrap stubs the few WordPress functions those services use, so no database
is needed. Integration testing is done against a real WordPress (e.g.
`wp-env` or WordPress Playground) with the `tests/mocks/searchpilot-mock-apis.php`
mu-plugin, which answers Google/Bing/IndexNow calls locally.
