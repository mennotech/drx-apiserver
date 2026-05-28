<?php

declare(strict_types=1);

namespace Drupal\drx_litestream\Controller;

use Drupal\Core\Controller\ControllerBase;
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
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('drx_litestream.status'),
      $container->get('drx_litestream.remote_replica'),
    );
  }

  public function overview(): array {
    $build = ['#cache' => ['max-age' => 0]];

    if (!$this->status->isEnabled()) {
      $build['notice'] = [
        '#markup' => $this->t('Litestream is <strong>disabled</strong> for this site. Set <code>DRX_LITESTREAM_ENABLED=1</code> in the container environment and provide a replica URL and credentials to enable replication.'),
        '#prefix' => '<div class="messages messages--warning">',
        '#suffix' => '</div>',
      ];
      return $build;
    }

    $local = $this->status->getLocalStatus();
    $replica_txid = $this->status->getReplicaLatestTxid();
    $replica_txid_text = (is_string($replica_txid) && trim($replica_txid) !== '') ? $replica_txid : NULL;
    $running = $this->status->isReplicating();
    $mtime = $this->status->getLastDbMtime();
    $retention = $this->status->getRetentionInfo();
    $remote = $this->remote->getRemoteSize();

    $rows = [
      [$this->t('Replicate daemon'), $running ? $this->t('running') : $this->t('not running')],
      [$this->t('Config file'), $this->status->getConfigPath()],
      [$this->t('Database path'), $this->status->getDatabasePath()],
      [$this->t('Replica URL'), $this->status->getReplicaUrl() ?? '—'],
      [$this->t('Local status'), $local['status'] ?? '—'],
      [$this->t('Local TXID'), $local['local_txid'] ?? '—'],
      [$this->t('WAL size'), $local['wal_size'] ?? '—'],
      [$this->t('Replica latest TXID'), $replica_txid_text ?? '—'],
      [$this->t('DB last modified'), $mtime ? date('c', $mtime) : '—'],
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

    if (!empty($local['error'])) {
      $build['error'] = [
        '#markup' => '<pre>' . htmlspecialchars($local['error']) . '</pre>',
        '#prefix' => '<div class="messages messages--error">',
        '#suffix' => '</div>',
      ];
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

  protected function formatRemoteSize(array $remote): string {
    if (empty($remote['available'])) {
      return $remote['error'] ?? '—';
    }
    return $this->humanBytes((int) ($remote['bytes'] ?? 0));
  }

  protected function formatRemoteObjects(array $remote): string {
    if (empty($remote['available'])) {
      return '—';
    }
    return (string) ($remote['objects'] ?? 0);
  }

  protected function formatComputedAt(array $remote): string {
    if (empty($remote['computed_at'])) {
      return '—';
    }
    $stamp = date('c', (int) $remote['computed_at']);
    $suffix = !empty($remote['cached'])
      ? sprintf('cached, TTL %ds', (int) ($remote['ttl'] ?? 0))
      : sprintf('fresh, TTL %ds', (int) ($remote['ttl'] ?? 0));
    return sprintf('%s (%s)', $stamp, $suffix);
  }

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
