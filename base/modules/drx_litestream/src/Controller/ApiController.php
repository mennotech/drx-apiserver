<?php

declare(strict_types=1);

namespace Drupal\drx_litestream\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\drx_litestream\Service\LitestreamStatus;
use Drupal\drx_litestream\Service\MarkerManager;
use Drupal\drx_litestream\Service\SnapshotOrchestrator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * HTTP API endpoints for the drx_litestream module.
 *
 * All routes are gated by ApiTokenAccessCheck via the
 * `_drx_litestream_api_token` routing requirement. Set the shared
 * secret in the DRX_LITESTREAM_API_TOKEN environment variable.
 *
 * Designed for two use cases:
 *
 * 1. External monitoring (Zabbix, Uptime Kuma, Prometheus scrape).
 *    GET /drx-litestream/v1/status returns a JSON health summary.
 *    HTTP 200 = enabled+daemon running; HTTP 503 = disabled or down.
 *
 * 2. External snapshot orchestration (K8s CronJob, CI, webhooks).
 *    POST /drx-litestream/v1/snapshot triggers createConsistent()
 *    and returns the marker result as JSON.
 *
 * Running snapshots through this controller has an important
 * credential advantage over `docker exec … drush drx:litestream:snapshot`:
 * code executing here runs in the Apache process tree, which has the
 * DRX_S3_* credentials injected by init.sh. The `litestream ltx`
 * sub-process inherits those credentials, so the full TXID verification
 * path is available rather than the sync-fallback path.
 *
 * Note on HTTP timeouts: snapshots run synchronously and can take
 * 30–90 s. Configure your HTTP client timeout accordingly. The
 * container's PHP max_execution_time must also be >= that ceiling;
 * the base image default is controlled by DRX_PHP_MAX_EXECUTION_TIME.
 */
class ApiController extends ControllerBase {

  public function __construct(
    protected LitestreamStatus $status,
    protected MarkerManager $markers,
    protected SnapshotOrchestrator $orchestrator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('drx_litestream.status'),
      $container->get('drx_litestream.markers'),
      $container->get('drx_litestream.snapshot_orchestrator'),
    );
  }

  /**
   * GET /drx-litestream/v1/status.
   *
   * Returns a machine-readable replication health summary.
   *
   * Response fields:
   *   state              string  "healthy" | "degraded" | "disabled"
   *   enabled            bool    DRX_S3_* contract active
   *   replicating        bool    litestream daemon running
   *   issues             array   human-readable issue strings
   *   latest_verified_marker  object|null  most recent verified marker
   *     .id              int
   *     .label           string
   *     .txid            string
   *     .consistent_at   int    unix timestamp
   *     .captured_at     int    unix timestamp
   *
   * HTTP status:
   *   200  enabled AND daemon running (state may still be "degraded")
   *   503  litestream disabled or daemon not running
   *
   * Zabbix HTTP check pattern:
   *   URL:     http://<host>/drx-litestream/v1/status
   *   Headers: Authorization: Bearer <token>
   *   Match:   "state":"healthy"   (string match on body)
   *   Trigger: HTTP status != 200   (availability alert)
   */
  public function status(): JsonResponse {
    $snap = $this->status->getHealthSnapshot();

    // Find the most recent verified marker (loadAll() returns DESC by
    // captured_at, so the first verified row is the latest).
    $latestVerified = NULL;
    foreach ($this->markers->loadAll() as $row) {
      if ((string) ($row['verify_state'] ?? '') === 'verified') {
        $latestVerified = [
          'id' => (int) $row['id'],
          'label' => (string) ($row['label'] ?? ''),
          'txid' => (string) ($row['txid'] ?? ''),
          'consistent_at' => (int) ($row['consistent_at'] ?? 0),
          'captured_at' => (int) ($row['captured_at'] ?? 0),
        ];
        break;
      }
    }

    $enabled = (bool) ($snap['enabled'] ?? FALSE);
    $running = (bool) ($snap['running'] ?? FALSE);

    $body = [
      'state' => (string) ($snap['state'] ?? 'unknown'),
      'enabled' => $enabled,
      'replicating' => $running,
      'issues' => (array) ($snap['issues'] ?? []),
      'latest_verified_marker' => $latestVerified,
    ];

    // 503 when litestream is disabled or the daemon stopped; 200
    // otherwise so Zabbix/Uptime-Kuma can differentiate states via
    // the body rather than just the status code.
    $httpCode = ($enabled && $running) ? 200 : 503;
    return new JsonResponse($body, $httpCode);
  }

  /**
   * POST /drx-litestream/v1/snapshot.
   *
   * Triggers an application-consistent snapshot synchronously.
   *
   * Request body (JSON, Content-Type: application/json):
   *   { "label": "pre-upgrade-2026-06-01",
   *     "description": "...", "notes": "..." }
   *
   * label is required; description and notes are optional.
   * As a convenience, label may also be passed as a query parameter:
   *   POST /drx-litestream/v1/snapshot?label=pre-upgrade-2026-06-01
   *
   *
   * Successful response (HTTP 200):
   *   { "id": 7, "state": "ok", "db_txid": "83",
   *     "consistent_at": 1748659183,
   *     "manifest_key": "litestream/snapshots/..." }
   *
   * Failure response (HTTP 400/500/503):
   *   { "state": "failed", "error": "..." }
   */
  public function snapshot(Request $request): JsonResponse {
    if (!$this->status->isEnabled()) {
      return new JsonResponse(['state' => 'failed', 'error' => 'litestream is not enabled'], 503);
    }

    $body = [];
    $content = $request->getContent();
    if ($content !== '') {
      $decoded = json_decode($content, TRUE);
      if (is_array($decoded)) {
        $body = $decoded;
      }
    }

    // Allow label as query param for simple curl one-liners.
    $label = trim((string) ($body['label'] ?? $request->query->get('label', '')));
    if ($label === '') {
      return new JsonResponse(['state' => 'failed', 'error' => 'label is required'], 400);
    }

    try {
      $result = $this->orchestrator->createConsistent([
        'label' => $label,
        'description' => (string) ($body['description'] ?? ''),
        'notes' => (string) ($body['notes'] ?? ''),
      ]);
    }
    catch (\Throwable $e) {
      return new JsonResponse(['state' => 'failed', 'error' => $e->getMessage()], 500);
    }

    $httpCode = $result['state'] === 'ok' ? 200 : 500;
    return new JsonResponse($result, $httpCode);
  }

}
