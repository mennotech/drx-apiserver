<?php

declare(strict_types=1);

namespace Drupal\drx_litestream\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\drx_litestream\Service\LitestreamStatus;
use Drupal\drx_litestream\Service\MarkerManager;
use Drupal\drx_litestream\Service\SnapshotOrchestrator;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for drx_litestream.
 */
class DrxLitestreamCommands extends DrushCommands {

  public function __construct(
    protected SnapshotOrchestrator $orchestrator,
    protected LitestreamStatus $status,
    protected MarkerManager $markers,
  ) {
    parent::__construct();
  }

  /**
   * Creates command handlers from Drupal's service container.
   *
   * Drush 12 instantiates command classes via static create(), which
   * avoids the legacy `drush.services.yml` parser; it does not
   * understand Symfony's `@service` argument references.
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('drx_litestream.snapshot_orchestrator'),
      $container->get('drx_litestream.status'),
      $container->get('drx_litestream.markers'),
    );
  }

  /**
   * Capture an application-consistent point-in-time snapshot marker.
   *
   * Puts the site into maintenance mode, holds the cron lock, force-
   * checkpoints the SQLite WAL, waits for the Litestream replica to
   * catch up, then writes a marker row recording the resulting TXID
   * plus the S3 bucket / prefix layout. Exit code is non-zero on
   * failure.
   */
  #[CLI\Command(name: 'drx:litestream:snapshot', aliases: ['drx-lit-snap'])]
  #[CLI\Option(name: 'label', description: 'Short human-readable name for the snapshot.')]
  #[CLI\Option(name: 'description', description: 'Optional longer description.')]
  #[CLI\Option(name: 'notes', description: 'Optional restore notes.')]
  #[CLI\Usage(name: 'drush drx:litestream:snapshot --label=pre-upgrade-2026-05-28', description: 'Capture a snapshot before a risky deploy.')]
  public function snapshot(
    array $options = [
      'label' => self::REQ,
      'description' => '',
      'notes' => '',
    ],
  ): int {
    $label = trim((string) ($options['label'] ?? ''));
    if ($label === '') {
      $this->logger()->error('--label is required');
      return self::EXIT_FAILURE;
    }

    $result = $this->orchestrator->createConsistent([
      'label' => $label,
      'description' => (string) ($options['description'] ?? ''),
      'notes' => (string) ($options['notes'] ?? ''),
    ]);

    if ($result['state'] === 'ok') {
      $this->logger()->success(sprintf(
        'Captured marker id=%d label=%s txid=%s consistent_at=%d manifest=%s',
        $result['id'],
        $label,
        $result['db_txid'] ?? '?',
        $result['consistent_at'] ?? 0,
        $result['manifest_key'] ?? '?',
      ));
      $this->output()->writeln((string) ($result['db_txid'] ?? ''));
      return self::EXIT_SUCCESS;
    }

    $this->logger()->error(sprintf(
      'Snapshot failed (id=%d): %s',
      $result['id'] ?? 0,
      $result['error'] ?? 'unknown error',
    ));
    return self::EXIT_FAILURE;
  }

  /**
   * Show consolidated litestream replication health.
   *
   * Output mirrors the structure surfaced by the admin dashboard so
   * dev/CI can read the same verdict from the terminal.
   */
  #[CLI\Command(name: 'drx:litestream:status', aliases: ['drx-lit-status'])]
  #[CLI\FieldLabels(labels: [
    'state' => 'State',
    'enabled' => 'Enabled',
    'running' => 'Daemon running',
    'local_status' => 'Local status',
    'local_txid' => 'Local TXID',
    'replica_txid' => 'Replica TXID',
    'wal_size' => 'WAL size',
    'replica_url' => 'Replica URL',
    'issues' => 'Issues',
  ])]
  public function status(array $options = ['format' => 'table']): PropertyList {
    $snap = $this->status->getHealthSnapshot();
    return new PropertyList([
      'state' => (string) $snap['state'],
      'enabled' => $snap['enabled'] ? 'yes' : 'no',
      'running' => $snap['running'] ? 'yes' : 'no',
      'local_status' => (string) $snap['local_status'],
      'local_txid' => (string) $snap['local_txid'],
      'replica_txid' => $snap['replica_txid'] ?? '—',
      'wal_size' => (string) $snap['wal_size'],
      'replica_url' => (string) $snap['replica_url'],
      'issues' => implode('; ', $snap['issues']),
    ]);
  }

  /**
   * Show the active litestream runtime contract (paths, env, retention).
   */
  #[CLI\Command(name: 'drx:litestream:info', aliases: ['drx-lit-info'])]
  #[CLI\FieldLabels(labels: [
    'enabled' => 'Enabled',
    'binary' => 'Binary',
    'config_path' => 'Config file',
    'database_path' => 'SQLite database',
    'replica_url' => 'Replica URL',
    'control_socket' => 'Control socket',
    'restore_on_boot' => 'Restore on boot',
    'restore_txid' => 'Restore TXID pin',
    'restore_timestamp' => 'Restore timestamp pin',
    'sync_interval' => 'Sync interval',
    'retention_mode' => 'Retention mode',
    'retention_description' => 'Retention description',
    'snapshot_interval' => 'Snapshot interval',
    'snapshot_retention' => 'Snapshot retention',
  ])]
  public function info(array $options = ['format' => 'table']): PropertyList {
    $retention = $this->status->getRetentionInfo();
    return new PropertyList([
      'enabled' => $this->status->isEnabled() ? 'yes' : 'no',
      'binary' => $this->status->getBinary(),
      'config_path' => $this->status->getConfigPath(),
      'database_path' => $this->status->getDatabasePath(),
      'replica_url' => $this->status->getReplicaUrl() ?? '—',
      'control_socket' => $this->status->getControlSocketPath() ?? '(disabled)',
      'restore_on_boot' => (string) (getenv('DRX_LITESTREAM_RESTORE_ON_BOOT') ?: 'if-empty'),
      'restore_txid' => (string) (getenv('DRX_LITESTREAM_RESTORE_TXID') ?: '—'),
      'restore_timestamp' => (string) (getenv('DRX_LITESTREAM_RESTORE_TIMESTAMP') ?: '—'),
      'sync_interval' => (string) (getenv('DRX_LITESTREAM_SYNC_INTERVAL') ?: '1s'),
      'retention_mode' => (string) ($retention['mode'] ?? 'unknown'),
      'retention_description' => (string) ($retention['description'] ?? ''),
      'snapshot_interval' => (string) ($retention['snapshot_interval'] ?? '—'),
      'snapshot_retention' => (string) ($retention['snapshot_retention'] ?? '—'),
    ]);
  }

  /**
   * Force the litestream daemon to flush pending WAL frames now.
   *
   * Useful for diagnostics and for tests that need a bounded wait
   * before reading from the replica. Exit code is non-zero on sync
   * failure.
   */
  #[CLI\Command(name: 'drx:litestream:sync', aliases: ['drx-lit-sync'])]
  #[CLI\Option(name: 'timeout', description: 'Seconds to wait for the daemon to flush (default 30).')]
  public function sync(array $options = ['timeout' => 30]): int {
    if (!$this->status->isEnabled()) {
      $this->logger()->error('litestream is not enabled');
      return self::EXIT_FAILURE;
    }
    $timeout = max(1, (int) ($options['timeout'] ?? 30));
    $result = $this->status->forceReplicaSync($timeout);
    $out = trim((string) ($result['output'] ?? ''));
    if ($out !== '') {
      $this->output()->writeln($out);
    }
    if (!empty($result['ok'])) {
      $this->logger()->success('replica sync ok');
      return self::EXIT_SUCCESS;
    }
    $this->logger()->error('replica sync failed');
    return self::EXIT_FAILURE;
  }

  /**
   * List recent snapshot markers.
   */
  #[CLI\Command(name: 'drx:litestream:markers', aliases: ['drx-lit-markers'])]
  #[CLI\Option(name: 'limit', description: 'Maximum rows to return (default 20).')]
  #[CLI\Option(name: 'verify-state', description: 'Filter by verify_state (e.g. verified, failed, pending).')]
  #[CLI\Option(name: 'kind', description: 'Filter by kind (e.g. live, consistent).')]
  #[CLI\FieldLabels(labels: [
    'id' => 'ID',
    'label' => 'Label',
    'kind' => 'Kind',
    'verify_state' => 'Verify',
    'txid' => 'TXID',
    'consistent_at' => 'Consistent at (UTC)',
    'captured_at' => 'Captured at (UTC)',
    'manifest_key' => 'Manifest key',
  ])]
  #[CLI\DefaultTableFields(fields: ['id', 'label', 'kind', 'verify_state', 'txid', 'consistent_at'])]
  public function markers(
    array $options = [
      'limit' => 20,
      'verify-state' => self::OPT,
      'kind' => self::OPT,
      'format' => 'table',
    ],
  ): RowsOfFields {
    $limit = max(1, (int) ($options['limit'] ?? 20));
    $filterVerify = trim((string) ($options['verify-state'] ?? ''));
    $filterKind = trim((string) ($options['kind'] ?? ''));

    $rows = [];
    $count = 0;
    foreach ($this->markers->loadAll() as $row) {
      if ($filterVerify !== '' && (string) ($row['verify_state'] ?? '') !== $filterVerify) {
        continue;
      }
      if ($filterKind !== '' && (string) ($row['kind'] ?? '') !== $filterKind) {
        continue;
      }
      $rows[(int) $row['id']] = [
        'id' => (int) $row['id'],
        'label' => (string) ($row['label'] ?? ''),
        'kind' => (string) ($row['kind'] ?? ''),
        'verify_state' => (string) ($row['verify_state'] ?? ''),
        'txid' => (string) ($row['txid'] ?? ''),
        'consistent_at' => $this->formatTimestamp((int) ($row['consistent_at'] ?? 0)),
        'captured_at' => $this->formatTimestamp((int) ($row['captured_at'] ?? 0)),
        'manifest_key' => $this->markerManifestHint($row),
      ];
      if (++$count >= $limit) {
        break;
      }
    }
    return new RowsOfFields($rows);
  }

  /**
   * Show a single snapshot marker, including full restore metadata.
   */
  #[CLI\Command(name: 'drx:litestream:marker-show', aliases: ['drx-lit-marker'])]
  #[CLI\Option(name: 'id', description: 'Marker id to show.')]
  public function markerShow(array $options = ['id' => self::REQ, 'format' => 'yaml']): PropertyList|int {
    $id = (int) ($options['id'] ?? 0);
    if ($id <= 0) {
      $this->logger()->error('--id is required');
      return self::EXIT_FAILURE;
    }
    $row = $this->markers->load($id);
    if ($row === NULL) {
      $this->logger()->error(sprintf('marker %d not found', $id));
      return self::EXIT_FAILURE;
    }
    $row['consistent_at_utc'] = $this->formatTimestamp((int) ($row['consistent_at'] ?? 0));
    $row['captured_at_utc'] = $this->formatTimestamp((int) ($row['captured_at'] ?? 0));
    $row['verified_at_utc'] = $this->formatTimestamp((int) ($row['verified_at'] ?? 0));
    $row['journal_boundary_at_utc'] = $this->formatTimestamp((int) ($row['journal_boundary_at'] ?? 0));
    return new PropertyList($row);
  }

  /**
   * Delete a snapshot marker row from Drupal (does not touch S3).
   */
  #[CLI\Command(name: 'drx:litestream:marker-delete', aliases: ['drx-lit-marker-rm'])]
  #[CLI\Option(name: 'id', description: 'Marker id to delete.')]
  #[CLI\Option(name: 'force', description: 'Required confirmation flag.')]
  public function markerDelete(array $options = ['id' => self::REQ, 'force' => FALSE]): int {
    $id = (int) ($options['id'] ?? 0);
    if ($id <= 0) {
      $this->logger()->error('--id is required');
      return self::EXIT_FAILURE;
    }
    if (empty($options['force'])) {
      $this->logger()->error('refusing to delete without --force');
      return self::EXIT_FAILURE;
    }
    if ($this->markers->load($id) === NULL) {
      $this->logger()->error(sprintf('marker %d not found', $id));
      return self::EXIT_FAILURE;
    }
    $this->markers->delete($id);
    $this->logger()->success(sprintf('deleted marker %d', $id));
    return self::EXIT_SUCCESS;
  }

  /**
   * Format a unix timestamp as UTC ISO-8601, or em-dash when zero.
   */
  protected function formatTimestamp(int $ts): string {
    if ($ts <= 0) {
      return '—';
    }
    return gmdate('Y-m-d\TH:i:s\Z', $ts);
  }

  /**
   * Best-effort manifest key hint for a marker row.
   *
   * Mirrors SnapshotManifestWriter::buildObjectKey() so operators can
   * locate the JSON manifest in S3 without a second lookup.
   */
  protected function markerManifestHint(array $row): string {
    $prefix = (string) ($row['s3_prefix_litestream'] ?? '');
    $id = (int) ($row['id'] ?? 0);
    $captured = (int) ($row['captured_at'] ?? 0);
    if ($prefix === '' || $id <= 0 || $captured <= 0) {
      return '';
    }
    $ts = gmdate('Ymd\THis\Z', $captured);
    $label = $this->sanitizeKeyPart((string) ($row['label'] ?? 'snapshot'));
    $txid = $this->sanitizeKeyPart((string) ($row['txid'] ?? 'unknown-txid'));
    return sprintf(
      '%s/snapshots/%s-%s-%d-%s.json',
      rtrim($prefix, '/'),
      $ts,
      $label,
      $id,
      $txid,
    );
  }

  /**
   * Sanitize a value for inclusion in an S3 key path component.
   */
  protected function sanitizeKeyPart(string $value): string {
    $value = preg_replace('/[^A-Za-z0-9._-]+/', '-', $value) ?? '';
    $value = trim($value, '-._');
    return $value !== '' ? $value : 'snapshot';
  }

}
