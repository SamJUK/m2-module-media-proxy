# Security Policy

## Supported Versions

Only the latest tagged release is supported with security fixes.

## Scope

This module is for development and integration environments, and it fetches from a
host an administrator configures. Point it at an upstream you trust: it follows
redirects (capped at 3, http and https only), so a hostile upstream could redirect a
fetch at an internal address and have the response written into a publicly readable
media directory.

## Reporting a Vulnerability

Please **do not** open a public GitHub issue for security vulnerabilities.

Instead, report it privately via
[GitHub Security Advisories](https://github.com/SamJUK/m2-module-media-proxy/security/advisories/new).

You should get a response within a few days. Once confirmed, a fix will be
released and the advisory published with credit (unless you prefer to stay
anonymous).
