<?php

declare(strict_types=1);

namespace Drupal\drx_s3_journal\Drush\Commands;

use Drupal\drx_s3_journal\Service\Journal;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for drx_s3_journal.
 *
 * Replay itself is intentionally delegated to the operator's S3 client
 * (`aws s3 ls`, `mc ls`, etc.) — the module's contract is the
 * lexicographically-sortable key layout. These commands just expose the
 * computed prefix and let operators smoke-test the pipeline end-to-end.
 */
class DrxS3JournalCommands extends DrushCommands {

  public function __construct(
    protected Journal $journal,
  ) {
    parent::__construct();
  }

  /**
   * Creates command handlers from Drupal's service container.
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('drx_s3_journal.journal'));
  }

  /**
   * Print the S3 prefix to start a replay from for a given timestamp.
   *
   * Accepts any strtotime() input, e.g. `--since="2026-05-28T14:00:00Z"`
   * or `--since="-1 hour"`.
   */
  #[CLI\Command(name: 'drx:s3-journal:prefix', aliases: ['drx-s3j-pfx'])]
  #[CLI\Option(name: 'since', description: 'Replay start timestamp (anything strtotime understands).')]
  #[CLI\Usage(name: 'drush drx:s3-journal:prefix --since="2026-05-28T00:00:00Z"', description: 'Print the prefix containing the first matching events.')]
  public function prefix(array $options = ['since' => self::REQ]): int {
    $since = trim((string) ($options['since'] ?? ''));
    if ($since === '') {
      $this->logger()->error('--since is required');
      return self::EXIT_FAILURE;
    }
    $ts = strtotime($since);
    if ($ts === FALSE) {
      $this->logger()->error('Could not parse --since value: ' . $since);
      return self::EXIT_FAILURE;
    }
    $this->output()->writeln($this->journal->replayPrefix($ts));
    return self::EXIT_SUCCESS;
  }

  /**
   * Emits a synthetic event to verify S3 reachability and credentials.
   *
   * Returns non-zero if the write fails.
   */
  #[CLI\Command(name: 'drx:s3-journal:test', aliases: ['drx-s3j-test'])]
  public function test(): int {
    try {
      $result = $this->journal->emitTestEvent();
    }
    catch (\Throwable $e) {
      $this->logger()->error('journal test failed: ' . $e->getMessage());
      return self::EXIT_FAILURE;
    }
    $this->output()->writeln('ok ' . $result['key']);
    return self::EXIT_SUCCESS;
  }

}
