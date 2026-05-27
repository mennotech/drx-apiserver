<?php

declare(strict_types=1);

namespace Drupal\drx_litestream\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;

/**
 * Stores point-in-time markers captured from the local litestream replica.
 */
class MarkerManager {

  public function __construct(
    protected Connection $db,
    protected AccountInterface $currentUser,
    protected UuidInterface $uuid,
    protected TimeInterface $time,
  ) {}

  public function create(array $data): int {
    return (int) $this->db->insert('drx_litestream_marker')->fields([
      'uuid' => $this->uuid->generate(),
      'label' => $data['label'],
      'description' => $data['description'] ?? '',
      'replica_url' => $data['replica_url'] ?? '',
      'txid' => $data['txid'] ?? '',
      'captured_at' => $data['captured_at'] ?? $this->time->getRequestTime(),
      'created_uid' => (int) $this->currentUser->id(),
      'notes' => $data['notes'] ?? '',
    ])->execute();
  }

  public function load(int $id): ?array {
    $row = $this->db->select('drx_litestream_marker', 'm')
      ->fields('m')
      ->condition('id', $id)
      ->execute()
      ->fetchAssoc();
    return $row ?: NULL;
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  public function loadAll(): array {
    return $this->db->select('drx_litestream_marker', 'm')
      ->fields('m')
      ->orderBy('captured_at', 'DESC')
      ->execute()
      ->fetchAllAssoc('id', \PDO::FETCH_ASSOC);
  }

  public function delete(int $id): void {
    $this->db->delete('drx_litestream_marker')
      ->condition('id', $id)
      ->execute();
  }

}
