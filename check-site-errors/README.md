# Site Error Scanner

Playwright-based scanner for checking page errors and browser-side issues across a sitemap or a single page.

## What it captures

- Main page HTTP failures (`404`, `500`, timeouts, navigation errors)
- Subresource HTTP failures (`script`, `stylesheet`, `fetch`, etc.)
- Failed network requests (`requestfailed`)
- Browser console errors
- Optional browser console warnings
- Uncaught page exceptions (`pageerror`)

## UI usage

1. Open `check-site-errors/index.php` in your browser.
2. Enter a sitemap URL or a single page URL.
3. Choose a suffix for the output file.
4. Optionally set a sleep time in seconds between each top-level sitemap/page request (defaults to 8 seconds).
5. Optionally add skip slugs to exclude matching URL paths before scanning.
6. Optionally enable console warnings.
7. Add ignore filters for console messages or request URLs.
8. Start the scan and monitor progress from the page.
9. After the scan finishes, use the results search box and the post-scan hide filters to narrow what stays visible in the UI.

### Skip slugs

Skip slugs remove matching URLs from the crawl list before any page scans begin.

- In the UI, enter them comma-separated or one per line.
- In JSON config, use `crawl.skipSlugs`.

Examples:

- `blog` skips `/blog/...`
- `author` skips `/author/...`
- `et/kampaaniad` skips `/et/kampaaniad/...`

### Filter syntax in the UI

Each line in the ignore textareas becomes one filter:

- Plain text = substring match
- `exact:Some full message` = exact match
- `regex:.*pattern.*` = regular expression match

### Post-scan filtering in the results view

Completed scan results can be filtered in the browser without changing the saved JSON file:

- Search across page metadata and issue details
- Hide issue rows by selecting one or more issue types
- Add one substring per line for issue messages/failure text to hide

Example:

- choose `request-failed`
- add `net::ERR_NAME_NOT_RESOLVED`

This hides matching `request-failed` rows from the current results view only.

## CLI usage

Install dependencies first:

```bash
npm install
npx playwright install chromium
```

Run the scanner with a config file:

```bash
node check-site-errors/run-check.mjs \
  --config check-site-errors/config.example.json \
  --output check-site-errors/results/site-errors-example.json \
  --progress check-site-errors/progress/progress-example.json \
  --run-id example
```

### Request pacing

Use `crawl.sleepBetweenRequestsSeconds` to add a delay between each top-level request that the scanner makes. The default is `8` seconds:

- sitemap fetches
- scanned page navigations

This is useful for being gentler on the target server. If you want the slowest and safest behavior, combine it with `concurrency: 1`.

You can also use `crawl.skipSlugs` in the same config to exclude paths you already know you do not want scanned.

## Output files

Created inside `check-site-errors/`:

- `results/site-errors-<suffix>.json` — scan results
- `progress/progress-<suffix>.json` — live progress state while a scan runs
- `configs/config-<suffix>.json` — saved config used for that run
- `logs/scan-<suffix>.log` — stdout/stderr for the background process started by the UI

## Test harness

A local end-to-end test server and assertions are included:

```bash
npm run test:site-errors
```

This spins up a temporary local server, runs the scanner twice, and verifies:

- page HTTP errors
- request HTTP errors
- console errors
- optional warnings
- ignore filters
- uncaught page errors
- request pacing / sleep between requests
- skip slug URL exclusions

