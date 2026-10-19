# Contributing

Thank you for helping improve CTC Website Audit Pro.

## Before making changes

1. Create a separate branch for the change.
2. Keep each change focused and explain its purpose in the commit or pull request.
3. Do not commit credentials, API keys, passwords, customer data, production URLs containing private information, or database exports.
4. Avoid unrelated formatting changes to the main plugin file.

## Validation checklist

Before requesting review:

- Run `php -l ctc-website-audit.php` with a supported PHP version.
- Test on a staging WordPress installation.
- Check shortcode rendering on desktop and mobile.
- Test the relevant REST route, including invalid input, unauthorized requests, rate limiting, and error responses.
- Verify CSV output and print/PDF layout.
- Confirm that scheduled monitoring can be enabled and disabled correctly.
- Document any behavior changes and update CHANGELOG.md.

Automated PHP syntax checks are useful but do not establish that a feature works correctly or is secure.

## Pull requests

Describe the problem, the proposed change, how it was tested, and any known limitations. For security-sensitive findings, avoid publishing exploit details or secrets in a public issue; contact the project maintainer privately.
