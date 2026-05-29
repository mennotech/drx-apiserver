<?php

declare(strict_types=1);

namespace Drupal\drx_litestream\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\drx_s3_journal\Service\Journal;

/**
 * Captures an application-consistent point-in-time marker.
 *
 * The flow is:
 *   1. Acquire the Drupal `cron` lock. Core's Cron::run() acquires the
 *      same lock before iterating any hook_cron implementation, so
 *      holding it here is the canonical short-circuit: every cron
 *      invocation (HTTP /cron/{key}, drush cron, automated_cron) will
 *      log "Attempting to re-run cron while it is already running" and
 *      no-op while the snapshot is in flight.
 *   2. Turn on maintenance mode so HTTP traffic stops mutating state.
 *   3. Sleep a small grace period so any in-flight non-admin requests
 *      complete.
 *   4. Insert a provisional marker row recording the source bucket /
 *      prefix layout and a wall-clock `consistent_at` timestamp.
 *   5. `PRAGMA wal_checkpoint(PASSIVE)` to nudge any pending WAL
 *      frames toward the main SQLite file without taking a RESERVED
 *      lock. Litestream is watching the WAL and will pick the frames
 *      up on its next sync (default 1s) — but we don't wait for that.
 *   6. Call `litestream sync -wait` over the daemon's control socket
 *      so the daemon flushes the WAL into a new LTX file *now* and
 *      blocks until that file is durable on S3, then read the LTX
 *      max_txid via `litestream ltx -level all`. That TXID is the
 *      canonical pin for `litestream restore -txid`. We deliberately
 *      do not use the `local_txid` column reported by `litestream
 *      status`: it is a WAL-local counter that resets on checkpoint
 *      and is not accepted by `restore -txid`.
 *   7. Update the marker row with the verified TXID + verify state,
 *      then flush + read again so the marker UPDATE is itself durable
 *      on the replica. The TXID stored on the row is the TXID at
 *      which the row (fully populated) is visible — a self-describing
 *      pin. We then write a JSON snapshot manifest into the S3
 *      bucket so the snapshot can be discovered by listing the
 *      bucket. The manifest carries the same `consistent_at`
 *      timestamp used for file restore, so downstream tooling can
 *      restore versioned public/private objects to the same point in
 *      time. We do not issue a second explicit SQLite checkpoint,
 *      because a foreign-PDO checkpoint takes a RESERVED lock and
 *      stalls every Apache worker rendering the maintenance page.
 *   8. Exit maintenance and release the cron lock.
 *
 * Note: the snapshot contains `system.maintenance_mode=TRUE` in the
 * State table at the moment of capture. The base image clears it
 * automatically after a litestream restore (see base/lib/litestream.sh)
 * before Apache starts, so a sidecar restored from this snapshot comes
 * up serving 200s without operator intervention.
 *
 * Steps 5–7 are wrapped in try/finally; maintenance mode is always
 * cleared and the cron lock is always released even on failure, and
 * SIGINT/SIGTERM handlers run the same cleanup on hard termination.
 *
 * Files (private://, public://) are not Litestream-managed: drupal/s3fs
 * writes them straight to the bucket via the AWS SDK, so once
 * maintenance mode blocks new writes and the grace period elapses the
 * bucket state is itself application-consistent. The `consistent_at`
 * wall-clock is the pin a downstream clone tool uses to select object
 * versions ("for each key, take the latest version with
 * LastModified <= consistent_at").
 */
class SnapshotOrchestrator {

  protected const CRON_LOCK_NAME = 'cron';

  public function __construct(
    protected MarkerManager $markers,
    protected LitestreamStatus $status,
    protected SnapshotManifestWriter $manifestWriter,
    protected StateInterface $state,
    protected LockBackendInterface $lock,
    protected TimeInterface $time,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected ?Journal $journal = NULL,
  ) {}

  /**
   * Capture a consistent marker.
   *
   * @param array{label:string, description?:string, notes?:string} $meta
   *
   * @return array{
   *   id:int,
   *   state:string,
   *   db_txid?:string,
   *   consistent_at?:int,
  *   manifest_key?:string,
   *   error?:string,
   * }
   *
   * @throws \RuntimeException
   *   If litestream is not enabled, the cron lock cannot be acquired,
   *   or the bucket/credentials are missing. Callers should treat a
   *   throw as a hard failure (no marker written, maintenance never
   *   toggled).
   */
  public function createConsistent(array $meta): array {
    if (!$this->status->isEnabled()) {
      throw new \RuntimeException('litestream is not enabled (DRX_LITESTREAM_ENABLED!=1)');
    }
    if (empty($meta['label'])) {
      throw new \RuntimeException('label is required');
    }

    $log = $this->loggerFactory->get('drx_litestream');

    $lockTtl = (float) $this->envInt('DRX_LITESTREAM_SNAPSHOT_LOCK_TTL', 900);
    $drainSecs = $this->envInt('DRX_LITESTREAM_SNAPSHOT_DRAIN_SECS', 3);
    $replicaTimeout = $this->envInt('DRX_LITESTREAM_SNAPSHOT_TIMEOUT', 30);
    $lockWaitSecs = $this->envInt('DRX_LITESTREAM_SNAPSHOT_LOCK_WAIT', 10);

    // 1) Acquire cron lock. If cron is currently running we briefly
    // wait, then try once more. If that still fails the operator can
    // try again shortly; better to fail fast than capture a marker
    // while cron is actively mutating state.
    if (!$this->lock->acquire(self::CRON_LOCK_NAME, $lockTtl)) {
      $this->lock->wait(self::CRON_LOCK_NAME, $lockWaitSecs);
      if (!$this->lock->acquire(self::CRON_LOCK_NAME, $lockTtl)) {
        throw new \RuntimeException(sprintf(
          'could not acquire cron lock within %ds; cron may be running',
          $lockWaitSecs,
        ));
      }
    }

    // Preserve operator-set maintenance state so we don't accidentally
    // bring the site out of maintenance mode they had turned on for an
    // unrelated reason.
    $previousMaintenance = (bool) $this->state->get('system.maintenance_mode', FALSE);

    // Install signal handlers so a Ctrl-C / SIGTERM during the
    // snapshot releases the cron lock and restores maintenance mode
    // instead of leaking a stale `semaphore` row that blocks every
    // subsequent snapshot and every cron run for 15 minutes (the
    // DatabaseLockBackend TTL).
    //
    // Drupal already registers a shutdown function that releases its
    // own locks, and PHP runs shutdown functions on SIGINT — but NOT
    // on SIGTERM. `docker compose exec` without a TTY also tends to
    // deliver SIGTERM to the child when the host side dies. Hooking
    // both signals explicitly closes the gap.
    $cleanupRan = FALSE;
    $cleanup = function (bool $viaSignal = FALSE) use (&$cleanupRan, &$previousMaintenance, $log): void {
      if ($cleanupRan) {
        return;
      }
      $cleanupRan = TRUE;
      try {
        if (!$previousMaintenance) {
          $this->state->set('system.maintenance_mode', FALSE);
        }
      }
      catch (\Throwable) {
        // Best-effort.
      }
      try {
        $this->lock->release(self::CRON_LOCK_NAME);
      }
      catch (\Throwable) {
        // Best-effort.
      }
      if ($viaSignal) {
        try {
          $log->warning('snapshot: interrupted; cron lock released and maintenance restored');
        }
        catch (\Throwable) {
          // Best-effort.
        }
      }
    };
    $signalsInstalled = $this->installSignalHandlers(function () use ($cleanup): void {
      $cleanup(TRUE);
    });

    $log->notice('snapshot: starting consistent capture (label=@label)', [
      '@label' => $meta['label'],
    ]);

    $id = 0;
    try {
      // 2) Maintenance ON.
      $this->state->set('system.maintenance_mode', TRUE);

      // 3) Drain. With maintenance on, the only writers are uid 1 and
      // ourselves. A small fixed wait lets in-flight non-admin requests
      // finish before we declare consistency.
      if ($drainSecs > 0) {
        sleep($drainSecs);
      }

      // 4) Provisional marker insert. Records the entire restore
      // recipe so the exported JSON stands alone.
      $consistentAt = $this->time->getCurrentTime();
      $id = $this->markers->create([
        'label' => $meta['label'],
        'description' => $meta['description'] ?? '',
        'notes' => $meta['notes'] ?? '',
        'replica_url' => $this->status->getReplicaUrl() ?? '',
        'txid' => '',
        'captured_at' => $consistentAt,
        'kind' => 'consistent',
        'consistent_at' => $consistentAt,
        'bucket' => (string) (getenv('DRX_S3_BUCKET') ?: ''),
        's3_endpoint' => (string) (getenv('DRX_S3_ENDPOINT') ?: ''),
        's3_region' => (string) (getenv('DRX_S3_REGION') ?: ''),
        's3_prefix_litestream' => (string) (getenv('DRX_S3_PREFIX_LITESTREAM') ?: ''),
        's3_prefix_private' => (string) (getenv('DRX_S3_PREFIX_PRIVATE') ?: ''),
        's3_prefix_public' => (string) (getenv('DRX_S3_PREFIX_PUBLIC') ?: ''),
        'base_image_ref' => (string) (getenv('DRX_BASE_VERSION') ?: ''),
        'drupal_site_uuid' => (string) $this->configFactory->get('system.site')->get('uuid'),
        'verify_state' => 'pending',
      ]);

      // 5) Nudge the WAL forward without blocking other writers.
      $this->checkpointWal();

      // 6) Ask the litestream daemon to flush pending WAL frames now
      // and block until they are durable on the replica, then read
      // the resulting LTX max_txid. This is the canonical TXID
      // namespace that `litestream restore -txid` understands; the
      // separate `local_txid` column reported by `litestream status`
      // is a WAL-local counter that resets on checkpoint and is NOT
      // accepted by `restore -txid`.
      $preTxid = $this->flushAndReadLtxTxid($replicaTimeout);
      if ($preTxid === NULL) {
        throw new \RuntimeException('could not obtain LTX max_txid after sync');
      }

      // 7) Update the marker with the verified TXID, then flush + read
      // again so the marker UPDATE itself is durable on the replica
      // and the recorded TXID reflects the state at which the row is
      // fully populated (a self-describing pin). We do NOT issue a
      // second explicit SQLite checkpoint here: Litestream's daemon
      // ships the UPDATE's WAL frames in response to the sync RPC, so
      // a foreign-PDO checkpoint that takes a RESERVED lock and
      // starves Apache workers is unnecessary.
      $this->markers->update($id, [
        'txid' => $preTxid,
        'verify_state' => 'verified',
        'verified_at' => $this->time->getCurrentTime(),
      ]);
      $finalTxid = $this->flushAndReadLtxTxid(max(10, (int) ($replicaTimeout / 2))) ?? $preTxid;

      // If the UPDATE advanced the TXID, record the higher value so
      // the restore pin matches the fully-populated marker row.
      if (strcmp(strtolower($finalTxid), strtolower($preTxid)) > 0) {
        $this->markers->update($id, ['txid' => $finalTxid]);
      }

      // Emit a snapshot-boundary record into the S3 journal (when the
      // drx_s3_journal module is enabled) so the manifest records the
      // exact lexicographic upper bound for file events included in
      // this snapshot. Restore replays journal objects up to and
      // including this key, then stops. A failure here is non-fatal:
      // the DB snapshot is still valid, we just log and continue.
      $boundary = NULL;
      if ($this->journal !== NULL) {
        try {
          $marker0 = $this->markers->load($id);
          $boundary = $this->journal->emitSnapshotBoundary(
            (string) ($meta['label'] ?? ''),
            (string) ($marker0['uuid'] ?? ''),
          );
          $this->markers->update($id, [
            'journal_boundary_key' => $boundary['key'],
            'journal_boundary_event_id' => $boundary['event_id'],
            'journal_boundary_at' => $boundary['occurred_at'],
          ]);
        }
        catch (\Throwable $e) {
          $log->warning('snapshot: journal boundary emit failed: @msg', ['@msg' => $e->getMessage()]);
          $boundary = NULL;
        }
      }

      $marker = $this->markers->load($id);
      if (!$marker) {
        throw new \RuntimeException(sprintf('snapshot marker %d could not be reloaded', $id));
      }
      $manifest = $this->manifestWriter->write($marker);
      if (empty($manifest['ok'])) {
        throw new \RuntimeException((string) ($manifest['error'] ?? 'manifest write failed'));
      }

      $log->notice('snapshot: captured marker id=@id label=@label txid=@txid consistent_at=@ts manifest=@manifest', [
        '@id' => $id,
        '@label' => $meta['label'],
        '@txid' => $finalTxid,
        '@ts' => $consistentAt,
        '@manifest' => (string) ($manifest['key'] ?? 'unknown'),
      ]);

      return [
        'id' => $id,
        'state' => 'ok',
        'db_txid' => $finalTxid,
        'consistent_at' => $consistentAt,
        'manifest_key' => (string) ($manifest['key'] ?? ''),
      ];
    }
    catch (\Throwable $e) {
      $log->error('snapshot: failed (id=@id): @msg', [
        '@id' => $id,
        '@msg' => $e->getMessage(),
      ]);
      if ($id > 0) {
        try {
          $this->markers->update($id, [
            'verify_state' => 'failed',
            'verify_error' => substr($e->getMessage(), 0, 512),
          ]);
        }
        catch (\Throwable) {
          // Swallow — we are already in the error path.
        }
      }
      return [
        'id' => $id,
        'state' => 'failed',
        'error' => $e->getMessage(),
      ];
    }
    finally {
      // Restore maintenance to its prior value (don't clobber an
      // operator-set maintenance window) and release the cron lock.
      // The signal handler also runs this exact cleanup; the
      // $cleanupRan guard inside makes the second call a no-op.
      $cleanup();
      $log->notice('snapshot: released cron lock; maintenance mode @state', [
        '@state' => $previousMaintenance ? 'left ON (was already on)' : 'OFF',
      ]);
      if ($signalsInstalled) {
        $this->restoreSignalHandlers();
      }
    }
  }

  /**
   * Nudge pending WAL frames into the main SQLite file.
   *
   * Uses PASSIVE so we never block readers/writers. TRUNCATE / RESTART
   * acquire a RESERVED lock that serializes every Apache worker trying
   * to render even the maintenance page, which produced a full site
   * stall in early testing. A PASSIVE checkpoint just flushes what it
   * can right now; Litestream's own checkpoint cycle handles the rest
   * on its normal cadence (default 1s sync interval).
   *
   * We also set a generous busy_timeout so this connection waits for
   * the SQLite write lock instead of immediately erroring out and
   * leaving the snapshot in an inconsistent half-state.
   */
  protected function checkpointWal(): void {
    $dbPath = $this->status->getDatabasePath();
    if (!is_readable($dbPath)) {
      throw new \RuntimeException(sprintf('SQLite DB not readable at %s', $dbPath));
    }
    try {
      $pdo = new \PDO('sqlite:' . $dbPath);
      $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
      // 5s busy_timeout matches Drupal's SQLite driver default.
      $pdo->exec('PRAGMA busy_timeout=5000;');
      $pdo->query('PRAGMA wal_checkpoint(PASSIVE);');
    }
    catch (\PDOException $e) {
      throw new \RuntimeException('wal_checkpoint failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * Flush pending WAL frames to the replica and return the LTX max_txid.
   *
   * Preferred path: ask the litestream daemon to `sync -wait` via the
   * control socket, which blocks until all dirty frames are durable on
   * S3. After it returns we read `litestream ltx -level all` and pick
   * the largest max_txid across all levels — that is the canonical
   * TXID for `litestream restore -txid`.
   *
   * Fallback path (control socket disabled or RPC failed): poll the
   * replica state for up to $timeoutSecs, advancing only when the
   * daemon's own sync-interval ships the frames naturally. Less
   * deterministic, but avoids hard-failing the snapshot if an operator
   * has explicitly disabled DRX_LITESTREAM_CONTROL_SOCKET.
   *
   * Returns NULL only if the replica never reports any LTX file.
   */
  protected function flushAndReadLtxTxid(int $timeoutSecs): ?string {
    $log = $this->loggerFactory->get('drx_litestream');
    $sync = $this->status->forceReplicaSync($timeoutSecs);
    if (!$sync['ok']) {
      $log->warning('snapshot: forceReplicaSync failed (@msg); falling back to poll', [
        '@msg' => trim($sync['output']) !== '' ? $sync['output'] : 'no output',
      ]);
    }

    // Even with sync -wait we briefly retry the ltx read: the daemon
    // signals "durable" the moment the LTX upload completes, and there
    // is a small window where `litestream ltx` (which lists files via
    // the replica client) may still be reading directory state.
    $deadline = time() + max(1, $timeoutSecs);
    $last = NULL;
    do {
      $r = $this->status->getReplicaLatestTxid();
      if ($r !== NULL && trim($r) !== '') {
        return strtolower($r);
      }
      $last = $r;
      usleep(500000);
    } while (time() < $deadline);

    if ($last !== NULL) {
      return strtolower($last);
    }
    return NULL;
  }

  protected function envInt(string $key, int $default): int {
    $v = getenv($key);
    if ($v === FALSE || $v === '') {
      return $default;
    }
    $n = (int) $v;
    return $n > 0 ? $n : $default;
  }

  /**
   * Install SIGINT/SIGTERM handlers that run $cleanup and then exit.
   *
   * Requires the `pcntl` extension (always present in the base image's
   * PHP CLI). If pcntl is unavailable we simply skip — the worst case
   * is the legacy behaviour of leaking a `cron` semaphore row on hard
   * termination, which the 15-minute TTL eventually clears.
   *
   * Returns TRUE if handlers were installed (caller should restore
   * them in `finally`).
   */
  protected function installSignalHandlers(callable $cleanup): bool {
    if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
      return FALSE;
    }
    pcntl_async_signals(TRUE);
    $handler = function (int $sig) use ($cleanup): void {
      $cleanup();
      // Use the conventional 128+signo exit code so callers can
      // distinguish signal termination from internal failure.
      exit(128 + $sig);
    };
    pcntl_signal(SIGINT, $handler);
    pcntl_signal(SIGTERM, $handler);
    return TRUE;
  }

  /**
   * Reset signal handlers to PHP defaults after the snapshot completes
   * normally, so a later SIGINT/SIGTERM (e.g. a long-running drush
   * session after this command finishes) is not still routed to the
   * snapshot cleanup closure.
   */
  protected function restoreSignalHandlers(): void {
    if (!function_exists('pcntl_signal')) {
      return;
    }
    pcntl_signal(SIGINT, SIG_DFL);
    pcntl_signal(SIGTERM, SIG_DFL);
  }

}
