Image Finder

This tool scans a site (or a single page / sitemap) and reports unique images used across the scanned pages.
It includes normal `<img>` sources, lazy-loaded image attributes, inline/background CSS image URLs, and can optionally include linked stylesheet backgrounds too.

Usage from UI:
- Open `find-images/index.php` in your browser.
- Fill URL (or sitemap URL), choose a suffix, optional skip slugs, pick CSS coverage, and start the job.
- SVG files are excluded by default; enable the checkbox if you want them included.
- Results are shown as one unique image per row, with a toggle to reveal all page URLs where that image appears.
- You can export all unique image URLs as a plain text file.

Usage from CLI:
- php find-images.php "https://example.com/sitemap.xml" "example-scan" --skip-slug=blog --css-scope=linked

CLI usage:
php find-images.php <url> <suffix> [--single] [--skip-slug=slug1,slug2] [--include-svgs] [--css-scope=page|linked]

Output files (created under `find-images/`):
- `images_found-<suffix>.json` — results file with unique images and the page URLs where each image appears
- `progress-<suffix>.json` — progress tracker while running
- `checked-image-urls-<suffix>.tmp` — internal temporary file of checked URLs

Notes:
- Default CSS scope is `page`, which scans inline style attributes and page `<style>` blocks.
- `linked` CSS scope also fetches linked stylesheets and tries to map matching selectors back to page elements.
- Saved results now keep only unique image records plus page URL lists; page-section metadata is not stored in JSON anymore.

