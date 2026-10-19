# Security Policy

## Reporting a vulnerability

Please do not publish exploitable vulnerability details, credentials, or private user data in a public issue.

Contact **creativetechcoderz@gmail.com** with:
- A concise description of the issue and its impact.
- The affected plugin version and WordPress/PHP versions.
- Reproduction steps that do not expose real user data.
- Any suggested mitigation.

Please allow time for the maintainer to investigate and coordinate a fix before public disclosure.

## Security expectations

Security-sensitive changes should be reviewed for authorization checks, nonce validation where appropriate, URL/redirect validation, SSRF protections, request throttling, output escaping, input sanitization, and privacy implications. Automated syntax checks alone are not a security audit.
