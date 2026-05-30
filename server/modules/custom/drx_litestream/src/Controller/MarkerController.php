<?php

declare(strict_types=1);

namespace Drupal\drx_litestream\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\drx_litestream\Service\LitestreamStatus;
use Drupal\drx_litestream\Service\MarkerManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Lists and exports captured markers.
 */
class MarkerController extends ControllerBase {

  public function __construct(
    protected MarkerManager $markers,
    protected LitestreamStatus $status,
  ) {}

  /**
   *
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('drx_litestream.markers'),
      $container->get('drx_litestream.status'),
    );
  }

  /**
   *
   */
  public function listPage(): array {
    $rows = [];
    foreach ($this->markers->loadAll() as $m) {
      $ops = [
        '#type' => 'operations',
        '#links' => [
          'view' => [
            'title' => $this->t('View JSON'),
            'url' => Url::fromRoute('drx_litestream.marker_view', ['id' => (int) $m['id']]),
          ],
          'export' => [
            'title' => $this->t('Export JSON'),
            'url' => Url::fromRoute('drx_litestream.marker_export', ['id' => (int) $m['id']]),
          ],
          'delete' => [
            'title' => $this->t('Delete'),
            'url' => Url::fromRoute('drx_litestream.marker_delete', ['id' => (int) $m['id']]),
          ],
        ],
      ];
      $rows[] = [
        [
          'data' => [
            '#type' => 'link',
            '#title' => (string) $m['label'],
            '#url' => Url::fromRoute('drx_litestream.marker_view', ['id' => (int) $m['id']]),
          ],
        ],
        $m['kind'] ?? 'live',
        $m['txid'] !== '' ? $m['txid'] : '—',
        !empty($m['captured_at']) ? $this->formatSiteDate((int) $m['captured_at']) : '—',
        $m['replica_url'] !== '' ? $m['replica_url'] : '—',
        ['data' => $ops],
      ];
    }

    return [
      '#cache' => ['max-age' => 0],
      'add' => [
        '#type' => 'container',
        '#attributes' => ['style' => 'display: flex; gap: 0.5em;'],
        '#prefix' => '<p>',
        '#suffix' => '</p>',
        'snapshot' => [
          '#type' => 'link',
          '#title' => $this->t('+ Capture consistent snapshot'),
          '#url' => Url::fromRoute('drx_litestream.snapshot_add'),
          '#attributes' => ['class' => ['button', 'button--primary']],
        ],
        'live' => [
          '#type' => 'link',
          '#title' => $this->t('+ Capture live marker'),
          '#url' => Url::fromRoute('drx_litestream.marker_add'),
          '#attributes' => ['class' => ['button']],
        ],
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Label'),
          $this->t('Kind'),
          $this->t('TXID'),
          $this->t('Captured at'),
          $this->t('Replica URL'),
          $this->t('Operations'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No markers captured yet.'),
      ],
    ];
  }

  /**
   *
   */
  public function export(int $id): Response {
    $m = $this->markers->load($id);
    if (!$m) {
      throw new NotFoundHttpException();
    }

    $payload = $this->buildPayload($m);

    $resp = new JsonResponse($payload);
    $resp->setEncodingOptions(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $m['label']);
    $resp->headers->set('Content-Disposition', sprintf('attachment; filename="marker-%s.json"', $name));
    return $resp;
  }

  /**
   *
   */
  public function view(int $id): array {
    $m = $this->markers->load($id);
    if (!$m) {
      throw new NotFoundHttpException();
    }

    $payload = $this->buildPayload($m);
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    return [
      '#cache' => ['max-age' => 0],
      'actions' => [
        '#type' => 'container',
        'back' => [
          '#type' => 'link',
          '#title' => $this->t('Back to markers'),
          '#url' => Url::fromRoute('drx_litestream.markers'),
          '#attributes' => ['class' => ['button']],
        ],
        'export' => [
          '#type' => 'link',
          '#title' => $this->t('Download JSON'),
          '#url' => Url::fromRoute('drx_litestream.marker_export', ['id' => $id]),
          '#attributes' => ['class' => ['button', 'button--primary']],
        ],
        '#prefix' => '<p>',
        '#suffix' => '</p>',
      ],
      'json' => [
        '#markup' => '<pre>' . htmlspecialchars((string) $json) . '</pre>',
      ],
    ];
  }

  /**
   * Build the marker export payload shared by download + on-screen view.
   *
   * @param array<string, mixed> $m
   */
  protected function buildPayload(array $m): array {
    $kind = (string) ($m['kind'] ?? 'live');
    $payload = [
      'schema' => 'drx-litestream-marker/v2',
      'uuid' => $m['uuid'],
      'label' => $m['label'],
      'kind' => $kind,
      'description' => $m['description'] ?? '',
      'replica_url' => $m['replica_url'] ?? '',
      'txid' => $m['txid'] ?? '',
      'captured_at' => !empty($m['captured_at']) ? $this->formatIsoDate((int) $m['captured_at']) : NULL,
      'notes' => $m['notes'] ?? '',
    ];

    if ($kind === 'consistent') {
      $payload['consistent_at'] = !empty($m['consistent_at'])
        ? $this->formatIsoDate((int) $m['consistent_at'])
        : NULL;
      $payload['source'] = [
        'bucket' => $m['bucket'] ?? '',
        's3_endpoint' => $m['s3_endpoint'] ?? '',
        's3_region' => $m['s3_region'] ?? '',
        'prefixes' => [
          'litestream' => $m['s3_prefix_litestream'] ?? '',
          'private' => $m['s3_prefix_private'] ?? '',
          'public' => $m['s3_prefix_public'] ?? '',
        ],
        'base_image_ref' => $m['base_image_ref'] ?? '',
        'drupal_site_uuid' => $m['drupal_site_uuid'] ?? '',
      ];
      $payload['verify'] = [
        'state' => $m['verify_state'] ?? '',
        'error' => $m['verify_error'] ?? '',
        'verified_at' => !empty($m['verified_at'])
          ? $this->formatIsoDate((int) $m['verified_at'])
          : NULL,
      ];
      if (!empty($m['journal_boundary_key'])) {
        $boundaryAt = (int) ($m['journal_boundary_at'] ?? 0);
        $payload['journal_boundary'] = [
          'key' => (string) $m['journal_boundary_key'],
          'event_id' => (string) ($m['journal_boundary_event_id'] ?? ''),
          'occurred_at' => $boundaryAt > 0 ? $this->formatIsoDate($boundaryAt) : NULL,
        ];
      }
    }

    $payload['dev_restore_hint'] = [
      'shell' => sprintf(
        'litestream restore -txid %s -o ./dev.sqlite %s',
        escapeshellarg($m['txid'] ?? ''),
        escapeshellarg($m['replica_url'] ?? ''),
      ),
      'docker_env' => [
        'DRX_LITESTREAM_REPLICA_URL' => $m['replica_url'] ?? '',
        'DRX_LITESTREAM_RESTORE_ON_BOOT' => 'always',
        'DRX_LITESTREAM_RESTORE_TXID' => $m['txid'] ?? '',
      ],
      'note' => $kind === 'consistent'
        ? 'For full point-in-time restore (DB + files), clone the source bucket up to consistent_at (using S3 object versions) into a new bucket, then boot a fresh image with DRX_S3_BUCKET=<new-bucket> plus the docker_env above.'
        : 'Live markers pin only the DB TXID. File state is whatever is currently in the bucket.',
    ];

    return $payload;
  }

  /**
   *
   */
  protected function formatSiteDate(int $timestamp): string {
    /** @var \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter */
    $dateFormatter = \Drupal::service('date.formatter');
    return $dateFormatter->format($timestamp, 'medium');
  }

  /**
   *
   */
  protected function formatIsoDate(int $timestamp): string {
    return (new \DateTimeImmutable('@' . $timestamp))
      ->setTimezone(new \DateTimeZone('UTC'))
      ->format('c');
  }

}
