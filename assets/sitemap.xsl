<?xml version="1.0" encoding="UTF-8"?>
<!--
	SearchPilot Pro — XML sitemap stylesheet (served at /sitemap.xsl).
	Only makes sitemaps readable in a browser; search engines ignore it.
	%%NAME%% tokens are replaced with translated, XML-escaped labels when served.
-->
<xsl:stylesheet version="1.0"
	xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
	xmlns:sitemap="http://www.sitemaps.org/schemas/sitemap/0.9"
	xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"
	xmlns:video="http://www.google.com/schemas/sitemap-video/1.1"
	xmlns:news="http://www.google.com/schemas/sitemap-news/0.9"
	exclude-result-prefixes="sitemap image video news">

	<xsl:output method="html" version="1.0" encoding="UTF-8" indent="yes" doctype-system="about:legacy-compat"/>

	<xsl:template match="/">
		<html lang="en">
			<head>
				<meta charset="UTF-8"/>
				<meta name="viewport" content="width=device-width, initial-scale=1"/>
				<meta name="robots" content="noindex, follow"/>
				<title>%%TITLE%%</title>
				<style>
					:root {
						--bg: #f6f4ef;
						--surface: #ffffff;
						--border: #e6e1d6;
						--text: #1c2333;
						--muted: #7a8091;
						--navy: #1b2a4a;
						--gold: #b08d57;
						--row: #fbfaf7;
					}
					* { box-sizing: border-box; }
					body { margin: 0; background: var(--bg); color: var(--text); font: 14px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Sans Kannada", "Noto Sans", sans-serif; }
					header { background: var(--navy); color: #fff; padding: 28px 32px 24px; border-bottom: 3px solid var(--gold); }
					header h1 { margin: 0 0 6px; font-size: 22px; font-weight: 600; letter-spacing: .2px; }
					header p { margin: 0; max-width: 760px; opacity: .85; }
					main { padding: 24px 32px 48px; }
					.card { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; box-shadow: 0 1px 2px rgba(28,35,51,.05), 0 6px 20px rgba(28,35,51,.05); }
					.meta { padding: 14px 18px; color: var(--muted); border-bottom: 1px solid var(--border); display: flex; gap: 18px; flex-wrap: wrap; align-items: center; }
					.meta strong { color: var(--text); }
					.meta a { color: var(--navy); }
					table { width: 100%; border-collapse: collapse; }
					th { text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: .6px; color: var(--muted); padding: 10px 18px; border-bottom: 1px solid var(--border); background: var(--row); }
					td { padding: 9px 18px; border-bottom: 1px solid var(--border); vertical-align: top; word-break: break-all; }
					tr:last-child td { border-bottom: 0; }
					tr:hover td { background: var(--row); }
					td.num, th.num { text-align: right; white-space: nowrap; word-break: normal; }
					td.date { white-space: nowrap; color: var(--muted); word-break: normal; }
					a { color: var(--navy); text-decoration: none; }
					a:hover { color: var(--gold); text-decoration: underline; }
					.news { color: var(--muted); font-size: 13px; word-break: normal; }
				</style>
			</head>
			<body>
				<header>
					<h1>%%TITLE%%</h1>
					<xsl:choose>
						<xsl:when test="sitemap:sitemapindex"><p>%%INDEX_INTRO%%</p></xsl:when>
						<xsl:otherwise><p>%%URLSET_INTRO%%</p></xsl:otherwise>
					</xsl:choose>
				</header>
				<main>
					<div class="card">
						<xsl:apply-templates/>
					</div>
				</main>
			</body>
		</html>
	</xsl:template>

	<xsl:template match="sitemap:sitemapindex">
		<div class="meta">
			<span>%%COUNT_SITEMAPS%% <strong><xsl:value-of select="count(sitemap:sitemap)"/></strong></span>
		</div>
		<table>
			<thead>
				<tr>
					<th>%%SITEMAP%%</th>
					<th class="num">%%LASTMOD%%</th>
				</tr>
			</thead>
			<tbody>
				<xsl:for-each select="sitemap:sitemap">
					<tr>
						<td><a href="{sitemap:loc}"><xsl:value-of select="sitemap:loc"/></a></td>
						<td class="date num"><xsl:value-of select="concat(substring(sitemap:lastmod, 1, 10), ' ', substring(sitemap:lastmod, 12, 5))"/></td>
					</tr>
				</xsl:for-each>
			</tbody>
		</table>
	</xsl:template>

	<xsl:template match="sitemap:urlset">
		<div class="meta">
			<span>%%COUNT_URLS%% <strong><xsl:value-of select="count(sitemap:url)"/></strong></span>
			<a href="%%INDEX_URL%%">%%BACK%%</a>
		</div>
		<table>
			<thead>
				<tr>
					<th>%%URL%%</th>
					<xsl:if test="sitemap:url/news:news"><th>%%NEWS%%</th></xsl:if>
					<xsl:if test="sitemap:url/image:image"><th class="num">%%IMAGES%%</th></xsl:if>
					<xsl:if test="sitemap:url/video:video"><th class="num">%%VIDEOS%%</th></xsl:if>
					<th class="num">%%LASTMOD%%</th>
				</tr>
			</thead>
			<tbody>
				<xsl:variable name="has_news" select="count(sitemap:url/news:news) &gt; 0"/>
				<xsl:variable name="has_images" select="count(sitemap:url/image:image) &gt; 0"/>
				<xsl:variable name="has_videos" select="count(sitemap:url/video:video) &gt; 0"/>
				<xsl:for-each select="sitemap:url">
					<tr>
						<td><a href="{sitemap:loc}"><xsl:value-of select="sitemap:loc"/></a></td>
						<xsl:if test="$has_news"><td class="news"><xsl:value-of select="news:news/news:title"/></td></xsl:if>
						<xsl:if test="$has_images"><td class="num"><xsl:value-of select="count(image:image)"/></td></xsl:if>
						<xsl:if test="$has_videos"><td class="num"><xsl:value-of select="count(video:video)"/></td></xsl:if>
						<td class="date num"><xsl:value-of select="concat(substring(sitemap:lastmod, 1, 10), ' ', substring(sitemap:lastmod, 12, 5))"/></td>
					</tr>
				</xsl:for-each>
			</tbody>
		</table>
	</xsl:template>

</xsl:stylesheet>
