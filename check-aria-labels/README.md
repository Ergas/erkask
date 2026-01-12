ARIA Attributes Checker

This tool scans a site (or a single page / sitemap) and reports elements that have ARIA attributes with empty values (for example, aria-label="").

Usage from UI:
- Open `check-aria-labels/index.php` in your browser.
- Fill URL (or sitemap URL), choose a suffix, toggle which attributes to check, and start the job.

Usage from CLI:
- php check-aria.php "https://www.bigbank.lt/sitemap.xml" "LT-test" --skip-slug=blog --attrs=aria-label


CLI usage:
php check-aria.php <url> <suffix> [--single] [--skip-slug=slug1,slug2] [--attrs=aria-label,aria-labelledby]

Output files (created under `check-aria-labels/`):
- aria_issues-<suffix>.json — results file with issues
- progress-<suffix>.json — progress tracker while running
- checked-aria-urls-<suffix>.tmp — internal temporary file of checked URLs

Notes:
- The script mirrors the behavior and UI of the existing `check-for-headings` tool.
- Default attribute to check is `aria-label` if none selected.

