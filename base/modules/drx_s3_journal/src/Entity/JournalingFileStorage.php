<?php

declare(strict_types=1);

namespace Drupal\drx_s3_journal\Entity;

use Drupal\file\FileInterface;
use Drupal\file\FileStorage;

/**
 * File entity storage that journals deletions before anything is removed.
 *
 * Core's File::preDelete() removes the file from the file system (here, the
 * S3 object) before hook_entity_predelete() and hook_entity_delete() run, so
 * a delete journal event written from a hook would come too late: a failed
 * journal write would roll back the database row but leave the content
 * already deleted without a durable event. Writing the event here, before
 * parent::delete(), keeps the strict-audit guarantee: if the journal write
 * throws, nothing has been deleted.
 */
class JournalingFileStorage extends FileStorage {

  /**
   * {@inheritdoc}
   */
  public function delete(array $entities) {
    $journal = \Drupal::service('drx_s3_journal.journal');
    foreach ($entities as $entity) {
      if ($entity instanceof FileInterface) {
        $journal->record('delete', $entity);
      }
    }
    parent::delete($entities);
  }

}
