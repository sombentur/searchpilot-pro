# SearchPilot Pro

Autonomous technical SEO, indexing and search-performance suite for WordPress.

SearchPilot Pro notifies search engines through their **official** APIs (Google Search
Console, the Google Indexing API for eligible pages only, Bing Webmaster Tools, IndexNow),
keeps XML sitemaps and structured data healthy, monitors how every URL is indexed, finds
content opportunities in Search Console data, builds safe internal links, and alerts you
when something breaks — inside Google and Bing webmaster guidelines. It never promises
rankings and never rewrites editorial content without approval.

- **Install:** upload `searchpilot-pro-<version>.zip` in *Plugins → Add Plugin → Upload Plugin*,
  activate, follow the setup wizard. Full guide: [docs/INSTALLATION.md](docs/INSTALLATION.md).
- **Google / Bing / IndexNow setup:** [docs/GOOGLE-SETUP.md](docs/GOOGLE-SETUP.md).
- **Architecture, APIs, hooks, UI framework:** [docs/DEVELOPER.md](docs/DEVELOPER.md).
- Requirements: WordPress 6.2+, PHP 7.4+ (tested up to WordPress 7.1 and PHP 8.5).

## Screens

Dashboard · Search Performance · Indexing · SEO Audit · Content Opportunities · Internal
Links · Sitemaps · Schema · Redirects · 404 Monitor · Search Engines · Automation · Reports
· Settings · System Logs · Setup Wizard, plus a block-editor sidebar, a Classic Editor meta
box, term and profile fields, and breadcrumb/FAQ blocks.

## Development

```bash
composer install          # PHPUnit, WPCS, PHPCompatibility
composer test             # unit tests (tests/unit)
php tests/run.php         # same tests without Composer
composer lint             # WordPress Coding Standards
python bin/make-pot.py    # regenerate languages/searchpilot-pro.pot (or: wp i18n make-pot)
python bin/build-zip.py   # build ../dist/searchpilot-pro-<version>.zip
```

The plugin has no runtime dependencies and no JavaScript build step.

## License

GPL-2.0-or-later.
