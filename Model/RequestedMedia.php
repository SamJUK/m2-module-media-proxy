<?php

declare(strict_types=1);

namespace SamJUK\MediaProxy\Model;

use SamJUK\MediaProxy\Api\ConfigInterface;
use SamJUK\MediaProxy\Api\RequestedMediaInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\HTTP\ClientInterface;
use Magento\Framework\HTTP\PhpEnvironment\Request;

class RequestedMedia implements RequestedMediaInterface
{
    /**
     * Catalog image cache segments carry a hash of the image settings, which is
     * unlikely to align across environments. Stripping it resolves the original
     * image, which Magento then resizes locally.
     */
    private const CACHE_SEGMENT_PATTERN = '#/cache/[a-f0-9]{32}/#i';

    /**
     * A path the upstream cannot hold, used to learn what its "file is missing"
     * response looks like. Kept under catalog/product so it passes the upstream's
     * own allowed resource check and reaches Magento rather than the web server.
     */
    private const PLACEHOLDER_PROBE_PATH = 'media/catalog/product/samjuk-media-proxy/probe.jpg';

    /** Cached in place of a hash when the upstream has no placeholder behaviour. */
    private const NO_PLACEHOLDER = '-';

    /**
     * Sent on every upstream request and refused on the way back in. If the
     * upstream ever resolves to this installation, cache and stream modes would
     * otherwise recurse through PHP-FPM until no workers are left, with nothing
     * in the log to say why.
     */
    private const LOOP_HEADER = 'X-SamJUK-Media-Proxy';

    private const CACHE_PREFIX = 'samjuk_media_proxy_';
    private const MISS_LIFETIME = 300;
    private const PLACEHOLDER_LIFETIME = 86400;
    private const MAX_REDIRECTS = 3;

    /**
     * The body is buffered whole before it is written, so an oversized file would
     * exhaust the memory limit, and a fatal cannot be caught and turned into a
     * placeholder. curl aborts instead, which can be.
     */
    private const MAX_DOWNLOAD_BYTES = 33554432;

    /** @readonly */
    private ConfigInterface $config;
    /** @readonly */
    private Filesystem $filesystem;
    /** @readonly */
    private ClientInterface $client;
    /** @readonly */
    private Request $request;
    /** @readonly */
    private CacheInterface $cache;

    /** @var string|null */
    private $relativePath;
    /** @var WriteInterface|null */
    private $pubDirectory;

    public function __construct(
        ConfigInterface $config,
        Filesystem $filesystem,
        ClientInterface $client,
        Request $request,
        CacheInterface $cache
    ) {
        $this->config = $config;
        $this->filesystem = $filesystem;
        $this->client = $client;
        $this->request = $request;
        $this->cache = $cache;
    }

    public function getAbsolutePath(): string
    {
        return $this->getPubDirectory()->getAbsolutePath($this->getRelativePath());
    }

    public function getUpstreamUrl(): string
    {
        return $this->getUpstreamBase() . '/' . $this->getRelativePath();
    }

    public function getUpstreamRedirectUrl(): string
    {
        $query = $this->getQueryString();

        return $query === '' ? $this->getUpstreamUrl() : $this->getUpstreamUrl() . '?' . $query;
    }

    public function exists(): bool
    {
        return $this->getPubDirectory()->isFile($this->getRelativePath());
    }

    public function download(): array
    {
        $path = $this->getRelativePath();

        if ($this->request->getHeader(self::LOOP_HEADER) !== false) {
            throw new LocalizedException(
                __('Refusing to fetch %1, the upstream resolves back to this installation.', $path)
            );
        }

        $missKey = $this->cacheKey('miss', $path);

        // Without this every render of a page holding a missing image re-asks the
        // upstream, which is usually somebody's production site.
        if ((string)$this->cache->load($missKey) !== '') {
            throw new LocalizedException(__('Upstream is known not to hold %1.', $path));
        }

        try {
            return $this->fetch();
        } catch (\Throwable $e) {
            $this->cache->save('1', $missKey, [], self::MISS_LIFETIME);
            throw $e;
        }
    }

    public function sync(): bool
    {
        $download = $this->download();

        return $this->write($this->getRelativePath(), $download['body']);
    }

    private function write(string $path, string $body): bool
    {
        $directory = $this->getPubDirectory();
        $temporaryPath = $path . '.' . bin2hex(random_bytes(8)) . '.mediaproxy';

        // Written aside and moved into place so that concurrent requests for the
        // same image never serve a half downloaded file.
        try {
            $directory->writeFile($temporaryPath, $body);
            $directory->renameFile($temporaryPath, $path);
        } catch (\Throwable $e) {
            // The write or move is the failure worth reporting, not the tidy up.
            // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
            try {
                $directory->delete($temporaryPath);
            } catch (\Throwable $cleanupFailure) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
                $cleanupFailure = null;
            }
            throw $e;
        }

        return true;
    }

    /** @return array{body: string, contentType: string} */
    private function fetch(): array
    {
        // Resolved first: it issues a request of its own on the shared client.
        $placeholderHash = $this->getUpstreamPlaceholderHash();

        $url = $this->getUpstreamUrl();
        $response = $this->requestUpstream($url);

        if ($placeholderHash !== '' && hash('sha256', $response['body']) === $placeholderHash) {
            throw new LocalizedException(
                __(
                    'Upstream served its placeholder for %1, so it does not hold the file.',
                    $this->withoutCredentials($url)
                )
            );
        }

        return $response;
    }

    /**
     * Magento answers a request for media it does not hold with its placeholder
     * image and a 200, which on its own is indistinguishable from a real image.
     * Ask once for a path that cannot exist, and remember what missing looks like.
     */
    private function getUpstreamPlaceholderHash(): string
    {
        $key = $this->cacheKey('placeholder', '');
        $cached = (string)$this->cache->load($key);
        if ($cached !== '') {
            return $cached === self::NO_PLACEHOLDER ? '' : $cached;
        }

        $hash = self::NO_PLACEHOLDER;
        try {
            $probe = $this->requestUpstream($this->getUpstreamBase() . '/' . self::PLACEHOLDER_PROBE_PATH);
            $hash = hash('sha256', $probe['body']);
        } catch (\Throwable $e) {
            // An upstream that answers a missing file honestly needs no detection.
            $hash = self::NO_PLACEHOLDER;
        }

        $this->cache->save($hash, $key, [], self::PLACEHOLDER_LIFETIME);

        return $hash === self::NO_PLACEHOLDER ? '' : $hash;
    }

    /** @return array{body: string, contentType: string} */
    private function requestUpstream(string $url): array
    {
        $options = [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => self::MAX_REDIRECTS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_MAXFILESIZE => self::MAX_DOWNLOAD_BYTES
        ];

        $username = $this->config->getUpstreamUsername();
        if ($username !== '') {
            // Set as a curl option rather than through setCredentials(), which adds a
            // literal Authorization header. Custom headers survive a cross host
            // redirect; CURLOPT_USERPWD is dropped by curl unless told otherwise.
            $options[CURLOPT_USERPWD] = $username . ':' . $this->config->getUpstreamPassword();
            $options[CURLOPT_UNRESTRICTED_AUTH] = false;
        }

        $this->client->setTimeout($this->config->getTimeout());
        $this->client->setOptions($options);
        $this->client->addHeader(self::LOOP_HEADER, '1');
        $this->client->get($url);

        $status = $this->client->getStatus();
        if ($status !== 200) {
            throw new LocalizedException(
                __('Upstream responded with HTTP %1 for %2.', $status, $this->withoutCredentials($url))
            );
        }

        // A login wall or error page served with a 200 would otherwise be cached
        // in place of the image, and every later request would serve it.
        $contentType = $this->getResponseContentType();
        if ($contentType !== '' && strncmp($contentType, 'text/', 5) === 0) {
            throw new LocalizedException(
                __(
                    'Upstream returned "%1" rather than a media file for %2.',
                    $contentType,
                    $this->withoutCredentials($url)
                )
            );
        }

        $body = $this->client->getBody();
        if ($body === '') {
            throw new LocalizedException(
                __('Upstream returned an empty body for %1.', $this->withoutCredentials($url))
            );
        }

        return ['body' => $body, 'contentType' => $contentType];
    }

    /**
     * Anything derived from the upstream host can carry credentials, and these
     * strings end up in var/log. Strip the userinfo before they get there.
     */
    private function withoutCredentials(string $url): string
    {
        // Anchored, and the userinfo cannot span a '/', so a '@' inside a path is safe.
        return (string)preg_replace('#^([a-z][a-z0-9+.-]*://)?[^/@]*@#i', '$1', $url);
    }

    /**
     * Taken raw from the request target rather than decoded, so it reaches the
     * upstream exactly as the browser sent it.
     */
    private function getQueryString(): string
    {
        $requestUri = (string)$this->request->getRequestUri();
        $position = strpos($requestUri, '?');

        return $position === false ? '' : substr($requestUri, $position + 1);
    }

    private function getUpstreamBase(): string
    {
        $host = $this->config->getUpstreamHost();
        if (!preg_match('#^https?://#i', $host)) {
            throw new LocalizedException(
                __('Upstream host must be an absolute http(s) URL, got "%1".', $this->withoutCredentials($host))
            );
        }

        return $host;
    }

    private function getResponseContentType(): string
    {
        foreach ($this->client->getHeaders() as $name => $value) {
            if (strcasecmp((string)$name, 'Content-Type') === 0) {
                return strtolower(trim(explode(';', (string)$value)[0]));
            }
        }

        return '';
    }

    private function cacheKey(string $kind, string $path): string
    {
        return self::CACHE_PREFIX . $kind . '_' . sha1($this->config->getUpstreamHost() . '|' . $path);
    }

    private function getRelativePath(): string
    {
        if ($this->relativePath !== null) {
            return $this->relativePath;
        }

        // Sanitised the same way Magento\MediaStorage\Model\File\Storage\Request
        // does for pub/get.php, then normalised and bounds checked below.
        $requested = (string)preg_replace(
            self::CACHE_SEGMENT_PATTERN,
            '/',
            str_replace('..', '', ltrim((string)$this->request->getPathInfo(), '/'))
        );

        if ($requested === '') {
            throw new LocalizedException(__('No media file was requested.'));
        }

        $directory = $this->getPubDirectory();
        $driver = $directory->getDriver();

        $absolutePath = $this->normalise($driver->getRealPathSafety($directory->getAbsolutePath($requested)));
        $mediaRoot = $this->normalise(
            $driver->getRealPathSafety(
                $this->filesystem->getDirectoryRead(DirectoryList::MEDIA)->getAbsolutePath()
            )
        ) . '/';

        if (strncmp($absolutePath, $mediaRoot, strlen($mediaRoot)) !== 0) {
            throw new LocalizedException(
                __('The requested path resolves outside of the media directory: %1', $requested)
            );
        }

        return $this->relativePath = $directory->getRelativePath($absolutePath);
    }

    /** getRealPathSafety() emits DIRECTORY_SEPARATOR, the rest of Magento uses '/'. */
    private function normalise(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function getPubDirectory(): WriteInterface
    {
        if ($this->pubDirectory === null) {
            $this->pubDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::PUB);
        }

        return $this->pubDirectory;
    }
}
