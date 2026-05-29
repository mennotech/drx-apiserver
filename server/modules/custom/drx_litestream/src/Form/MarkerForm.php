<?php

declare(strict_types=1);

namespace Drupal\drx_litestream\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\drx_litestream\Service\LitestreamStatus;
use Drupal\drx_litestream\Service\MarkerManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form: capture a point-in-time marker from the current replica LTX TXID.
 */
class MarkerForm extends FormBase {

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

  public function getFormId(): string {
    return 'drx_litestream_marker_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    // The marker's `txid` must be a value that `litestream restore -txid`
    // accepts. That is the LTX-space TXID reported by `litestream ltx`,
    // NOT the WAL-local `local_txid` from `litestream status` (which is
    // reset on checkpoint and is not a valid restore argument).
    $txid = $this->status->getReplicaLatestTxid() ?? '';
    $replica = $this->status->getReplicaUrl();

    $form['summary'] = [
      '#markup' => $this->t('Captures the current replica LTX TXID and replica URL as a named marker. Export the marker as JSON and apply the embedded <code>litestream restore -txid &lt;txid&gt;</code> command on a dev machine to reproduce this exact point in time.'),
      '#prefix' => '<p>',
      '#suffix' => '</p>',
    ];

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#description' => $this->t('Short human-readable name, e.g. <code>pre-upgrade-2026-05-27</code>.'),
    ];
    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#rows' => 3,
    ];
    $form['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Dev restore notes'),
      '#rows' => 3,
      '#description' => $this->t('Optional. Anything a developer should know when restoring this marker on a test machine.'),
    ];
    $form['captured'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Will record:'),
      'fields' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('TXID: @v', ['@v' => $txid !== '' ? $txid : '(none yet)']),
          $this->t('Replica URL: @v', ['@v' => $replica ?? '(none)']),
        ],
      ],
    ];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Capture marker'),
    ];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // See buildForm(): the marker pin must be the LTX-space TXID.
    $txid = $this->status->getReplicaLatestTxid() ?? '';
    $id = $this->markers->create([
      'label' => (string) $form_state->getValue('label'),
      'description' => (string) $form_state->getValue('description'),
      'notes' => (string) $form_state->getValue('notes'),
      'replica_url' => $this->status->getReplicaUrl() ?? '',
      'txid' => $txid,
    ]);
    $this->messenger()->addStatus($this->t('Marker captured (id=@id, txid=@txid).', [
      '@id' => $id,
      '@txid' => $txid !== '' ? $txid : '?',
    ]));
    $form_state->setRedirectUrl(Url::fromRoute('drx_litestream.markers'));
  }

}
