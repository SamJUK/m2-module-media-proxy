# SamJUK_MediaProxy

[![Supported Magento Versions](https://img.shields.io/badge/magento-2.4.3%E2%80%932.4.8-orange.svg?logo=magento)](https://github.com/SamJUK/m2-module-media-proxy/actions/workflows/ci.yml)
[![CI Workflow Status](https://github.com/samjuk/m2-module-media-proxy/actions/workflows/ci.yml/badge.svg)](https://github.com/SamJUK/m2-module-media-proxy/actions/workflows/ci.yml)
[![GitHub Release](https://img.shields.io/github/v/release/SamJUK/m2-module-media-proxy?label=Latest%20Release&logo=github)](https://github.com/SamJUK/m2-module-media-proxy/releases)

Proxy or download missing media from an upstream environment, so a local install does not need a copy of every image.

Useful when you work across several large projects, and for getting a new developer running without a media sync first.

Three modes:

- **Proxy** answers with a `302` to the upstream. Nothing is written or read locally, but the browser fetches the image itself, so the upstream has to be publicly reachable.
- **Cache** downloads the file once and serves it locally from then on. Costs disk, improves TTFB, and keeps third party image extensions happy.
- **Stream** fetches through PHP on every request and keeps nothing. Zero disk and credentials never leave the server, at the cost of a Magento bootstrap and an upstream round trip per image.

For development and integration environments. **Not for production.**

Infrastructure level alternatives are over on my [documentation site](https://docs.sdj.pw/magento/media-management.html).

## Installation

```sh
composer require samjuk/m2-module-media-proxy
php bin/magento module:enable SamJUK_MediaProxy && php bin/magento cache:flush
```

## Configuration

The default configuration does nothing. Configure from the Media Proxy menu of the SamJUK tab in system configuration, or from the CLI:

```sh
php bin/magento config:set --lock-env samjuk_media_proxy/general/enabled 1
php bin/magento config:set --lock-env samjuk_media_proxy/general/mode 'proxy'
php bin/magento config:set --lock-env samjuk_media_proxy/general/upstream_host 'https://www.example.com'
```

Option | Config Path | Default | Description
--- | --- | --- | ---
Enabled | `samjuk_media_proxy/general/enabled` | `0` | Feature flag for the whole module
Mode | `samjuk_media_proxy/general/mode` | `proxy` | `proxy`, `cache` or `stream`
Upstream Host | `samjuk_media_proxy/general/upstream_host` | `-` | Absolute http(s) URL to fetch missing media from
Upstream Timeout | `samjuk_media_proxy/general/timeout` | `10` | Seconds to wait on the upstream. Cache and stream modes
Upstream Username | `samjuk_media_proxy/general/upstream_username` | `-` | HTTP basic auth username. Cache and stream modes
Upstream Password | `samjuk_media_proxy/general/upstream_password` | `-` | HTTP basic auth password, stored encrypted. Cache and stream modes

Config is read at store scope, so on a multi-site install `MAGE_RUN_CODE` gives each website its own upstream.

### An upstream behind HTTP basic auth

Proxy mode cannot present credentials, because the browser fetches the image rather than Magento. Use cache or stream mode, which fetch server side.

```sh
php bin/magento config:set samjuk_media_proxy/general/upstream_username 'staging'
php bin/magento config:set samjuk_media_proxy/general/upstream_password 'hunter2'
```

The password uses the encrypted backend model, so set it with `config:set` rather than writing it into `env.php`. Do not put the credentials in `upstream_host` instead: browsers strip them from subresource requests, and they would end up in a `Location` header on every miss.

## How it works

Magento only routes a media request through `pub/get.php` when the file is missing from disk, so the module only ever sees genuine misses. Its plugin runs before `Magento\MediaStorage\App\Media::launch()` and either redirects to the upstream, downloads and writes the file, or downloads and serves it.

Catalog cache segments (`/cache/<32 char hash>/`) are stripped from the path first. That hash is derived from the image settings of whichever environment generated it, so it rarely aligns across installations. Stripping it resolves the original image, which Magento then resizes locally.

A Magento upstream answers a request for media it does not hold with its own placeholder image and a `200`, which on its own is indistinguishable from a real image. So on first use the module asks for a path that cannot exist and remembers the SHA-256 of whatever comes back, for 24 hours. Anything matching that signature later is treated as missing. An upstream that answers honestly with a `404` needs no signature and does not get one.

Failed lookups are remembered for five minutes, so a page of missing images does not re-ask the upstream on every render. `bin/magento cache:flush` clears both.

Anything that fails is logged and falls through to Magento untouched, so you get the usual placeholder rather than an error.

## Limitations

- Cache mode never revisits a file once it has it, and proxied or streamed responses carry `Cache-Control: max-age=300`. After changing media upstream or switching mode, delete the local copy and purge Varnish.
- `.webp` and `.avif` derivatives served out of the media directory by a next generation image module are not resolved back to their source, so those requests fall back to the placeholder.

## Development

```sh
make help            # list the available targets
make test-unit       # phpunit
make test-phpstan    # phpstan, level 6
make test-phpcs      # Magento2 coding standard
make local-tests     # the whole CI matrix, in docker
```
