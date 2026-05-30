<?php

declare(strict_types=1);

namespace Drupal\drx_litestream\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\drx_litestream\Service\LitestreamStatus;
use Drupal\drx_litestream\Service\RemoteReplica;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Renders the litestream replication health overview.
 */
class HealthController extends ControllerBase {

  public function __construct(
    protected LitestreamStatus $status,
    protected RemoteReplica $remote,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Creates the controller using container-managed services.
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('drx_litestream.status'),
      $container->get('drx_litestream.remote_replica'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Builds the replication health overview page.
   */
  public function overview(): array {
    $build = ['#cache' => ['max-age' => 0]];

    if (!$this->status->isEnabled()) {
      $build['notice'] = [
        '#markup' => $this->t('Litestream is <strong>disabled</strong> for this site. Enable the shared DRX_S3_* contract (<code>DRX_S3_REQUIRED=1</code> with bucket and credentials) to activate replication.'),
        '#prefix' => '<div class="messages messages--warning">',
        '#suffix' => '</div>',
      ];
      return $build;
    }

    $snapshot = $this->status->getHealthSnapshot();
    $mtime = $this->status->getLastDbMtime();
    $retention = $this->status->getRetentionInfo();
    $cfg = $this->status->getSanitizedConfigSummary();
    $remote = $this->remote->getRemoteSize();

    $stateText = [
      'healthy' => (string) $this->t('Healthy'),
      'degraded' => (string) $this->t('Degraded'),
      'disabled' => (string) $this->t('Disabled'),
      'failing' => (string) $this->t('Failing'),
    ];
    $overallState = $snapshot['state'];

    $rows = [
      [$this->t('Overall health'), $stateText[$overallState] ?? $this->t('Unknown')],
      [$this->t('Replicate daemon'), $snapshot['running'] ? $this->t('running') : $this->t('not running')],
      [$this->t('Config file'), $this->status->getConfigPath()],
      [$this->t('Database path'), $this->status->getDatabasePath()],
      [$this->t('Replica URL'), $this->status->getReplicaUrl() ?? '—'],
      [$this->t('Local status'), $snapshot['local_status']],
      [$this->t('Local TXID'), $snapshot['local_txid']],
      [$this->t('WAL size'), $snapshot['wal_size']],
      [$this->t('Replica latest TXID'), $snapshot['replica_txid'] ?? '—'],
      [$this->t('DB last modified'), $mtime ? $this->formatSiteDate((int) $mtime) : '—'],
      [$this->t('Remote backup size'), $this->formatRemoteSize($remote)],
      [$this->t('Remote object count'), $this->formatRemoteObjects($remote)],
      [$this->t('Remote size computed'), $this->formatComputedAt($remote)],
      [$this->t('Retention mode'), $retention['mode'] ?? '—'],
      [$this->t('Retention details'), $retention['description'] ?? '—'],
    ];
    if (!empty($retention['snapshot_interval'])) {
      $rows[] = [$this->t('Snapshot interval'), $retention['snapshot_interval']];
    }
    if (!empty($retention['snapshot_retention'])) {
      $rows[] = [$this->t('Snapshot retention'), $retention['snapshot_retention']];
    }
    if (!empty($snapshot['issues'])) {
      $rows[] = [$this->t('Health issues'), implode('; ', $snapshot['issues'])];
    }

    if (!empty($remote['error'])) {
      $build['remote_error'] = [
        '#markup' => $this->t('Remote size probe error: @msg', ['@msg' => $remote['error']]),
        '#prefix' => '<div class="messages messages--warning">',
        '#suffix' => '</div>',
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => [$this->t('Metric'), $this->t('Value')],
      '#rows' => $rows,
    ];

    if (!empty($cfg['ok']) && !empty($cfg['text'])) {
      $build['config'] = [
        '#type' => 'details',
        '#title' => $this->t('Litestream config (sanitized)'),
        '#open' => FALSE,
        'body' => [
          '#markup' => '<pre>' . htmlspecialchars((string) $cfg['text']) . '</pre>',
        ],
      ];
    }
    else {
      $build['config_error'] = [
        '#markup' => $this->t('Could not read litestream config summary: @msg', ['@msg' => $cfg['error'] ?? 'unknown error']),
        '#prefix' => '<div class="messages messages--warning">',
        '#suffix' => '</div>',
      ];
    }

    $build['actions'] = [
      '#type' => 'container',
      'marker' => [
        '#type' => 'link',
        '#title' => $this->t('Capture point-in-time marker'),
        '#url' => Url::fromRoute('drx_litestream.marker_add'),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
      'refresh_remote' => [
        '#type' => 'link',
        '#title' => $this->t('Refresh remote backup size'),
        '#url' => Url::fromRoute('drx_litestream.refresh_remote_size'),
        '#attributes' => ['class' => ['button']],
      ],
    ];

    return $build;
  }

  /**
   * Cache-busts the cached remote size and returns to the overview.
   */
  public function refreshRemoteSize(): RedirectResponse {
    $this->remote->invalidate();
    $this->remote->getRemoteSize(TRUE);
    $this->messenger()->addStatus($this->t('Remote backup size recomputed.'));
    return new RedirectResponse(Url::fromRoute('drx_litestream.health')->toString());
  }

  /**
   * Formats remote backup size text for display.
   */
  protected function formatRemoteSize(array $remote): string {
    if (empty($remote['available'])) {
      return $remote['error'] ?? '—';
    }
    return $this->humanBytes((int) ($remote['bytes'] ?? 0));
  }

  /**
   * Formats remote object count text for display.
   */
  protected function formatRemoteObjects(array $remote): string {
    if (empty($remote['available'])) {
      return '—';
    }
    return (string) ($remote['objects'] ?? 0);
  }

  /**
   * Formats the remote size computation timestamp for display.
   */
  protected function formatComputedAt(array $remote): string {
    if (empty($remote['computed_at'])) {
      return '—';
    }
    $stamp = $this->formatSiteDate((int) $remote['computed_at']);
    $suffix = !empty($remote['cached'])
      ? sprintf('cached, TTL %ds', (int) ($remote['ttl'] ?? 0))
      : sprintf('fresh, TTL %ds', (int) ($remote['ttl'] ?? 0));
    return sprintf('%s (%s)', $stamp, $suffix);
  }

  /**
   * Format a timestamp using Drupal's configured date/time preferences.
   */
  protected function formatSiteDate(int $timestamp): string {
    return $this->dateFormatter->format($timestamp, 'medium');
  }

  /**
   * Formats bytes into a human-readable size string.
   */
  protected function humanBytes(int $bytes): string {
    $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
    $i = 0;
    $v = (float) $bytes;
    while ($v >= 1024 && $i < count($units) - 1) {
      $v /= 1024;
      $i++;
    }
    return sprintf('%.2f %s (%d bytes)', $v, $units[$i], $bytes);
  }

}
