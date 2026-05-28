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

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('drx_litestream.markers'),
      $container->get('drx_litestream.status'),
    );
  }

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
        $m['txid'] !== '' ? $m['txid'] : '—',
        !empty($m['captured_at']) ? $this->formatSiteDate((int) $m['captured_at']) : '—',
        $m['replica_url'] !== '' ? $m['replica_url'] : '—',
        ['data' => $ops],
      ];
    }

    return [
      '#cache' => ['max-age' => 0],
      'add' => [
        '#type' => 'link',
        '#title' => $this->t('+ Capture marker'),
        '#url' => Url::fromRoute('drx_litestream.marker_add'),
        '#attributes' => ['class' => ['button', 'button--primary']],
        '#prefix' => '<p>',
        '#suffix' => '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Label'),
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
    return [
      'schema' => 'drx-litestream-marker/v1',
      'uuid' => $m['uuid'],
      'label' => $m['label'],
      'description' => $m['description'] ?? '',
      'replica_url' => $m['replica_url'] ?? '',
      'txid' => $m['txid'] ?? '',
      'captured_at' => !empty($m['captured_at']) ? $this->formatIsoDate((int) $m['captured_at']) : NULL,
      'notes' => $m['notes'] ?? '',
      'dev_restore_hint' => [
        'shell' => sprintf(
          'litestream restore -txid %s -o ./dev.sqlite %s',
          escapeshellarg($m['txid'] ?? ''),
          escapeshellarg($m['replica_url'] ?? ''),
        ),
        'docker_env' => [
          'DRX_LITESTREAM_ENABLED' => '1',
          'DRX_LITESTREAM_REPLICA_URL' => $m['replica_url'] ?? '',
          'DRX_LITESTREAM_RESTORE_ON_BOOT' => 'always',
          'DRX_LITESTREAM_RESTORE_TXID' => $m['txid'] ?? '',
        ],
      ],
    ];
  }

  protected function formatSiteDate(int $timestamp): string {
    /** @var \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter */
    $dateFormatter = \Drupal::service('date.formatter');
    return $dateFormatter->format($timestamp, 'medium');
  }

  protected function formatIsoDate(int $timestamp): string {
    return (new \DateTimeImmutable('@' . $timestamp))
      ->setTimezone(new \DateTimeZone('UTC'))
      ->format('c');
  }

}
