# Changelog

All notable changes to this module are documented here. This project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-09-19

Breaking. Previous release was `v1.0.1`.

### Fixed
- `RequestedMedia` asked for `Magento\Framework\Filesystem\DriverInterface`, which
  Magento core never binds, it is passed explicitly per type instead. The object
  manager could only build it on installs where some third party module happens to
  declare a global preference (`amasty/base` does), so the module worked on some
  sites and fataled on every media miss on others.
- An upstream that resolved back to the same installation made cache and stream
  modes recurse through PHP-FPM until no workers were left, with nothing in the log
  to explain it. Every upstream request now carries `X-SamJUK-Media-Proxy` and is
  refused on the way back in.
- A failure while tidying up the temporary file masked the write or move failure
  that caused it.
- A failed write left the temporary file behind; only a failed rename cleaned up.
- The media directory bounds check compared a path built with `DIRECTORY_SEPARATOR`
  against one built with `/`, so on Windows it rejected everything.
- Cache mode created a *directory* at the path of the image it was about to write,
  which made the write fail and left a directory shadowing the file. Later requests
  for that image 404'd permanently. It happened on every miss, including ones where
  the upstream was unreachable, because the directory was created before the fetch.
  Writes now go to a temporary file that is renamed into place, so concurrent
  requests cannot serve a partial image either.

  An install that ran 1.x cache mode can clear the directories it left behind. They
  are always empty, so `rmdir` refuses anything that is not one of them:

  ```sh
  find pub/media -type d -regex '.*\.\(jpe?g\|png\|gif\|webp\|svg\)$' -exec rmdir {} +
  ```
- The requested path was read from `$GLOBALS['relativePath']`, which is only set
  inside `pub/get.php`. It comes from the HTTP request now, sanitised the same way
  `Magento\MediaStorage\Model\File\Storage\Request` does it for `pub/get.php`.
- Configuration was read at `default` scope only, so the website scope offered by
  the admin form was silently ignored. It is read at store scope now.
- `Magento\Framework\Filesystem\Driver\File::fileGetContents()` throws on failure,
  so an unreachable upstream surfaced as a 404 instead of the placeholder image.
  Failures are now logged and the request falls through to Magento.
- Added the missing `etc/acl.xml`, without which the admin form's
  `SamJUK_MediaProxy::config` resource could not be granted to a role.

### Security
- Upstream credentials reached `var/log`. Failure messages carry the upstream URL,
  and a host configured as `https://user:pass@host` put the password in every one
  of them. The userinfo is stripped before the message is built, with or without a
  scheme.
- HTTP basic auth credentials were sent as a literal `Authorization` header via
  `Magento\Framework\HTTP\Client\Curl::setCredentials()`. curl replays custom
  headers across a cross host redirect, so a redirecting upstream could have been
  handed the credentials. They now go through `CURLOPT_USERPWD`, which curl scopes
  to the first host, with `CURLOPT_UNRESTRICTED_AUTH` off.
- Cache mode could be used to fill the disk and to amplify traffic onto the
  upstream, which is usually a production site: each request for a unique
  nonexistent media path caused an upstream fetch and a write. Failed lookups are
  now remembered for 5 minutes, and a response matching the upstream's placeholder
  is not written at all.
- The download target is normalised and checked to resolve inside the media
  directory before anything is written.
- Upstream responses are rejected unless they are a `200` with a non-`text/*`
  content type, so an upstream error page or login wall is no longer cached in
  place of an image.
- The upstream host must be an absolute `http(s)` URL. Redirects are followed but
  capped at 3 and restricted to http/https.

### Added
- A third mode, `stream`, which fetches through PHP and keeps nothing on disk. It
  is the answer for an upstream behind HTTP basic auth: proxy mode redirects the
  browser, which has no credentials, and putting them in the host instead leaks
  them through the `Location` header and is stripped by browsers for subresources
  anyway.
- Proxy mode forwards the original query string. Under the
  `image_optimization_based_on_query_parameters` media URL format that is how a
  size is asked for, so redirecting without it returned the full size original.
  Cache and stream modes still fetch without it, on purpose: storing a resized
  variant at the original's path would poison it for every other size.
- A redirect to an upstream host carrying credentials is sent `no-store`, so it
  cannot come to rest in a browser history or a shared Varnish.
- The upstream's "file is missing" placeholder is detected and not cached as if it
  were the image. Magento answers a missing media request with its own placeholder
  and a `200`; the module learns that signature once per day from a probe path that
  cannot exist.
- Failed upstream lookups are cached for 5 minutes, so a page of missing images
  does not re-ask the upstream on every render.
- The proxy redirect carries `Cache-Control: max-age=300`. Each miss that reaches
  the module costs a full Magento bootstrap, and this keeps a reload from paying it
  again.
- Downloads are capped at 32MB via `CURLOPT_MAXFILESIZE`. Buffering a larger file
  exhausted the memory limit, and that fatal could not be caught and turned into a
  placeholder.
- The admin's Upstream Host field validates that it is a URL, rather than failing
  silently at the first media request.
- Upstream HTTP basic auth (`upstream_username` / `upstream_password`), for
  staging environments behind a password. The password is stored encrypted.
- Configurable upstream timeout, defaulting to 10 seconds.
- Proxy mode no longer redirects away from an image that is present locally.

### Changed
- **Breaking.** `SamJUK\MediaProxy\Model\Config\Source\Mode` now implements
  `Magento\Framework\Data\OptionSourceInterface` rather than extending
  `Magento\Eav\Model\Entity\Attribute\Source\AbstractSource`. `getAllOptions()`
  is gone, the undeclared Magento_Eav dependency with it.
- **Breaking.** `SamJUK\MediaProxy\Api\ConfigInterface` gained `getTimeout()`,
  `getUpstreamUsername()`, `getUpstreamPassword()` and `isStreamMode()`.
- **Breaking.** `SamJUK\MediaProxy\Api\RequestedMediaInterface` gained
  `getUpstreamRedirectUrl()` and `download()`.
- **Breaking.** The constructors of `Model\Config`, `Model\RequestedMedia` and
  `Plugin\Media` changed.
- `composer.json` now declares its Magento dependencies, `etc/module.xml` its
  sequence.
- The `Makefile`'s default `RELATIVE_PROJECT_DIR` was one level short for the
  `app/code/<vendor>/<module>` layout it documents.
- `_local_test.sh` could not run at all: it read a CI matrix path that no longer
  exists, built image tags as `8.3-magento2.4.8` when the publisher moved to
  `php83-fpm-magento2.4.8`, and the image no longer ships `make`.
- PHPStan raised from level 1 to level 6.
