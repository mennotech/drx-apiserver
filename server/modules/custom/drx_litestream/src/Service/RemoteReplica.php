<?php

declare(strict_types=1);

namespace Drupal\drx_litestream\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;

/**
 * Computes the total size of the litestream replica in remote object storage.
 *
 * Walks the configured S3 prefix with a minimal SigV4 ListObjectsV2
 * implementation, sums object sizes, and caches the result. Running this
 * on every admin page render would be slow and would add LIST request
 * cost that scales with the number of stored objects, so the snapshot
 * is cached and refreshed lazily (or on operator request).
 */
class RemoteReplica {

  protected const CACHE_KEY = 'drx_litestream:remote_size';

  public function __construct(
    protected LitestreamStatus $status,
    protected CacheBackendInterface $cache,
    protected ClientInterface $http,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected TimeInterface $time,
  ) {}

  /**
   *
   */
  public function ttl(): int {
    $v = getenv('DRX_LITESTREAM_REMOTE_SIZE_TTL');
    return ($v !== FALSE && (int) $v > 0) ? (int) $v : 900;
  }

  /**
   * Returns a snapshot describing the remote replica size.
   *
   * @return array{
   *   available: bool,
   *   bytes?: int,
   *   objects?: int,
   *   computed_at?: int,
   *   cached?: bool,
   *   ttl: int,
   *   error?: string,
   *   }
   */
  public function getRemoteSize(bool $refresh = FALSE): array {
    if (!$this->status->isEnabled()) {
      return ['available' => FALSE, 'error' => 'litestream disabled', 'ttl' => $this->ttl()];
    }
    $url = $this->status->getReplicaUrl();
    if ($url === NULL) {
      return ['available' => FALSE, 'error' => 'no replica URL', 'ttl' => $this->ttl()];
    }
    $scheme = parse_url($url, PHP_URL_SCHEME);
    if ($scheme !== 's3') {
      return [
        'available' => FALSE,
        'error' => sprintf('sizing not implemented for "%s" replicas', (string) $scheme),
        'ttl' => $this->ttl(),
      ];
    }

    if (!$refresh) {
      $cached = $this->cache->get(self::CACHE_KEY);
      if ($cached && is_array($cached->data)) {
        return ['cached' => TRUE, 'ttl' => $this->ttl()] + $cached->data;
      }
    }

    try {
      $result = $this->computeRemoteSize($url);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('drx_litestream')->warning(
        'Remote size probe failed: @msg',
        ['@msg' => $e->getMessage()],
      );
      return [
        'available' => FALSE,
        'error' => $e->getMessage(),
        'computed_at' => $this->time->getRequestTime(),
        'ttl' => $this->ttl(),
      ];
    }

    $result['available'] = TRUE;
    $result['computed_at'] = $this->time->getRequestTime();
    $result['ttl'] = $this->ttl();
    $this->cache->set(
      self::CACHE_KEY,
      $result,
      $this->time->getRequestTime() + $this->ttl(),
    );
    return $result;
  }

  /**
   * Drop the cached snapshot so the next read recomputes.
   */
  public function invalidate(): void {
    $this->cache->delete(self::CACHE_KEY);
  }

  /**
   * Walk the configured prefix and tally bytes + objects.
   *
   * @return array{bytes:int, objects:int}
   */
  protected function computeRemoteSize(string $replicaUrl): array {
    $parts = parse_url($replicaUrl);
    if (!isset($parts['host'])) {
      throw new \RuntimeException('invalid replica URL');
    }
    $bucket = (string) $parts['host'];
    $prefix = ltrim((string) ($parts['path'] ?? ''), '/');
    if ($prefix !== '' && !str_ends_with($prefix, '/')) {
      $prefix .= '/';
    }

    $endpoint = getenv('DRX_S3_ENDPOINT') ?: '';
    $region = getenv('DRX_S3_REGION') ?: 'us-east-1';
    $key = getenv('DRX_S3_ACCESS_KEY_ID') ?: '';
    $secret = getenv('DRX_S3_SECRET_ACCESS_KEY') ?: '';
    if ($key === '' || $secret === '') {
      throw new \RuntimeException('missing S3 credentials');
    }

    // Path-style is required for custom endpoints (MinIO etc.); virtual-
    // hosted style is the default for AWS S3.
    if ($endpoint !== '') {
      $base = rtrim($endpoint, '/');
      $epParts = parse_url($base);
      if (!is_array($epParts) || empty($epParts['host'])) {
        throw new \RuntimeException('DRX_S3_ENDPOINT must include a valid host');
      }
      $path = (string) ($epParts['path'] ?? '');
      if ($path !== '' && $path !== '/') {
        throw new \RuntimeException('DRX_S3_ENDPOINT must not include a path component');
      }
      if (isset($epParts['query']) || isset($epParts['fragment'])) {
        throw new \RuntimeException('DRX_S3_ENDPOINT must not include query or fragment components');
      }
      $host = ($epParts['host'] ?? '') . (isset($epParts['port']) ? ':' . $epParts['port'] : '');
      $canonicalUri = '/' . $bucket . '/';
    }
    else {
      $base = "https://{$bucket}.s3.{$region}.amazonaws.com";
      $host = "{$bucket}.s3.{$region}.amazonaws.com";
      $canonicalUri = '/';
    }

    $continuation = NULL;
    $bytes = 0;
    $objects = 0;
    $pages = 0;

    do {
      $query = ['list-type' => '2', 'prefix' => $prefix];
      if ($continuation !== NULL) {
        $query['continuation-token'] = $continuation;
      }
      $canonicalQuery = $this->canonicalQuery($query);

      $now = gmdate('Ymd\THis\Z');
      $today = substr($now, 0, 8);
      $payloadHash = hash('sha256', '');

      $headers = [
        'host' => $host,
        'x-amz-content-sha256' => $payloadHash,
        'x-amz-date' => $now,
      ];
      ksort($headers);
      $canonicalHeaders = '';
      $signedList = [];
      foreach ($headers as $h => $v) {
        $canonicalHeaders .= $h . ':' . $v . "\n";
        $signedList[] = $h;
      }
      $signedHeaders = implode(';', $signedList);

      $canonicalRequest = "GET\n{$canonicalUri}\n{$canonicalQuery}\n{$canonicalHeaders}{$signedHeaders}\n{$payloadHash}";
      $scope = "{$today}/{$region}/s3/aws4_request";
      $stringToSign = "AWS4-HMAC-SHA256\n{$now}\n{$scope}\n" . hash('sha256', $canonicalRequest);

      $kDate = hash_hmac('sha256', $today, 'AWS4' . $secret, TRUE);
      $kRegion = hash_hmac('sha256', $region, $kDate, TRUE);
      $kService = hash_hmac('sha256', 's3', $kRegion, TRUE);
      $kSigning = hash_hmac('sha256', 'aws4_request', $kService, TRUE);
      $signature = hash_hmac('sha256', $stringToSign, $kSigning);

      $authorization = "AWS4-HMAC-SHA256 Credential={$key}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";
      $url = $base . $canonicalUri . '?' . $canonicalQuery;

      $response = $this->http->request('GET', $url, [
        'headers' => [
          'Host' => $host,
          'x-amz-content-sha256' => $payloadHash,
          'x-amz-date' => $now,
          'Authorization' => $authorization,
        ],
        'http_errors' => TRUE,
        'timeout' => 10,
      ]);

      $body = (string) $response->getBody();
      $xml = new \SimpleXMLElement($body);
      $ns = $xml->getNamespaces(TRUE);
      $root = !empty($ns['']) ? $xml->children($ns['']) : $xml;
      foreach ($root->Contents as $obj) {
        $bytes += (int) $obj->Size;
        $objects++;
      }
      $next = (string) ($root->NextContinuationToken ?? '');
      $continuation = ($next !== '') ? $next : NULL;

      $pages++;
      if ($pages > 1000) {
        throw new \RuntimeException('pagination limit reached (>1000 pages)');
      }
    } while ($continuation !== NULL);

    return ['bytes' => $bytes, 'objects' => $objects];
  }

  /**
   *
   */
  protected function canonicalQuery(array $query): string {
    ksort($query);
    $parts = [];
    foreach ($query as $k => $v) {
      $parts[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
    }
    return implode('&', $parts);
  }

}
