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

  /**
   * Inserts a new marker row and returns its numeric id.
   */
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
      'kind' => $data['kind'] ?? 'live',
      'consistent_at' => (int) ($data['consistent_at'] ?? 0),
      'bucket' => $data['bucket'] ?? '',
      's3_endpoint' => $data['s3_endpoint'] ?? '',
      's3_region' => $data['s3_region'] ?? '',
      's3_prefix_litestream' => $data['s3_prefix_litestream'] ?? '',
      's3_prefix_private' => $data['s3_prefix_private'] ?? '',
      's3_prefix_public' => $data['s3_prefix_public'] ?? '',
      'base_image_ref' => $data['base_image_ref'] ?? '',
      'drupal_site_uuid' => $data['drupal_site_uuid'] ?? '',
      'verify_state' => $data['verify_state'] ?? '',
      'verify_error' => $data['verify_error'] ?? '',
      'verified_at' => (int) ($data['verified_at'] ?? 0),
      'journal_boundary_key' => $data['journal_boundary_key'] ?? '',
      'journal_boundary_event_id' => $data['journal_boundary_event_id'] ?? '',
      'journal_boundary_at' => (int) ($data['journal_boundary_at'] ?? 0),
    ])->execute();
  }

  /**
   * Update a subset of fields on an existing marker.
   *
   * @param int $id
   *   Marker id to update.
   * @param array<string, mixed> $fields
   *   Column values to write.
   */
  public function update(int $id, array $fields): void {
    if (empty($fields)) {
      return;
    }
    $this->db->update('drx_litestream_marker')
      ->fields($fields)
      ->condition('id', $id)
      ->execute();
  }

  /**
   * Loads a single marker by id.
   */
  public function load(int $id): ?array {
    $row = $this->db->select('drx_litestream_marker', 'm')
      ->fields('m')
      ->condition('id', $id)
      ->execute()
      ->fetchAssoc();
    return $row ?: NULL;
  }

  /**
   * Loads all markers ordered by capture time descending.
   *
   * @return array<int, array<string, mixed>>
   *   Marker rows keyed by id.
   */
  public function loadAll(): array {
    return $this->db->select('drx_litestream_marker', 'm')
      ->fields('m')
      ->orderBy('captured_at', 'DESC')
      ->execute()
      ->fetchAllAssoc('id', \PDO::FETCH_ASSOC);
  }

  /**
   * Deletes one marker by id.
   */
  public function delete(int $id): void {
    $this->db->delete('drx_litestream_marker')
      ->condition('id', $id)
      ->execute();
  }

}
