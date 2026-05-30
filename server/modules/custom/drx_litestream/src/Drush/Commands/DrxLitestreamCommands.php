<?php

declare(strict_types=1);

namespace Drupal\drx_litestream\Drush\Commands;

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
  ) {
    parent::__construct();
  }

  /**
   * Drush 12 instantiates command classes that expose a static create()
   * via the Drupal container. This avoids the legacy
   * `drush.services.yml` parser, which does not understand Symfony's
   * `@service` argument references.
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('drx_litestream.snapshot_orchestrator'));
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

}
