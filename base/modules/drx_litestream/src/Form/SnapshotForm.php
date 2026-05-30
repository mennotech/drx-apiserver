<?php

declare(strict_types=1);

namespace Drupal\drx_litestream\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\drx_litestream\Service\SnapshotOrchestrator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form: capture an application-consistent snapshot marker.
 *
 * Submitting this form puts the site into maintenance mode for the
 * duration of the snapshot (typically a few seconds). The cron lock is
 * held while the snapshot runs, so any in-flight or queued cron
 * invocation will short-circuit.
 */
class SnapshotForm extends FormBase {

  public function __construct(
    protected SnapshotOrchestrator $orchestrator,
  ) {}

  /**
   * Creates the form using container-managed services.
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('drx_litestream.snapshot_orchestrator'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'drx_litestream_snapshot_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['warning'] = [
      '#markup' => $this->t('<strong>This will briefly put the site into maintenance mode</strong> while the WAL is checkpointed and the replica catches up. Typical duration: a few seconds. Cron is blocked for the duration.'),
      '#prefix' => '<div class="messages messages--warning">',
      '#suffix' => '</div>',
    ];
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#description' => $this->t('Short human-readable name, e.g. <code>pre-upgrade-@d</code>.', ['@d' => date('Y-m-d')]),
    ];
    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#rows' => 3,
    ];
    $form['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Restore notes'),
      '#rows' => 3,
      '#description' => $this->t('Optional. Anything an operator should know when restoring this snapshot.'),
    ];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Capture consistent snapshot'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $result = $this->orchestrator->createConsistent([
      'label' => (string) $form_state->getValue('label'),
      'description' => (string) $form_state->getValue('description'),
      'notes' => (string) $form_state->getValue('notes'),
    ]);

    if ($result['state'] === 'ok') {
      $this->messenger()->addStatus($this->t('Consistent snapshot captured (id=@id, txid=@txid).', [
        '@id' => $result['id'],
        '@txid' => $result['db_txid'] ?? '?',
      ]));
      $form_state->setRedirectUrl(Url::fromRoute('drx_litestream.marker_view', [
        'id' => (int) $result['id'],
      ]));
    }
    else {
      $this->messenger()->addError($this->t('Snapshot failed: @err', [
        '@err' => $result['error'] ?? 'unknown error',
      ]));
      if (!empty($result['id'])) {
        $form_state->setRedirectUrl(Url::fromRoute('drx_litestream.marker_view', [
          'id' => (int) $result['id'],
        ]));
      }
      else {
        $form_state->setRedirectUrl(Url::fromRoute('drx_litestream.markers'));
      }
    }
  }

}
