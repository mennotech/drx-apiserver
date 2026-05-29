<?php

declare(strict_types=1);

namespace Drupal\drx_s3_journal\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\file\FileInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds and emits one journal record per Drupal file change for the
 * `public://` and `private://` streams.
 *
 * Strict-audit semantics: write failures bubble up as exceptions, so
 * the surrounding Drupal request fails and no content mutation is
 * confirmed without a durable journal record on S3.
 */
class Journal {

  /**
   * Stream wrapper schemes we track. Anything else is ignored.
   */
  protected const TRACKED_STREAMS = ['public', 'private'];

  /**
   * Per-request id, so multiple events from the same HTTP request can
   * be correlated at replay time.
   */
  protected ?string $requestId = NULL;

  public function __construct(
    protected JournalWriter $writer,
    protected Connection $database,
    protected AccountInterface $currentUser,
    protected RequestStack $requestStack,
    protected RouteMatchInterface $routeMatch,
    protected UuidInterface $uuidService,
    protected TimeInterface $time,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Record a file event. No-op when the journal is not configured or
   * the file lives outside a tracked stream.
   */
  public function record(string $op, FileInterface $file): void {
    if (!$this->writer->isEnabled()) {
      return;
    }
    $uri = (string) $file->getFileUri();
    $scope = $this->scopeFromUri($uri);
    if ($scope === NULL) {
      return;
    }

    $eventId = $this->uuidService->generate();
    $nowUs = $this->nowMicroIso();
    $key = $this->buildKey($nowUs, $eventId, $op, $scope, (int) $file->id());
    $payload = $this->buildPayload($eventId, $nowUs, $op, $scope, $file);

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === FALSE) {
      throw new \RuntimeException('drx_s3_journal: failed to encode event payload');
    }

    // Strict-fail: any exception from put() propagates and aborts the
    // surrounding write so we never confirm a content mutation that
    // we could not durably journal.
    $this->writer->put($key, $json);
  }

  /**
   * Emit a synthetic snapshot-boundary journal record.
   *
   * Written by the litestream snapshot orchestrator while the site is
   * quiesced. The returned key is the lexicographic upper bound for
   * file events included in the snapshot: a restore replays every
   * journal object up to and including this key, then stops.
   *
   * @return array{key:string,event_id:string,occurred_at_utc:string,occurred_at:int}
   */
  public function emitSnapshotBoundary(string $snapshotLabel, string $snapshotUuid = ''): array {
    $eventId = $this->uuidService->generate();
    $nowUs = $this->nowMicroIso();
    $payload = [
      'schema' => 'drx-s3-journal/v1',
      'event_id' => $eventId,
      'occurred_at_utc' => $nowUs,
      'op' => 'snapshot',
      'stream' => 'meta',
      'fid' => 0,
      'snapshot' => [
        'label' => $snapshotLabel,
        'uuid' => $snapshotUuid,
      ],
      'request_id' => $this->getRequestId(),
      'context' => $this->buildContext(),
    ];
    $key = $this->buildKey($nowUs, $eventId, 'snapshot', 'meta', 0);
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === FALSE) {
      throw new \RuntimeException('drx_s3_journal: failed to encode snapshot-boundary payload');
    }
    $this->writer->put($key, $json);
    $occurredAt = (new \DateTimeImmutable($nowUs))->getTimestamp();
    return [
      'key' => $key,
      'event_id' => $eventId,
      'occurred_at_utc' => $nowUs,
      'occurred_at' => $occurredAt,
    ];
  }

  /**
   * Public for the Drush "test" helper.
   *
   * @return array{key:string,payload:array<string,mixed>}
   */
  public function emitTestEvent(): array {
    $eventId = $this->uuidService->generate();
    $nowUs = $this->nowMicroIso();
    $payload = [
      'schema' => 'drx-s3-journal/v1',
      'event_id' => $eventId,
      'occurred_at_utc' => $nowUs,
      'op' => 'test',
      'stream' => 'public',
      'fid' => 0,
      'uri' => 'public://__drx-s3-journal-test__',
      'request_id' => $this->getRequestId(),
      'context' => $this->buildContext(),
    ];
    $key = $this->buildKey($nowUs, $eventId, 'test', 'public', 0);
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === FALSE) {
      throw new \RuntimeException('drx_s3_journal: failed to encode test payload');
    }
    $this->writer->put($key, $json);
    return ['key' => $key, 'payload' => $payload];
  }

  /**
   * Return the lexicographic prefix that contains all events at-or-after
   * the given unix timestamp, suitable for resuming replay.
   *
   * The hourly prefix is the coarsest stable prefix; the caller is
   * expected to drop records whose `occurred_at_utc` is earlier than
   * the requested start within that hour.
   */
  public function replayPrefix(int $unixTimestamp): string {
    return $this->journalPrefix() . '/' . gmdate('Y/m/d/H', $unixTimestamp) . '/';
  }

  /**
   * @return array<string,mixed>
   */
  protected function buildPayload(
    string $eventId,
    string $occurredAt,
    string $op,
    string $scope,
    FileInterface $file,
  ): array {
    return [
      'schema' => 'drx-s3-journal/v1',
      'event_id' => $eventId,
      'occurred_at_utc' => $occurredAt,
      'op' => $op,
      'stream' => $scope,
      'fid' => (int) $file->id(),
      'uuid' => (string) $file->uuid(),
      'uri' => (string) $file->getFileUri(),
      'filename' => (string) $file->getFilename(),
      'mime' => (string) $file->getMimeType(),
      'size' => (int) $file->getSize(),
      'status' => (int) $file->get('status')->value,
      'owner_uid' => (int) $file->getOwnerId(),
      'request_id' => $this->getRequestId(),
      'context' => $this->buildContext(),
      'usage' => $this->collectUsage((int) $file->id()),
    ];
  }

  /**
   * @return array<string,mixed>
   */
  protected function buildContext(): array {
    $request = $this->requestStack->getCurrentRequest();
    return [
      'uid' => (int) $this->currentUser->id(),
      'username' => (string) $this->currentUser->getAccountName(),
      'route' => $this->routeMatch->getRouteName() ?? '',
      'ip' => $request ? (string) $request->getClientIp() : '',
      'user_agent' => $request ? (string) ($request->headers->get('User-Agent') ?? '') : '',
      'method' => $request ? $request->getMethod() : '',
    ];
  }

  /**
   * Snapshot of {file_usage} for this fid at the moment of the event.
   *
   * @return list<array{module:string,type:string,id:string,count:int}>
   */
  protected function collectUsage(int $fid): array {
    if ($fid <= 0 || !$this->database->schema()->tableExists('file_usage')) {
      return [];
    }
    $rows = $this->database->select('file_usage', 'fu')
      ->fields('fu', ['module', 'type', 'id', 'count'])
      ->condition('fid', $fid)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $row) {
      $out[] = [
        'module' => (string) $row['module'],
        'type' => (string) $row['type'],
        'id' => (string) $row['id'],
        'count' => (int) $row['count'],
      ];
    }
    return $out;
  }

  protected function scopeFromUri(string $uri): ?string {
    $pos = strpos($uri, '://');
    if ($pos === FALSE) {
      return NULL;
    }
    $scheme = substr($uri, 0, $pos);
    return in_array($scheme, self::TRACKED_STREAMS, TRUE) ? $scheme : NULL;
  }

  /**
   * Build the S3 object key.
   *
  * Layout: <DRX_S3_PREFIX_JOURNAL>/YYYY/MM/DD/HH/<TS>_<eventId>_<op>_<scope>_<fid>.json
   *
   * The hourly partition is the lexicographic anchor for "replay from
   * point in time": listing the bucket from this prefix forward yields
   * events in chronological order.
   */
  protected function buildKey(
    string $occurredAt,
    string $eventId,
    string $op,
    string $scope,
    int $fid,
  ): string {
    $ts = (new \DateTimeImmutable($occurredAt))->setTimezone(new \DateTimeZone('UTC'));
    $partition = $ts->format('Y/m/d/H');
    $stamp = $ts->format('Ymd\THis.u\Z');
    return sprintf(
      '%s/%s/%s_%s_%s_%s_%d.json',
      $this->journalPrefix(),
      $partition,
      $stamp,
      $eventId,
      $this->sanitize($op),
      $this->sanitize($scope),
      $fid,
    );
  }

  protected function journalPrefix(): string {
    $prefix = (string) (getenv('DRX_S3_PREFIX_JOURNAL') ?: 'journal/v1');
    $prefix = trim($prefix, '/');
    return $prefix !== '' ? $prefix : 'journal/v1';
  }

  protected function sanitize(string $value): string {
    $value = preg_replace('/[^A-Za-z0-9._-]+/', '-', $value) ?? '';
    return trim($value, '-._') ?: 'na';
  }

  protected function nowMicroIso(): string {
    // Microsecond precision UTC ISO-8601, e.g. 2026-05-28T14:35:01.123456Z.
    $now = \DateTimeImmutable::createFromFormat(
      'U.u',
      sprintf('%.6F', microtime(TRUE)),
    );
    if ($now === FALSE) {
      $now = new \DateTimeImmutable('@' . $this->time->getCurrentTime());
    }
    return $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
  }

  protected function getRequestId(): string {
    if ($this->requestId === NULL) {
      $this->requestId = $this->uuidService->generate();
    }
    return $this->requestId;
  }

}
