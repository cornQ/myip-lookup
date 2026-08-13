# Security Policy

## Supported versions

Security fixes are provided for the latest version on the default branch.

## Reporting a vulnerability

Please do not disclose suspected vulnerabilities in a public issue.

Use the repository's private vulnerability-reporting feature under the
**Security** tab. Include the affected route or component, reproduction steps,
impact, and any suggested mitigation. Remove real IP addresses, database
credentials, diagnostic tokens, and other personal data from the report.

You should receive an acknowledgement within seven days. Please allow a
reasonable period for investigation and remediation before public disclosure.

## Secrets

`config.php` is intentionally ignored by Git. Never commit database passwords,
hosting credentials, live diagnostic URLs, or production database exports.

