<?php

namespace Drupal\stripe_webform\Plugin\WebformElement;

use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\WebformElementBase;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Provides a 'stripe' element.
 *
 * @WebformElement(
 *   id = "stripe",
 *   label = @Translation("Stripe element"),
 *   category = @Translation("Stripe"),
 *   description = @Translation("Provides a placeholder for a stripe elements integration."),
 * )
 */
class Stripe extends WebformElementBase {

  /**
   * {@inheritdoc}
   */
  public function getDefaultProperties() {
    $info = $this->getInfo();
    $properties = [];
    foreach ($info['#stripe_selectors'] as $key => $value) {
      $properties['stripe_selectors_' . $key] = $value;
    }
    return $properties + parent::getDefaultProperties();
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    $form['stripe'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Stripe javascript selectors'),
      '#description' => $this->t('jQuery selectors so that the value of the fields can be feed to the stripe element. i.e. %ie. The selectors are gonna be looked within the enclosing form only.', ['%ie' => ':input[name="name[first]"]']),
    ];

    $info = $this->getInfo();
    foreach ($info['#stripe_selectors'] as $key => $value) {
      $form['stripe']['stripe_selectors_' . $key] = [
        '#type' => 'textfield',
        '#title' => $this->t(ucfirst(str_replace('_', ' ', $key))),
      ];
    }

    return $form;
  }


  /**
   * {@inheritdoc}
   */
  public function prepare(array &$element, WebformSubmissionInterface $webform_submission) {
    parent::prepare($element, $webform_submission);
    $info = $this->getInfo();
    foreach ($info['#stripe_selectors'] as $key => $value) {
      if (!empty($element['#stripe_selectors_' . $key])) {
        $element['#stripe_selectors'][$key] = $element['#stripe_selectors_' . $key];
      }
    }
  }

}
