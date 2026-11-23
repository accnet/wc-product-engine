<?php

declare(strict_types=1);

namespace WPE\Application\Feeds;

/**
 * Public feed URL that Merchant Center fetches (D10, 10 §12):
 *   /wpe-feed/{feed_key}/{token}.{xml|tsv}[.gz]   or   /?wpe_feed={feed_key}&token=…&format=xml|tsv[&gz=1]
 *
 * Only the last published file is served; no product data is read in the request. A wrong key,
 * token or extension, a disabled feed and a feed without a file all return the same 404, so URLs
 * cannot be probed. The nginx FastCGI cache is told not to store the body (X-Accel-Expires: 0).
 */
final class FeedEndpoint
{
    private const PATH_PATTERN = '#/wpe-feed/([a-z0-9-]{1,64})/([a-f0-9]{64})\.(xml|tsv)(\.gz)?$#';

    private FeedRepository $feeds;
    private FeedBuilder $builder;

    public function __construct(?FeedRepository $feeds = null, ?FeedBuilder $builder = null)
    {
        $this->feeds = $feeds ?? new FeedRepository();
        $this->builder = $builder ?? new FeedBuilder(null, $this->feeds);
    }

    public static function register(): void
    {
        add_filter('do_parse_request', static function ($continue) {
            $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
            $isPretty = str_contains($uri, '/wpe-feed/');
            $isQuery = isset($_GET['wpe_feed']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public token-checked feed URL
            if (!$isPretty && !$isQuery) {
                return $continue;
            }
            (new self())->serve($uri, wp_unslash($_GET), $_SERVER); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            exit;
        }, 1);
    }

    /**
     * Decide the response for a request without emitting it.
     *
     * @param array<string,mixed> $query
     * @param array<string,mixed> $server
     * @return array{status:int,headers:array<string,string>,file:?string}
     */
    public function resolve(string $uri, array $query, array $server): array
    {
        $notFound = ['status' => 404, 'headers' => ['Content-Type' => 'text/plain; charset=UTF-8', 'X-Accel-Expires' => '0', 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex'], 'file' => null];

        $path = (string)parse_url($uri, PHP_URL_PATH);
        if (preg_match(self::PATH_PATTERN, $path, $m)) {
            [$key, $token, $ext, $gz] = [$m[1], $m[2], $m[3], ($m[4] ?? '') !== ''];
        } elseif (isset($query['wpe_feed'])) {
            $key = (string)$query['wpe_feed'];
            $token = strtolower((string)($query['token'] ?? ''));
            $ext = (string)($query['format'] ?? '');
            $gz = !empty($query['gz']);
        } else {
            return $notFound;
        }
        if (!preg_match('/^[a-z0-9-]{1,64}$/', $key) || !in_array($ext, ['xml', 'tsv'], true)) {
            return $notFound;
        }

        $feed = $this->feeds->getByKey($key);
        if (!$feed || !$this->feeds->verifyToken($feed, $token) || !$feed['enabled'] || $feed['format'] !== $ext) {
            return $notFound;
        }
        $file = $this->builder->publishedFile($feed, $gz);
        if ($file === null) {
            return $notFound;
        }

        clearstatcache(true, $file);
        $mtime = (int)filemtime($file);
        $etag = '"' . substr(hash('sha256', (string)($feed['stats']['published']['etag'] ?? '') . ($gz ? 'gz' : '') . $mtime), 0, 32) . '"';
        $headers = [
            'Content-Type'    => $gz ? 'application/gzip' : ($ext === 'xml' ? 'application/xml; charset=UTF-8' : 'text/tab-separated-values; charset=UTF-8'),
            'Last-Modified'   => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
            'ETag'            => $etag,
            'Cache-Control'   => 'no-cache',
            'X-Accel-Expires' => '0',
            'X-Robots-Tag'    => 'noindex',
            'Content-Disposition' => 'inline; filename="' . $key . '.' . $ext . ($gz ? '.gz' : '') . '"',
        ];

        $ifNoneMatch = trim((string)($server['HTTP_IF_NONE_MATCH'] ?? ''));
        $ifModifiedSince = (string)($server['HTTP_IF_MODIFIED_SINCE'] ?? '');
        if (($ifNoneMatch !== '' && $ifNoneMatch === $etag)
            || ($ifNoneMatch === '' && $ifModifiedSince !== '' && strtotime($ifModifiedSince) >= $mtime)) {
            return ['status' => 304, 'headers' => $headers, 'file' => null];
        }

        $headers['Content-Length'] = (string)filesize($file);
        return ['status' => 200, 'headers' => $headers, 'file' => $file];
    }

    /**
     * Emit the response and stream the file in 1 MB chunks.
     */
    public function serve(string $uri, array $query, array $server): void
    {
        $res = $this->resolve($uri, $query, $server);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        status_header($res['status']);
        nocache_headers();
        foreach ($res['headers'] as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($res['status'] === 404) {
            echo 'Not found';
            return;
        }
        if ($res['file'] === null || strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') {
            return;
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        $fh = fopen($res['file'], 'rb');
        if ($fh === false) {
            return;
        }
        while (!feof($fh)) {
            echo fread($fh, 1 << 20);
            flush();
        }
        fclose($fh);
    }
}
