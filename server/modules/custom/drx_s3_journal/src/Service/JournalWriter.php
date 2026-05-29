<?php

declare(strict_types=1);

namespace Drupal\drx_s3_journal\Service;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;

/**
 * Writes a single immutable JSON object to the configured S3 bucket
 * under the `journal/` prefix using AWS SigV4.
 *
 * Uses direct HTTP calls instead of Drupal stream wrappers on purpose:
 * the journal must not flow through file entity or s3fs code paths,
 * otherwise we would loop (journal write triggers file event triggers
 * journal write...). Reuses the SigV4 pattern from
 * drx_litestream's SnapshotManifestWriter.
 */
class JournalWriter {

  public function __construct(
    protected ClientInterface $http,
  ) {}

  /**
   * Is the journal pipeline configured?
   *
   * Returns FALSE when running with DRX_S3_REQUIRED=0 (CI/smoke), in
   * which case the caller is expected to no-op silently.
   */
  public function isEnabled(): bool {
    if ((string) getenv('DRX_S3_REQUIRED') === '0') {
      return FALSE;
    }
    return (string) getenv('DRX_S3_BUCKET') !== '';
  }

  /**
   * Synchronously write one journal object. Throws on failure so the
   * caller's transaction fails too (strict audit).
   */
  public function put(string $key, string $body, string $contentType = 'application/json'): void {
    $bucket = (string) getenv('DRX_S3_BUCKET');
    if ($bucket === '') {
      throw new \RuntimeException('DRX_S3_BUCKET is not set; cannot write journal');
    }

    [$base, $host, $canonicalUri] = $this->buildEndpoint($bucket, $key);

    $region = (string) (getenv('DRX_S3_REGION') ?: 'us-east-1');
    $keyId = (string) (getenv('DRX_S3_ACCESS_KEY_ID') ?: '');
    $secret = (string) (getenv('DRX_S3_SECRET_ACCESS_KEY') ?: '');
    if ($keyId === '' || $secret === '') {
      throw new \RuntimeException('missing S3 credentials for journal write');
    }

    $now = gmdate('Ymd\THis\Z');
    $today = substr($now, 0, 8);
    $payloadHash = hash('sha256', $body);

    $headers = [
      'content-type' => $contentType,
      'host' => $host,
      'x-amz-content-sha256' => $payloadHash,
      'x-amz-date' => $now,
    ];
    ksort($headers);

    $canonicalHeaders = '';
    $signedList = [];
    foreach ($headers as $name => $value) {
      $canonicalHeaders .= $name . ':' . $value . "\n";
      $signedList[] = $name;
    }
    $signedHeaders = implode(';', $signedList);

    $canonicalRequest = "PUT\n{$canonicalUri}\n\n{$canonicalHeaders}{$signedHeaders}\n{$payloadHash}";
    $scope = "{$today}/{$region}/s3/aws4_request";
    $stringToSign = "AWS4-HMAC-SHA256\n{$now}\n{$scope}\n" . hash('sha256', $canonicalRequest);

    $kDate = hash_hmac('sha256', $today, 'AWS4' . $secret, TRUE);
    $kRegion = hash_hmac('sha256', $region, $kDate, TRUE);
    $kService = hash_hmac('sha256', 's3', $kRegion, TRUE);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, TRUE);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $authorization = "AWS4-HMAC-SHA256 Credential={$keyId}/{$scope}, "
      . "SignedHeaders={$signedHeaders}, Signature={$signature}";

    try {
      $this->http->request('PUT', $base . $canonicalUri, [
        'headers' => [
          'Host' => $host,
          'Content-Type' => $contentType,
          'x-amz-content-sha256' => $payloadHash,
          'x-amz-date' => $now,
          'Authorization' => $authorization,
          // If-None-Match: * ensures we never overwrite an existing
          // journal object. If a retry hits the same key and S3
          // returns 412, treat it as an idempotent success.
          'If-None-Match' => '*',
        ],
        'body' => $body,
        'http_errors' => TRUE,
        'timeout' => 10,
      ]);
    }
    catch (ClientException $e) {
      if ($e->getResponse()?->getStatusCode() === 412) {
        return;
      }
      throw $e;
    }
  }

  /**
   * @return array{0:string,1:string,2:string}
   */
  protected function buildEndpoint(string $bucket, string $key): array {
    $endpoint = (string) getenv('DRX_S3_ENDPOINT');
    $region = (string) (getenv('DRX_S3_REGION') ?: 'us-east-1');
    $encodedKey = implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));

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
      $canonicalUri = '/' . rawurlencode($bucket) . '/' . $encodedKey;
      return [$base, $host, $canonicalUri];
    }

    $base = "https://{$bucket}.s3.{$region}.amazonaws.com";
    $host = "{$bucket}.s3.{$region}.amazonaws.com";
    $canonicalUri = '/' . $encodedKey;
    return [$base, $host, $canonicalUri];
  }

}
