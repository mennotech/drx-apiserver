<?php

declare(strict_types=1);

namespace Drupal\drx_litestream\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Reads litestream state by shelling out to the local CLI.
 *
 * No HTTP/IPC dependency: `litestream status` reads SQLite directly and
 * `litestream ltx` reads the replica directly. www-data needs read
 * access to the litestream config (which the base bootstrap writes with
 * mode 0644) and to the SQLite file (which it owns).
 */
class LitestreamStatus {

  public function __construct(
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  public function isEnabled(): bool {
    return getenv('DRX_LITESTREAM_ENABLED') === '1';
  }

  public function getBinary(): string {
    return '/usr/local/bin/litestream';
  }

  public function getConfigPath(): string {
    $v = getenv('DRX_LITESTREAM_CONFIG_FILE');
    return $v !== FALSE && $v !== '' ? $v : '/etc/litestream.yml';
  }

  public function getReplicaUrl(): ?string {
    $v = getenv('DRX_LITESTREAM_REPLICA_URL');
    return $v !== FALSE && $v !== '' ? $v : NULL;
  }

  public function getDatabasePath(): string {
    $v = getenv('DRUPAL_SQLITE_PATH');
    return $v !== FALSE && $v !== '' ? $v : '/var/drupal-db/db.sqlite';
  }

  /**
   * Whether a `litestream replicate` process is running on this host.
   */
  public function isReplicating(): bool {
    if (!$this->isEnabled()) {
      return FALSE;
    }
    $out = [];
    $rc = 0;
    @exec('pgrep -fa "litestream replicate" 2>/dev/null', $out, $rc);
    return $rc === 0 && !empty($out);
  }

  /**
   * Parse the human-readable `litestream status` output.
   *
   * @return array{database?:string,status?:string,local_txid?:string,wal_size?:string,error?:string}
   */
  public function getLocalStatus(): array {
    if (!$this->isEnabled()) {
      return ['status' => 'disabled'];
    }
    $bin = escapeshellarg($this->getBinary());
    $cfg = escapeshellarg($this->getConfigPath());
    $out = [];
    $rc = 0;
    @exec("$bin status -config $cfg 2>&1", $out, $rc);
    if ($rc !== 0) {
      return ['status' => 'error', 'error' => implode("\n", $out)];
    }
    foreach ($out as $line) {
      $trim = trim($line);
      if ($trim === '' || str_starts_with($trim, 'database')) {
        continue;
      }
      $cols = preg_split('/\s{2,}/', $trim);
      if ($cols && count($cols) >= 2) {
        return [
          'database' => $cols[0] ?? '',
          'status' => $cols[1] ?? '',
          'local_txid' => $cols[2] ?? '',
          'wal_size' => $cols[3] ?? '',
        ];
      }
    }
    return ['status' => 'unknown'];
  }

  /**
   * Last modification time of the SQLite file (epoch seconds, or null).
   */
  public function getLastDbMtime(): ?int {
    $path = $this->getDatabasePath();
    if (!is_readable($path)) {
      return NULL;
    }
    $stat = @stat($path);
    return $stat['mtime'] ?? NULL;
  }

  /**
   * Best-effort latest TXID present on the replica.
   */
  public function getReplicaLatestTxid(): ?string {
    if (!$this->isEnabled() || !$this->getReplicaUrl()) {
      return NULL;
    }
    $bin = escapeshellarg($this->getBinary());
    $cfg = escapeshellarg($this->getConfigPath());
    $out = [];
    $rc = 0;
    @exec("$bin ltx -config $cfg -level all 2>&1", $out, $rc);
    if ($rc !== 0) {
      return NULL;
    }
    $max = NULL;
    foreach ($out as $line) {
      if (preg_match('/([0-9a-f]{16})\s+([0-9a-f]{16})/i', $line, $m)) {
        $cand = strtolower($m[2]);
        if ($max === NULL || strcmp($cand, $max) > 0) {
          $max = $cand;
        }
      }
    }
    return $max;
  }

}
