<?php

declare(strict_types=1);

namespace Drupal\drx_litestream\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;

/**
 * Writes a browsable snapshot manifest into the S3 replica bucket.
 *
 * The database marker remains the authoritative restore record, but the
 * manifest makes snapshots discoverable by simply listing the bucket's
 * `snapshots/` prefix. The manifest carries the same `consistent_at`
 * timestamp used for file restore so downstream tooling can restore
 * versioned public/private objects to the exact same point in time.
 */
class SnapshotManifestWriter {

  public function __construct(
    protected LitestreamStatus $status,
    protected ClientInterface $http,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected TimeInterface $time,
  ) {}

  /**
   * Write a JSON manifest for a captured snapshot.
   *
   * @param array<string, mixed> $marker
   *   Marker row as returned by MarkerManager::load().
   *
   * @return array{ok:bool,key?:string,error?:string}
   */
  public function write(array $marker): array {
    if (!$this->status->isEnabled()) {
      return ['ok' => FALSE, 'error' => 'litestream disabled'];
    }

    $bucket = trim((string) ($marker['bucket'] ?? ''));
    if ($bucket === '') {
      return ['ok' => FALSE, 'error' => 'snapshot bucket is missing'];
    }

    $payload = $this->buildPayload($marker);
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === FALSE) {
      return ['ok' => FALSE, 'error' => 'failed to encode snapshot manifest'];
    }

    $key = $this->buildObjectKey($marker);
    try {
      $this->putObject($bucket, $key, $json, 'application/json');
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('drx_litestream')->warning(
        'Snapshot manifest write failed: @msg',
        ['@msg' => $e->getMessage()],
      );
      return ['ok' => FALSE, 'error' => $e->getMessage()];
    }

    return ['ok' => TRUE, 'key' => $key];
  }

  /**
   * Build the human-browsable manifest payload.
   *
   * @param array<string, mixed> $marker
   * @return array<string, mixed>
   */
  protected function buildPayload(array $marker): array {
    $consistentAt = (int) ($marker['consistent_at'] ?? 0);
    $capturedAt = (int) ($marker['captured_at'] ?? 0);
    $txid = strtolower((string) ($marker['txid'] ?? ''));

    return [
      'schema' => 'drx-litestream-snapshot/v1',
      'generated_at' => $this->formatIso($this->time->getRequestTime()),
      'marker' => [
        'id' => (int) ($marker['id'] ?? 0),
        'uuid' => (string) ($marker['uuid'] ?? ''),
        'label' => (string) ($marker['label'] ?? ''),
        'kind' => (string) ($marker['kind'] ?? 'consistent'),
        'description' => (string) ($marker['description'] ?? ''),
        'notes' => (string) ($marker['notes'] ?? ''),
        'captured_at' => $capturedAt,
        'captured_at_iso' => $capturedAt > 0 ? $this->formatIso($capturedAt) : NULL,
        'consistent_at' => $consistentAt,
        'consistent_at_iso' => $consistentAt > 0 ? $this->formatIso($consistentAt) : NULL,
        'txid' => $txid,
        'replica_url' => (string) ($marker['replica_url'] ?? ''),
      ],
      'restore' => [
        'db_txid' => $txid,
        'files_last_modified_cutoff' => $consistentAt,
        'files_last_modified_cutoff_iso' => $consistentAt > 0 ? $this->formatIso($consistentAt) : NULL,
        'bucket' => (string) ($marker['bucket'] ?? ''),
        's3_endpoint' => (string) ($marker['s3_endpoint'] ?? ''),
        's3_region' => (string) ($marker['s3_region'] ?? ''),
        'prefixes' => [
          'litestream' => (string) ($marker['s3_prefix_litestream'] ?? ''),
          'private' => (string) ($marker['s3_prefix_private'] ?? ''),
          'public' => (string) ($marker['s3_prefix_public'] ?? ''),
        ],
        'journal_boundary' => $this->buildJournalBoundary($marker),
        'base_image_ref' => (string) ($marker['base_image_ref'] ?? ''),
        'drupal_site_uuid' => (string) ($marker['drupal_site_uuid'] ?? ''),
      ],
      'verify' => [
        'state' => (string) ($marker['verify_state'] ?? ''),
        'error' => (string) ($marker['verify_error'] ?? ''),
        'verified_at' => (int) ($marker['verified_at'] ?? 0),
        'verified_at_iso' => !empty($marker['verified_at'])
          ? $this->formatIso((int) $marker['verified_at'])
          : NULL,
      ],
      'dev_restore_hint' => [
        'db' => sprintf(
          'litestream restore -txid %s -o ./dev.sqlite %s',
          escapeshellarg($txid),
          escapeshellarg((string) ($marker['replica_url'] ?? '')),
        ),
        'files' => 'Clone the source bucket prefixes up to restore.files_last_modified_cutoff using S3 object versions, then point DRX_S3_BUCKET at the cloned bucket.',
      ],
    ];
  }

  /**
   * Describe the S3 journal boundary written during snapshot capture.
   *
   * Restore replays every object under `journal/v1/` whose key is
   * lexicographically <= `key`, then stops; the boundary record
   * itself is a synthetic `snapshot` op and is not replayed.
   *
   * @param array<string, mixed> $marker
   * @return array<string, mixed>|null
   */
  protected function buildJournalBoundary(array $marker): ?array {
    $key = (string) ($marker['journal_boundary_key'] ?? '');
    if ($key === '') {
      return NULL;
    }
    $at = (int) ($marker['journal_boundary_at'] ?? 0);
    return [
      'key' => $key,
      'event_id' => (string) ($marker['journal_boundary_event_id'] ?? ''),
      'occurred_at' => $at,
      'occurred_at_iso' => $at > 0 ? $this->formatIso($at) : NULL,
    ];
  }

  /**
   * Build the S3 object key for this manifest.
   */
  protected function buildObjectKey(array $marker): string {
    $snapshotPrefix = 'snapshots';
    $capturedAt = (int) ($marker['captured_at'] ?? 0);
    $timestamp = $capturedAt > 0 ? gmdate('Ymd\THis\Z', $capturedAt) : gmdate('Ymd\THis\Z');
    $label = $this->sanitizePathPart((string) ($marker['label'] ?? 'snapshot'));
    $txid = $this->sanitizePathPart((string) ($marker['txid'] ?? 'unknown-txid'));
    $id = (int) ($marker['id'] ?? 0);
    return sprintf('%s/%s-%s-%d-%s.json', $snapshotPrefix, $timestamp, $label, $id, $txid);
  }

  protected function sanitizePathPart(string $value): string {
    $value = preg_replace('/[^A-Za-z0-9._-]+/', '-', $value) ?? '';
    $value = trim($value, '-._');
    return $value !== '' ? $value : 'snapshot';
  }

  protected function formatIso(int $timestamp): string {
    return (new \DateTimeImmutable('@' . $timestamp))
      ->setTimezone(new \DateTimeZone('UTC'))
      ->format('c');
  }

  protected function putObject(string $bucket, string $key, string $body, string $contentType): void {
    [$base, $host, $canonicalUri] = $this->buildEndpoint($bucket, $key);

    $region = getenv('DRX_S3_REGION') ?: 'us-east-1';
    $keyId = getenv('DRX_S3_ACCESS_KEY_ID') ?: '';
    $secret = getenv('DRX_S3_SECRET_ACCESS_KEY') ?: '';
    if ($keyId === '' || $secret === '') {
      throw new \RuntimeException('missing S3 credentials');
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

    $authorization = "AWS4-HMAC-SHA256 Credential={$keyId}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";
    $url = $base . $canonicalUri;
    $this->http->request('PUT', $url, [
      'headers' => [
        'Host' => $host,
        'Content-Type' => $contentType,
        'x-amz-content-sha256' => $payloadHash,
        'x-amz-date' => $now,
        'Authorization' => $authorization,
      ],
      'body' => $body,
      'http_errors' => TRUE,
      'timeout' => 10,
    ]);
  }

  /**
   * Build the endpoint URL and canonical URI for an S3 object key.
   *
   * @return array{0:string,1:string,2:string}
   */
  protected function buildEndpoint(string $bucket, string $key): array {
    $endpoint = getenv('DRX_S3_ENDPOINT') ?: '';
    $region = getenv('DRX_S3_REGION') ?: 'us-east-1';
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