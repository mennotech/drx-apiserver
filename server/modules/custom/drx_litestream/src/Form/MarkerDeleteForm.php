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

  protected ?int $id = NULL;

  public function __construct(
    protected MarkerManager $markers,
  ) {}

  /**
   *
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('drx_litestream.markers'));
  }

  /**
   *
   */
  public function getFormId(): string {
    return 'drx_litestream_marker_delete';
  }

  /**
   *
   */
  public function getQuestion() {
    return $this->t('Delete this marker?');
  }

  /**
   *
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('drx_litestream.markers');
  }

  /**
   *
   */
  public function getConfirmText() {
    return $this->t('Delete');
  }

  /**
   *
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?int $id = NULL): array {
    $this->id = $id;
    return parent::buildForm($form, $form_state);
  }

  /**
   *
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if ($this->id !== NULL) {
      $this->markers->delete($this->id);
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
    $this->messenger()->addStatus($this->t('Marker deleted.'));
  }

}
