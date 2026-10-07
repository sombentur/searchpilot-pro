# Connecting Google, Bing and IndexNow

SearchPilot Pro talks to search engines only through their official APIs, with
credentials that belong to **you** (your own Google Cloud project, your own Bing
key). Nothing passes through a third-party server. Secrets are stored encrypted
in the WordPress database (or in `wp-config.php`) and are never shown again in
the admin, in REST responses or in logs.

Everything below is done once per site, in **SearchPilot → Search Engines →
Connections** (or in the setup wizard).

> On local, staging or unfinished installs SearchPilot **holds** every
> submission in the queue (Search Engines → Submission queue) so test URLs are
> never sent. You can connect the accounts anyway; sending starts on the live
> site after the setup wizard is finished.

---

## 1. Google Search Console

You need a Google account that has access to the site's Search Console property
(Owner or Full user) and a Google Cloud project. Choose **one** sign-in method:

| | OAuth (recommended) | Service account |
|---|---|---|
| Who signs in | You, with your Google account | A robot account (`…@….iam.gserviceaccount.com`) |
| Setup effort | Client ID + secret, then “Connect with Google” | JSON key + adding the robot as a user in Search Console |
| Survives password changes | Reconnect needed if you revoke access or change the password | Yes |

### 1.1 Create the Google Cloud project

1. Open <https://console.cloud.google.com/projectcreate> and create a project
   (for example “SearchPilot – mysite.com”). One project can serve several sites.
2. Open **APIs & Services → Library** and enable **Google Search Console API**
   (<https://console.cloud.google.com/apis/library/searchconsole.googleapis.com>).
3. Only if you publish **job postings or livestreams**: also enable
   **Web Search Indexing API**
   (<https://console.cloud.google.com/apis/library/indexing.googleapis.com>).
   News sites and blogs do not need it (see §1.5).

### 1.2 OAuth (recommended)

1. **OAuth consent screen** — <https://console.cloud.google.com/apis/credentials/consent>
   - User type: **External** (or Internal on Google Workspace).
   - App name, support e-mail and developer e-mail: anything that identifies you.
   - Scopes: you do not have to add any here; SearchPilot requests them when you connect.
   - Test users: add the Google account you will connect with, **or** press
     *Publish app*. (Unverified apps in “Testing” status issue refresh tokens
     that expire after 7 days — publishing avoids that. Google shows an
     “unverified app” notice to you only; that is expected for a private app.)
2. **Credentials → Create credentials → OAuth client ID** —
   <https://console.cloud.google.com/apis/credentials>
   - Application type: **Web application**.
   - **Authorized redirect URIs**: paste the exact URI shown in SearchPilot
     (Search Engines → Google, step 4, with a copy button). It looks like:

     ```
     https://example.com/wp-admin/admin-post.php?action=searchpilot_google_oauth
     ```

     It must match character for character (protocol, `www`, trailing path).
     No JavaScript origins are needed.
   - Create, then copy the **Client ID** (`…apps.googleusercontent.com`) and the
     **Client secret** (`GOCSPX-…`).
3. In SearchPilot paste both values and press **Save and connect with Google**.
   Sign in with the account that has access to the property and allow access.
4. You return to SearchPilot. It selects the best matching property
   automatically:
   - the **Domain property** `sc-domain:example.com` when you have one, else
   - the **URL-prefix property** matching your home URL (http/https and
     `www` variants are considered).
   If nothing matches, choose a property from the list in the Google panel.

**Permissions requested**

- `https://www.googleapis.com/auth/webmasters` — Search Console. Write access
  is required only to **submit sitemaps**; SearchPilot never changes any other
  Search Console setting.
- `https://www.googleapis.com/auth/indexing` — requested **only** after you
  switch the Indexing API on (§1.5). Existing connections are asked to
  reconnect once to grant it.

Tokens are protected with PKCE and a single-use `state` bound to your user, and
stored encrypted. **Disconnect** revokes the token at Google and deletes it.

Optional hardening — define the client in `wp-config.php` instead of the
database:

```php
define( 'SEARCHPILOT_GOOGLE_CLIENT_ID', '123456789012-abc….apps.googleusercontent.com' );
define( 'SEARCHPILOT_GOOGLE_CLIENT_SECRET', 'GOCSPX-…' );
```

### 1.3 Service account (alternative)

1. In the Cloud project open **IAM & Admin → Service accounts**
   (<https://console.cloud.google.com/iam-admin/serviceaccounts>) → *Create
   service account*. No project roles are needed.
2. Open the account → **Keys → Add key → Create new key → JSON**. A `.json`
   file downloads. Keep it private.
3. In **Search Console → Settings → Users and permissions**
   (<https://search.google.com/search-console/users>) add the service-account
   e-mail (`name@project.iam.gserviceaccount.com`) as **Owner** (required for
   the Indexing API) or **Full user** (enough for performance data, inspection
   and sitemaps).
4. In SearchPilot choose **Service account**, paste the whole JSON file and
   press **Save and test key**. SearchPilot validates the file (type
   `service_account`, `client_email`, `private_key`, Google `token_uri`),
   signs a test token request (RS256 JWT bearer grant) and selects the
   property. Keys Google rejects are not saved.

Or keep the key outside the database:

```php
// JSON string, or an absolute path to the key file outside the web root.
define( 'SEARCHPILOT_GOOGLE_SERVICE_ACCOUNT', '/home/user/keys/searchpilot.json' );
```

### 1.4 Checking the connection

- **Test connection** refreshes the token, lists your properties and confirms
  the selected one is still accessible.
- If Google later rejects the authorization (revoked, password changed,
  key deleted), SearchPilot marks the connection as failed and opens a
  **Google connection failed** issue — *critical* (“Search Console
  connectivity failure”) when a property had been connected — with the fix.
- Quotas (URL Inspection 2,000/day per property, Search Analytics, Sitemaps)
  are tracked in **Search Engines → Quotas**; SearchPilot pauses before the
  limits and resumes after Google's daily reset (midnight Pacific Time).

### 1.5 Google Indexing API — job postings and livestreams only

Google accepts the Indexing API **only** for pages with `JobPosting` or
`BroadcastEvent` (livestream `VideoObject`) structured data, and may revoke
access when it is used for anything else. Ordinary pages and news articles are
discovered from your **sitemap** in Search Console instead.

If you publish such pages:

1. Enable **Web Search Indexing API** in the Cloud project (§1.1).
2. Make sure the connected account (or the service account) is an **Owner** of
   the property.
3. In the Google panel switch on **Use the Indexing API for eligible pages**,
   tick the post types that are job postings/livestreams (leave all unticked on
   a news site) and save. With OAuth, press **Reconnect Google** once to grant
   the permission.

SearchPilot sends a page to the Indexing API only if its post type is ticked,
it is marked *Job posting* / *Livestream* in the SearchPilot editor panel, or
its content carries `JobPosting` / `BroadcastEvent` structured data. Removed
eligible pages are sent as `URL_DELETED`. The default quota is 200
notifications per day; SearchPilot keeps a small reserve.

---

## 2. Bing Webmaster Tools

1. Sign in at <https://www.bing.com/webmasters>, add your site and verify it
   (the fastest way is **Import from Google Search Console**).
2. Open **Settings (gear icon) → API access → API Key**, generate a key and
   copy it (32 letters and digits).
3. In SearchPilot → Bing paste the key; the site URL defaults to your home URL
   and must match the site exactly as listed in Bing. **Connect Bing**
   validates the key with `GetUserSites` and refuses sites that are missing or
   not verified in that account.

What it enables: Bing clicks/impressions, query and page statistics, crawl
statistics, sitemap submission (`SubmitFeed`) and — optionally — the **Bing URL
Submission API** (`SubmitUrlBatch`, up to 500 URLs per call within your daily
quota, which SearchPilot reads from `GetUrlSubmissionQuota` once a day).
IndexNow already notifies Bing instantly, so URL submission is off by default.

`wp-config.php` alternative: `define( 'SEARCHPILOT_BING_API_KEY', '…' );`

---

## 3. IndexNow

IndexNow notifies **Bing, Yandex, Seznam, Naver and Yep** about new, changed
and removed URLs within minutes. Participating engines share submissions, so
one endpoint is enough (default `api.indexnow.org`). **Google does not use
IndexNow.**

- No account is needed. SearchPilot generates a 32-character key on activation
  and answers `https://example.com/{key}.txt` itself — no file is written and
  no rewrite rule is needed (subdirectory installs work too). Every submission
  also sends the `keyLocation`.
- Press **Verify key file**: SearchPilot fetches its own key URL and compares
  the content. If it fails, purge the page cache/CDN for that URL and make sure
  the web server passes requests for missing `.txt` files to WordPress (the
  default for Apache/LiteSpeed; check custom Nginx rules). A daily check raises
  **IndexNow key file is not reachable** when it breaks.
- **Regenerate key** only if the key leaked or another site uses it; the old
  key stops working immediately.
- If another plugin already sends IndexNow notifications (for example Rank
  Math's *Instant Indexing* module), SearchPilot stays quiet so no URL is sent
  twice and shows an informational issue. Switch it off in the other plugin if
  you prefer SearchPilot's queue, retries and history.

Responses: `200`/`202` accepted; `400`/`403`/`422` are permanent (403 = key not
valid or key file unreachable; 422 = URLs do not belong to the host); `429`
and `5xx` are retried with exponential back-off.

---

## 4. How notifications are sent

All notifications go through **Search Engines → Submission queue**:

- Publishing, significant updates, URL changes (old and new URL), unpublishing,
  trashing and noindex changes are detected automatically (Settings → Indexing
  → *When to notify search engines*).
- Duplicates are merged; a URL sent successfully is not resent within the
  cool-down (default 60 minutes) unless its state changed.
- **Manual mode** holds every automatic submission for one-click release;
  **Assisted/Autopilot** send them automatically.
- Retryable failures back off exponentially (1 min · 2ⁿ, up to 12 h, honouring
  `Retry-After`) for up to 5 attempts; permanent failures are marked failed with
  the provider's message. More than 20 failures in 24 hours open the
  **Search engine submissions are failing** issue.
- **Submit URLs** (top of the Search Engines screen) queues up to 100 URLs of
  this site by hand.
