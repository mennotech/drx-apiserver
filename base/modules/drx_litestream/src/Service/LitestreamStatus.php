<?php

declare(strict_types=1);

namespace Drupal\drx_litestream\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\Yaml\Yaml;

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

  /**
   * Returns whether litestream is enabled for this runtime.
   *
   * Honours the explicit `DRX_LITESTREAM_ENABLED=1` set by bootstrap
   * for the apache process tree, and otherwise mirrors the same
   * derivation from the shared S3 contract (see
   * `base/lib/s3.sh::drx::s3::bridge_litestream`). The fallback path
   * matters when Drush is invoked via `docker exec`, which receives
   * the container-spec env rather than the runtime env exported by
   * `init.sh`.
   */
  public function isEnabled(): bool {
    if (getenv('DRX_LITESTREAM_ENABLED') === '1') {
      return TRUE;
    }
    if ((string) getenv('DRX_S3_REQUIRED') === '0') {
      return FALSE;
    }
    return (string) getenv('DRX_S3_BUCKET') !== ''
      && (string) getenv('DRX_S3_ACCESS_KEY_ID') !== ''
      && (string) getenv('DRX_S3_SECRET_ACCESS_KEY') !== '';
  }

  /**
   * Returns the absolute path to the litestream binary.
   */
  public function getBinary(): string {
    return '/usr/local/bin/litestream';
  }

  /**
   * Returns the litestream config path used by this runtime.
   */
  public function getConfigPath(): string {
    $v = getenv('DRX_LITESTREAM_CONFIG_FILE');
    return $v !== FALSE && $v !== '' ? $v : '/etc/litestream.yml';
  }

  /**
   * Returns the replica URL, or NULL when not configured.
   *
   * Falls back to the shared-S3-contract derivation
   * (`s3://${DRX_S3_BUCKET}/${DRX_S3_PREFIX_LITESTREAM}`) when the
   * explicit env var is absent, matching what bootstrap exports for
   * litestream itself.
   */
  public function getReplicaUrl(): ?string {
    $v = getenv('DRX_LITESTREAM_REPLICA_URL');
    if ($v !== FALSE && $v !== '') {
      return $v;
    }
    $bucket = (string) getenv('DRX_S3_BUCKET');
    if ($bucket === '') {
      return NULL;
    }
    $prefix = trim((string) (getenv('DRX_S3_PREFIX_LITESTREAM') ?: 'litestream'), '/');
    return 's3://' . $bucket . '/' . $prefix;
  }

  /**
   * Returns the SQLite database path managed by litestream.
   */
  public function getDatabasePath(): string {
    $v = getenv('DRUPAL_SQLITE_PATH');
    return $v !== FALSE && $v !== '' ? $v : '/var/drupal-db/db.sqlite';
  }

  /**
   * Path to the litestream daemon control socket, or NULL if disabled.
   *
   * The base image (re)generates the litestream config with a
   * `socket:` block honouring DRX_LITESTREAM_CONTROL_SOCKET. When that
   * env var is set to an empty string the daemon does not listen and
   * `litestream sync` cannot be used; callers must then fall back to
   * polling the natural sync-interval.
   */
  public function getControlSocketPath(): ?string {
    $v = getenv('DRX_LITESTREAM_CONTROL_SOCKET');
    if ($v === FALSE) {
      // Match the base default so this works even on older container
      // images that have not exported the variable explicitly.
      return '/var/run/litestream.sock';
    }
    $v = trim((string) $v);
    return $v === '' ? NULL : $v;
  }

  /**
   * Ask the litestream daemon to flush pending WAL frames now.
   *
   * Returns ['ok' => bool, 'output' => string]. On `ok = true` the
   * caller can assume any committed-and-fsynced rows are durable on
   * the configured replica when `-wait` is honoured. This is the
   * mechanism that lets the snapshot orchestrator turn the natural
   * sync-interval-bounded latency into an immediate, bounded-wait
   * flush.
   */
  public function forceReplicaSync(int $timeoutSeconds = 30): array {
    if (!$this->isEnabled()) {
      return ['ok' => FALSE, 'output' => 'litestream disabled'];
    }
    $socket = $this->getControlSocketPath();
    if ($socket === NULL) {
      return ['ok' => FALSE, 'output' => 'control socket disabled (DRX_LITESTREAM_CONTROL_SOCKET is empty)'];
    }
    $bin = escapeshellarg($this->getBinary());
    $db = escapeshellarg($this->getDatabasePath());
    $sock = escapeshellarg($socket);
    $to = (int) max(1, $timeoutSeconds);
    // `litestream sync -timeout` expects an integer number of seconds,
    // not a duration string ("30s" is rejected as a parse error).
    $timeout = escapeshellarg((string) $to);
    $out = [];
    $rc = 0;
    @exec("$bin sync -socket $sock -wait -timeout $timeout $db 2>&1", $out, $rc);
    $joined = implode("\n", $out);
    $parsed = [];
    if (preg_match('/\{.*\}/s', $joined, $m)) {
      $decoded = json_decode($m[0], TRUE);
      if (is_array($decoded)) {
        $parsed = $decoded;
      }
    }
    return [
      'ok' => $rc === 0,
      'output' => $joined,
      'txid' => isset($parsed['txid']) ? (string) $parsed['txid'] : '',
      'replicated_txid' => isset($parsed['replicated_txid']) ? (string) $parsed['replicated_txid'] : '',
    ];
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
   *   Parsed local replication status fields.
   */
  public function getLocalStatus(): array {
    if (!$this->isEnabled()) {
      return ['status' => 'disabled'];
    }
    $bin = escapeshellarg($this->getBinary());
    $cfg = escapeshellarg($this->getConfigPath());
    $out = [];
    $rc = 0;
    @exec("timeout 10 $bin status -config $cfg 2>&1", $out, $rc);
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
    $db = escapeshellarg($this->getDatabasePath());
    $out = [];
    $rc = 0;
    // `litestream ltx` reads the replica directly and can block
    // indefinitely on a slow or empty remote; cap it so the caller
    // (status, dashboard, CLI) never hangs.
    @exec("timeout 10 $bin ltx -config $cfg -level all $db 2>&1", $out, $rc);
    if ($rc !== 0) {
      return NULL;
    }
    $max = NULL;
    foreach ($out as $line) {
      $plain = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', (string) $line);
      if (!is_string($plain)) {
        continue;
      }
      $trim = trim($plain);
      if ($trim === '' || str_starts_with(strtolower($trim), 'level ')) {
        continue;
      }
      $cols = preg_split('/\s+/', $trim);
      if (!is_array($cols) || count($cols) < 3) {
        continue;
      }
      // `litestream ltx` rows are: level, min_txid, max_txid, ...
      $cand = trim((string) ($cols[2] ?? ''));
      if ($cand === '' || !$this->isComparableTxid($cand)) {
        continue;
      }
      if ($max === NULL || $this->compareTxids($cand, $max) > 0) {
        $max = $cand;
      }
    }
    return $max;
  }

  /**
   * Returns TRUE when the value looks like a decimal or hex TXID.
   */
  protected function isComparableTxid(string $txid): bool {
    $t = trim($txid);
    return (bool) preg_match('/^[0-9]+$/', $t)
      || (bool) preg_match('/^[0-9a-fA-F]{1,16}$/', $t);
  }

  /**
   * Compares two TXID strings (decimal or hex-like).
   */
  protected function compareTxids(string $a, string $b): int {
    $a = trim($a);
    $b = trim($b);
    $aDec = (bool) preg_match('/^[0-9]+$/', $a);
    $bDec = (bool) preg_match('/^[0-9]+$/', $b);
    if ($aDec && $bDec) {
      $la = strlen($a);
      $lb = strlen($b);
      if ($la !== $lb) {
        return $la <=> $lb;
      }
      return strcmp($a, $b);
    }
    $aHex = strtolower($a);
    $bHex = strtolower($b);
    $la = strlen($aHex);
    $lb = strlen($bHex);
    if ($la !== $lb) {
      return $la <=> $lb;
    }
    return strcmp($aHex, $bHex);
  }

  /**
   * Read snapshot/retention settings from the live config file.
   *
   * Operators can opt out of Litestream-driven remote deletion by setting
   * `retention: { enabled: false }` and relying on bucket lifecycle
   * policies instead, which has very different cost / blast-radius
   * implications. This method surfaces which mode is active.
   *
   * @return array{
   *   mode: string,
   *   description: string,
   *   snapshot_interval?: string,
   *   snapshot_retention?: string,
   *   }
   *   Retention mode details derived from the active config.
   */
  public function getRetentionInfo(): array {
    $path = $this->getConfigPath();
    if (!is_readable($path)) {
      return ['mode' => 'unknown', 'description' => 'config file not readable'];
    }
    try {
      $data = Yaml::parseFile($path);
    }
    catch (\Throwable $e) {
      return ['mode' => 'unknown', 'description' => 'config parse error: ' . $e->getMessage()];
    }
    if (!is_array($data)) {
      return ['mode' => 'unknown', 'description' => 'unexpected config shape'];
    }

    $info = [];
    $retentionDisabled = FALSE;
    if (isset($data['retention']) && is_array($data['retention'])
      && array_key_exists('enabled', $data['retention'])) {
      $retentionDisabled = $data['retention']['enabled'] === FALSE;
    }
    if (isset($data['snapshot']) && is_array($data['snapshot'])) {
      if (!empty($data['snapshot']['interval'])) {
        $info['snapshot_interval'] = (string) $data['snapshot']['interval'];
      }
      if (!empty($data['snapshot']['retention'])) {
        $info['snapshot_retention'] = (string) $data['snapshot']['retention'];
      }
    }

    if ($retentionDisabled) {
      $info['mode'] = 'bucket-lifecycle';
      $info['description'] = 'Litestream remote deletes are disabled; old LTX files and snapshots are pruned by the bucket lifecycle policy.';
    }
    elseif (!empty($info['snapshot_interval']) || !empty($info['snapshot_retention'])) {
      $info['mode'] = 'litestream-managed (custom)';
      $info['description'] = 'Old LTX files and snapshots are pruned by Litestream using the configured snapshot/retention settings.';
    }
    else {
      $info['mode'] = 'litestream-managed (defaults)';
      $info['description'] = 'Old LTX files and snapshots are pruned by Litestream using upstream defaults. Set snapshot.interval / snapshot.retention in the litestream config to tune.';
    }
    return $info;
  }

  /**
   * Returns a redacted view of the live litestream config.
   *
   * @return array{ok:bool,text?:string,error?:string}
   *   Sanitized YAML text or an error description.
   */
  public function getSanitizedConfigSummary(): array {
    $path = $this->getConfigPath();
    if (!is_readable($path)) {
      return ['ok' => FALSE, 'error' => 'config file not readable'];
    }
    try {
      $data = Yaml::parseFile($path);
    }
    catch (\Throwable $e) {
      return ['ok' => FALSE, 'error' => 'config parse error: ' . $e->getMessage()];
    }
    if (!is_array($data)) {
      return ['ok' => FALSE, 'error' => 'unexpected config shape'];
    }

    $redacted = $this->redactSecrets($data);
    return [
      'ok' => TRUE,
      'text' => Yaml::dump($redacted, 8, 2),
    ];
  }

  /**
   * Computes a unified replication health snapshot.
   *
   * @return array{
   *   state:string,
   *   enabled:bool,
   *   running:bool,
   *   local_status:string,
   *   local_txid:string,
   *   replica_txid:?string,
   *   wal_size:string,
   *   replica_url:string,
   *   issues:array<int,string>,
   *   }
   *   Consolidated health state used by UI and requirements checks.
   */
  public function getHealthSnapshot(): array {
    $local = $this->getLocalStatus();
    $running = $this->isReplicating();
    $replicaTxidRaw = $this->getReplicaLatestTxid();

    $localStatus = (string) ($local['status'] ?? 'unknown');
    $localTxid = (string) ($local['local_txid'] ?? '—');
    $walSize = (string) ($local['wal_size'] ?? '—');
    $replicaUrl = (string) ($this->getReplicaUrl() ?? '—');
    $replicaTxid = (is_string($replicaTxidRaw) && trim($replicaTxidRaw) !== '')
      ? $replicaTxidRaw
      : NULL;

    $state = 'healthy';
    $issues = [];

    if (!$this->isEnabled()) {
      $state = 'disabled';
      $issues[] = 'shared DRX_S3_* contract is not active.';
    }
    else {
      if (!$running) {
        $state = 'failing';
        $issues[] = 'replicate daemon is not running';
      }

      if ($localStatus === 'error') {
        $state = 'failing';
        $issues[] = 'local status command returned an error';
      }
      elseif (!in_array(strtolower($localStatus), ['ok', 'healthy'], TRUE)) {
        if ($state !== 'failing') {
          $state = 'degraded';
        }
        $issues[] = sprintf('local status is %s', $localStatus);
      }

      if ($replicaTxid === NULL) {
        if ($state !== 'failing') {
          $state = 'degraded';
        }
        $issues[] = 'replica latest TXID could not be read';
      }
      // Note: we intentionally do not compare $localTxid (WAL-local
      // counter from `litestream status`, resets on checkpoint) to
      // $replicaTxid (LTX-space TXID from `litestream ltx`). They live
      // in different namespaces; a mismatch is normal and does not
      // imply the replica is behind. Replication lag is signalled by
      // the daemon-not-running / status-error branches above.
    }

    return [
      'state' => $state,
      'enabled' => $this->isEnabled(),
      'running' => $running,
      'local_status' => $localStatus !== '' ? $localStatus : 'unknown',
      'local_txid' => $localTxid !== '' ? $localTxid : '—',
      'replica_txid' => $replicaTxid,
      'wal_size' => $walSize !== '' ? $walSize : '—',
      'replica_url' => $replicaUrl,
      'issues' => $issues,
    ];
  }

  /**
   * Recursively redact values for sensitive config keys.
   */
  protected function redactSecrets(mixed $value, ?string $key = NULL): mixed {
    if (is_array($value)) {
      $out = [];
      foreach ($value as $k => $v) {
        $out[$k] = $this->redactSecrets($v, (string) $k);
      }
      return $out;
    }

    if ($key !== NULL && $this->isSensitiveKey($key)) {
      return '*** redacted ***';
    }

    return $value;
  }

  /**
   * Returns TRUE when a configuration key should be redacted.
   */
  protected function isSensitiveKey(string $key): bool {
    $k = strtolower($key);
    return str_contains($k, 'password')
      || str_contains($k, 'secret')
      || str_contains($k, 'token')
      || str_contains($k, 'private')
      || str_contains($k, 'identity')
      || str_contains($k, 'access-key')
      || str_contains($k, 'account-key')
      || str_contains($k, 'sse-customer-key');
  }

}
