<?php

declare(strict_types=1);

namespace Drupal\drx_s3_journal\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Drupal\drx_s3_journal\Service\Journal;
use Drupal\drx_s3_journal\Service\JournalWriter;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for drx_s3_journal.
 *
 * Replay itself is intentionally delegated to the operator's S3 client
 * (`aws s3 ls`, `mc ls`, etc.) — the module's contract is the
 * lexicographically-sortable key layout. These commands expose the
 * computed prefix and let operators smoke-test the pipeline end-to-end.
 */
class DrxS3JournalCommands extends DrushCommands {

  public function __construct(
    protected Journal $journal,
    protected JournalWriter $writer,
  ) {
    parent::__construct();
  }

  /**
   * Creates command handlers from Drupal's service container.
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('drx_s3_journal.journal'),
      $container->get('drx_s3_journal.writer'),
    );
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

  /**
   * Show drx_s3_journal runtime configuration.
   */
  #[CLI\Command(name: 'drx:s3-journal:status', aliases: ['drx-s3j-status'])]
  #[CLI\FieldLabels(labels: [
    'enabled' => 'Enabled',
    'bucket' => 'Bucket',
    'region' => 'Region',
    'endpoint' => 'Endpoint',
    'prefix' => 'Journal prefix',
    'sample_key' => 'Sample key',
  ])]
  public function status(array $options = ['format' => 'table']): PropertyList {
    return new PropertyList([
      'enabled' => $this->writer->isEnabled() ? 'yes' : 'no',
      'bucket' => (string) (getenv('DRX_S3_BUCKET') ?: '—'),
      'region' => (string) (getenv('DRX_S3_REGION') ?: 'us-east-1'),
      'endpoint' => (string) (getenv('DRX_S3_ENDPOINT') ?: '—'),
      'prefix' => $this->journal->getJournalPrefix(),
      'sample_key' => $this->journal->previewKey(time(), 'preview', 'public', 0),
    ]);
  }

  /**
   * Preview the journal key that would be written for an event.
   *
   * Side-effect free: does not write to S3. Useful when debugging
   * downstream consumers that key off the layout.
   */
  #[CLI\Command(name: 'drx:s3-journal:key-preview', aliases: ['drx-s3j-key'])]
  #[CLI\Option(name: 'op', description: 'Event op (e.g. create, update, delete, snapshot, test).')]
  #[CLI\Option(name: 'stream', description: 'Stream wrapper scope (public, private, meta).')]
  #[CLI\Option(name: 'fid', description: 'File id to embed in the key (default 0).')]
  #[CLI\Option(name: 'at', description: 'Timestamp expression (default now).')]
  public function keyPreview(
    array $options = [
      'op' => 'update',
      'stream' => 'public',
      'fid' => 0,
      'at' => 'now',
    ],
  ): int {
    $at = strtotime((string) ($options['at'] ?? 'now'));
    if ($at === FALSE) {
      $this->logger()->error('Could not parse --at value');
      return self::EXIT_FAILURE;
    }
    $this->output()->writeln($this->journal->previewKey(
      $at,
      (string) ($options['op'] ?? 'update'),
      (string) ($options['stream'] ?? 'public'),
      (int) ($options['fid'] ?? 0),
    ));
    return self::EXIT_SUCCESS;
  }

}
