<?php

declare(strict_types=1);

namespace Drupal\drx_litestream\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\drx_litestream\Service\MarkerManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirm form: delete a captured marker.
 */
class MarkerDeleteForm extends ConfirmFormBase {

  /**
   * Marker id to delete.
   */
  protected ?int $id = NULL;

  public function __construct(
    protected MarkerManager $markers,
  ) {}

  /**
   * Creates the form using container-managed services.
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('drx_litestream.markers'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'drx_litestream_marker_delete';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Delete this marker?');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('drx_litestream.markers');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?int $id = NULL): array {
    $this->id = $id;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->id !== NULL) {
      $this->markers->delete($this->id);
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
    $this->messenger()->addStatus($this->t('Marker deleted.'));
  }

}
