# CTC Website Audit Pro

A WordPress plugin by Creative Tech Coderz that reviews selected technical SEO, on-page, content, local SEO, AI-search-readiness, and website-speed signals for a public website URL.

> **Current plugin version:** 3.3.13  
> **Minimum WordPress version:** 6.2  
> **Minimum PHP version:** 7.4

Audit results are automated indicators, not guarantees or a substitute for manual review. Confirm findings before changing a production website.

## Features

- Technical SEO checks, including robots.txt and sitemap signals, canonical tags, redirects, link checks, and selected crawlability signals.
- On-page checks for page titles, meta descriptions, headings, image alt attributes, viewport settings, and social metadata.
- Content-quality signals and basic AI SEO / GEO / AEO technical-readiness checks.
- Local SEO signals that should be manually verified against real business listings and contact information.
- Optional Google PageSpeed Insights integration.
- CSV export and branded print-friendly reports that can be saved as PDF from the browser.
- Audit history and optional scheduled homepage monitoring.
- A review-first workflow for supported title and excerpt changes, with change logging and rollback support.
- A request form for larger website audits.

Feature availability and results depend on the target website, server responses, configuration, and the audit options selected.

## Installation

1. Download or clone this repository.
2. Place the plugin PHP file in a folder named `ctc-website-audit-pro`.
3. Zip that folder if you need an installable package.
4. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin**, upload the ZIP, and activate it.
5. Add the shortcode `[ctc_website_audit]` to a page where you want the audit form to appear.
6. Open that page and test with a public website URL you own or are authorized to audit.

The repository currently contains the main plugin file, `ctc-website-audit.php`. It does not currently include a prebuilt release ZIP.

## Configuration and use

- Open **CTC Website Audit Pro** in the WordPress administration menu to review settings.
- The Google PageSpeed Insights integration is optional. Configure it only if you want to use the external API.
- The public audit endpoint applies request limits and limits instant audits to 100 pages. Requests above that limit are directed to the larger-audit request process.
- Scheduled monitoring is optional and should be enabled only when wanted.
- Review findings before applying any recommended changes. Supported approved fixes in this version are limited to page titles and excerpts; other content and SEO-plugin metadata are not automatically changed.

## REST API routes

The plugin registers routes under `/wp-json/ctc-audit/v1/`:

- `POST /scan` — run an audit for a public HTTP(S) URL.
- `POST /large-audit-request` — submit a request for a larger audit.
- `POST /apply-approved-fix` — update a supported page field after WordPress capability and nonce checks.
- `POST /rollback-approved-fix` — roll back an eligible logged change after capability and nonce checks.

Use the routes only in accordance with the site's authorization, rate limits, and privacy obligations. Do not use the scanner against websites without permission.

## Security notes

The scanner is designed to accept public HTTP(S) targets and includes URL validation, redirect checks, response-size limits, and request throttling. These safeguards should still be tested as the code evolves. Keep WordPress and PHP updated, use HTTPS, restrict administrator accounts, and review any security reports promptly.

The large-audit form handles contact details and sends email using the WordPress site's configured mail function. Publish an appropriate privacy policy and terms, and only submit information with the required authorization.

## Development and validation

- Main plugin file: `ctc-website-audit.php`
- CI workflow: GitHub Actions runs a PHP syntax check when PHP files or the workflow change.
- Before a release, also test activation, shortcode rendering, each REST route, CSV export, print/PDF layout, scheduled monitoring, rate limiting, and mobile browser behavior on a staging WordPress site.
- CI syntax validation does **not** replace functional, security, compatibility, or performance testing.

## Version history

See [CHANGELOG.md](CHANGELOG.md).

## Support

For project support, contact **creativetechcoderz@gmail.com**. Do not include passwords, API keys, private customer data, or other secrets in issues.

## License

No open-source license is currently included in this repository. Unless a license is added, do not assume the code is available for redistribution or reuse. Contact the copyright holder for permission.
