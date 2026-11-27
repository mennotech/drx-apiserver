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
 *
 * The journal is append-only, so a written event cannot be taken back.
 * Files are therefore journaled and deleted one at a time: if the journal
 * write for a later file in a bulk delete fails, earlier files are already
 * fully deleted and journaled, and the failing file is neither journaled nor
 * deleted. Batching the writes would leave events for files that were never
 * deleted.
 *
 * Known residual window: if the journal write succeeds but the subsequent
 * parent::delete() throws (for example a database error), the journal holds
 * a delete event for a file that may still exist. Replay tooling should
 * confirm a delete against the restored database or object before applying
 * it.
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
      parent::delete([$entity]);
    }
  }

}
