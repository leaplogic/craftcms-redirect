# Redirects for Craft CMS 5

Leap Logic’s redirect manager lets editors create short links, redirect existing pages, fix broken URLs, and review unresolved 404s. Package: `leaplogic/craftcms-redirect`; plugin handle: `redirects`. Requires PHP 8.2+ and Craft CMS 5.

## Installation

Add the repository to the **site’s** `composer.json`:

```json
{
  "repositories": [
    {"type": "vcs", "url": "git@github.com:leaplogic/craftcms-redirect.git"}
  ]
}
```

Then run from the site root:

```sh
composer require leaplogic/craftcms-redirect:dev-main
php craft plugin/install redirects
```

Composer needs access to the GitHub repository. Once releases are tagged, require a release constraint instead of `dev-main`. The plugin creates its own rules and 404 tables; uninstalling it removes both tables and their data. It never changes `config/redirects.php`.

## Redirect modes and matching

- **Only when the page doesn’t exist (404)** is the default. Live pages continue to render. Redirects run when Craft receives a 404, including 404s wrapped by a Twig runtime exception.
- **Always, even if a page exists** runs before Craft handles the request. Use this for short links or to retire an existing page without deleting it.

Both modes run only for site GET and HEAD requests. Control panel, console, action, token, and preview requests are excluded. A 410 rule returns **Gone**, with no Location header. Other supported statuses are 301 (default), 302, 307, and 308.

Enter an exact or prefix source as a full URL, `/path`, or `path`. The host and query are discarded, the path is URL-decoded and lowercased, and leading/trailing slashes are removed. Use `/` for the home page. Encoded reserved characters such as `%3F` remain part of the matched path. Sources include any base path in the public URL: for `https://example.com/en/old`, enter `/en/old` or the full URL. A full source URL is a convenience for entering the path, not a host restriction; select a site to scope the rule. Destinations that are site-relative are resolved against that site’s configured base URL.

Within the applicable mode and site, the first usable rule wins:

1. **Exact** path matches, ordered by ID.
2. **Prefix** matches, longest source first, then ID. Prefixes match whole path segments: `old` matches `old` and `old/page`, but not `older`. An empty/root prefix matches every path. The configured destination is used as-is; the unmatched suffix is not appended.
3. **Regex** matches, ordered by ID.

Regex sources are PHP patterns **with delimiters**, such as `~^old/([^/]+)$~i`. Their syntax is preserved rather than path-normalized. They run against the normalized lowercase path. Destinations may reference `$1`, `$2`, through `$n`. Invalid regexes are rejected on save. Runtime matching uses bounded PCRE backtracking/recursion limits, restored afterward; PCRE failures are treated as no match and logged as warnings.

Absolute HTTP(S) destinations keep their host, path, and case. Other destinations are built with Craft’s `UrlHelper::siteUrl` for the request’s site. Spaces must be URL-encoded. Protocol-relative URLs, credentials in URLs, non-HTTP schemes, backslashes, and control characters are rejected. Query strings are retained by default and merged before a destination fragment; a key already in the destination takes precedence. Repeated incoming keys and raw encoding are preserved. Craft’s internal path query parameter is excluded.

A rule resolving to the current host and normalized path is skipped, ignoring scheme, query, and fragment, so it cannot redirect to itself. Other matching rules may then apply. This guard does not detect chains between multiple different URLs; avoid creating cycles across rules.

## Control panel and permissions

The **Redirects** section contains searchable, sortable rules with 50 rows per page, editing, enable/disable controls, and confirmed deletion. Rules can apply to every site or one site. A read-only table below the rules displays redirects defined in code; callbacks display `(computed)` without being invoked by the CP listing.

- `redirects-manage`: view, create, edit, delete, enable/disable, and import rules.
- `redirects-view404s`: view and delete 404 log entries and clear the log.
- Creating a redirect from a 404 requires **both** permissions. A successful save removes the originating 404 row in the same database transaction. The source and site are prefilled.
- Admins have both permissions. Logging settings are admin-only.

Either permission makes the navigation item visible; no additional `accessPlugin-redirects` grant is needed. Users still need Craft’s normal CP access. Users with only 404 access land on the 404 screen. Rule management spans all configured sites.

Settings are standard plugin settings stored in Craft’s project config. They are read-only when `allowAdminChanges` is disabled. Configure them in an environment where administrative changes are allowed and deploy project config as usual. No entry picker is included; paste an entry’s URL as the destination.

## 404 log

The log groups normalized paths per site and records hit count, first/last seen, latest referrer, and latest user agent. Paths/referrers are limited to 500 characters; user agents to 255. Only requests with no matching plugin or native code redirect are logged. Logging failures are caught and written to Craft’s log without replacing the 404 response.

The list ranks by hit count and can filter to paths last seen in the past 7 days, 30 days, or all retained history. Counts are cumulative per path, not counts within the selected period. Paths handled by a currently enabled plugin rule in either mode are hidden, respecting site scope and the loop guard. All-time views still respect retention settings.

Defaults:

- Logging enabled.
- Maximum 5,000 rows across all sites; least-hit entries are pruned first, oldest last-seen first on ties.
- Retention of 90 days since the last hit.
- Ignore patterns below, one per line, **without delimiters**, case-insensitive. They affect logging only.

```text
^wp-
\.php$
^\.env
^\.git
xmlrpc
^cgi-bin
^vendor/
^(favicon\.ico|apple-touch-icon.*\.png)$
```

Retention and capacity are enforced during log writes, when viewing the log, and after saving settings. No queue or scheduled task is needed.

## CSV import

Use **Redirects → Import** to paste CSV, preview the counts and row errors, then import the valid rows. The preview is read-only. The import checks the submitted data again, so counts can change if another editor saves a rule between preview and import.

```csv
source,destination,statusCode,mode
/guests,/im-new,301,always
/old-page,/new-page,301,notFound
/removed-page,,410,notFound
```

A header containing `source,destination` is required. `statusCode` and `mode` columns are optional; blank or omitted values default to `301` and `notFound`. Columns may be reordered. UTF-8 BOMs, quoted commas, and quoted multiline fields are supported. Unknown or duplicate header columns and invalid statuses/modes are reported as errors. A blank source means the home page; only completely blank CSV rows are ignored.

Imports create **enabled, exact, all-sites** rules with query preservation enabled. They skip normalized source paths already in the database (including disabled rules or rules for a particular site) and duplicates in the same CSV. They do not overwrite rules. Valid rows are imported even if other rows have errors; the result reports created/skipped counts and errors by CSV record number.

The same importer is available from the console:

```sh
php craft redirects/import /absolute/path/redirects.csv --preview=1
php craft redirects/import /absolute/path/redirects.csv
```

Preview writes nothing. Errors produce a nonzero exit code; successful rows remain imported. Every imported save invalidates the rules cache.

## Precedence with config/redirects.php

Craft’s native code redirects continue working unchanged. On a 404, this plugin checks its `notFound` rules first in `ErrorHandler::EVENT_BEFORE_HANDLE_EXCEPTION`; core checks `config/redirects.php` afterward as the fallback. The plugin uses core `RedirectRule` objects and `getMatch()` to check whether a code rule would handle the request before adding it to the 404 log. It does not send a redirect or fire core’s redirect event during that check. A computed match callback can therefore run twice: once for the log check and once in core; keep these callbacks free of side effects.

Native `RedirectRule` support is available in Craft 5.6+. On earlier Craft 5 releases the plugin’s own rules and log work, but the native-code match check is unavailable. The API reference used for this implementation is Craft 5.11.

## Caching and proxies

Enabled rules use Craft’s data cache with tag-dependency invalidation on every save, delete, enable/disable, or imported rule. A normal eligible request performs one rules-cache read rather than a rules-table query; the first request after invalidation repopulates it. A matching rule records its hit count and last-hit timestamp with one UPDATE. Hit updates do not invalidate the rule cache. CP counts read directly from the database.

Sites behind Leap Logic’s cache box (`nginx proxy_cache`) or Blitz can serve redirects and 404 pages without reaching Craft. Rule changes take effect after the edge/page cache TTL expires or those caches are purged, and 404 counts undercount cached hits. The ranking is still useful. Purge the relevant cache after changing a rule when immediate behavior is needed. This plugin does not purge nginx, Blitz, or browser caches automatically; browsers can also retain permanent redirects.

## Development and verification

Authored and maintained by Michael <michael@leaplogic.net> for Leap Logic.

```sh
composer install
composer test
find src tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

The PHPUnit suite bootstraps only the pure matcher, not Craft. It covers exact/prefix/regex matching, normalization, precedence, substitutions, query merging, 410 responses, loop prevention, ignore patterns, regex failures, and destination validation.

Before deployment, verify install/uninstall migrations on the site’s database, CP permissions and form submissions, multi-site URL resolution, both request hooks, native code fallbacks, CSV preview/import, and 404 pruning in a running Craft environment. The plugin does not require a queue worker.
