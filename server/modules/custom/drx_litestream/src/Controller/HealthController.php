<?php

declare(strict_types=1);

namespace Drupal\drx_litestream\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\drx_litestream\Service\LitestreamStatus;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the litestream replication health overview.
 */
class HealthController extends ControllerBase {

  public function __construct(
    protected LitestreamStatus $status,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('drx_litestream.status'));
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
    $running = $this->status->isReplicating();
    $mtime = $this->status->getLastDbMtime();

    $rows = [
      [$this->t('Replicate daemon'), $running ? $this->t('running') : $this->t('not running')],
      [$this->t('Config file'), $this->status->getConfigPath()],
      [$this->t('Database path'), $this->status->getDatabasePath()],
      [$this->t('Replica URL'), $this->status->getReplicaUrl() ?? '—'],
      [$this->t('Local status'), $local['status'] ?? '—'],
      [$this->t('Local TXID'), $local['local_txid'] ?? '—'],
      [$this->t('WAL size'), $local['wal_size'] ?? '—'],
      [$this->t('Replica latest TXID'), $replica_txid ?? '—'],
      [$this->t('DB last modified'), $mtime ? date('c', $mtime) : '—'],
    ];

    if (!empty($local['error'])) {
      $build['error'] = [
        '#markup' => '<pre>' . htmlspecialchars($local['error']) . '</pre>',
        '#prefix' => '<div class="messages messages--error">',
        '#suffix' => '</div>',
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => [$this->t('Metric'), $this->t('Value')],
      '#rows' => $rows,
    ];

    $build['actions'] = [
      '#type' => 'link',
      '#title' => $this->t('Capture point-in-time marker'),
      '#url' => Url::fromRoute('drx_litestream.marker_add'),
      '#attributes' => ['class' => ['button', 'button--primary']],
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    return $build;
  }

}
