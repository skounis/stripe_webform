<?php

namespace Drupal\stripe_webform\Plugin\WebformHandler;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Serialization\Yaml;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\webform\Utility\WebformYaml;
use Drupal\webform\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform\WebformInterface;
use Drupal\webform\WebformTokenManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Webform submission debug handler.
 *
 * @WebformHandler(
 *   id = "stripe",
 *   label = @Translation("Stripe"),
 *   category = @Translation("Stripe"),
 *   description = @Translation("Create a customer and charge the card."),
 *   cardinality = \Drupal\webform\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 * )
 */
class StripeWebformHandler extends WebformHandlerBase {

  /**
   * The token manager.
   *
   * @var \Drupal\webform\WebformTokenManagerInterface
   */
  protected $tokenManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerInterface $logger, ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager, WebformTokenManagerInterface $token_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $logger, $config_factory, $entity_type_manager);
    $this->tokenManager = $token_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory')->get('webform.stripe'),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('webform.token_manager')
    );
  }
  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'amount' => '',
      'stripe_element' => '',
      'plan_id' => '',
      'quantity' => '',
      'currency' => 'usd',
      'description' => '',
      'email' => '',
      'metadata' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $webform = $this->getWebform();

    $elements = $webform->getElementsInitializedFlattenedAndHasValue('view');
    foreach ($elements as $key => $element) {
      if ($element['#type'] == 'stripe') {
        $options[$key] = $element['#admin_title'] ?: $element['#title'] ?: $key;
      }
    }

    $form['stripe_element'] = [
      '#type' => 'select',
      '#title' => $this->t('Stripe element'),
      '#required' => TRUE,
      '#options' => ['' => $this->t('-Select-')] + $options,
      '#default_value' => $this->configuration['stripe_element'] ?: (count($options) == 1 ? $key : ''),
    ];

    $form['amount'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Amount'),
      '#default_value' => $this->configuration['amount'],
      '#description' => $this->t('Amount to charge the credit card. You may use tokens.'),
      '#required' => TRUE,
    ];

    $form['plan'] = [
      '#type' => 'details',
      '#title' => t('Subscriptions'),
      '#description' => $this->t('Optional fields to subscribe the customer to a plan instead of a directly charging it.'),
    ];
    $form['plan']['plan_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Plan'),
      '#default_value' => $this->configuration['plan_id'],
      '#parents' => ['settings', 'plan_id'],
      '#description' => $this->t('Stripe subscriptions plan id. You may use tokens.'),
    ];
    $form['plan']['quantity'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Quantity'),
      '#default_value' => $this->configuration['quantity'],
      '#parents' => ['settings', 'quantity'],
      '#description' => $this->t('Quantity of the plan to subscribe. You may use tokens.'),
    ];

    $form['customer'] = [
      '#type' => 'details',
      '#title' => t('Customer information'),
    ];
    $form['customer']['email'] = [
      '#type' => 'textfield',
      '#title' => t('E-mail'),
      '#parents' => ['settings', 'email'],
      '#default_value' => $this->configuration['email'],
    ];
    $form['customer']['description'] = [
      '#type' => 'textfield',
      '#title' => t('Description'),
      '#parents' => ['settings', 'description'],
      '#default_value' => $this->configuration['description'],
    ];


    $form['metadata'] = [
      '#type' => 'details',
      '#title' => $this->t('Meta data'),
      '#description' => $this->t('Additional metadata in YAML format, each line a key:value element. You may use tokens.'),
    ];

    $form['metadata']['metadata'] = [
      '#type' => 'webform_codemirror',
      '#mode' => 'yaml',
      '#title' => $this->t('Meta data'),
      '#parents' => ['settings', 'metadata'],
      '#default_value' => $this->configuration['metadata'],
    ];

    $form['advanced'] = [
      '#type' => 'details',
      '#title' => t('Advanced settings'),
      '#open' => FALSE,
    ];
    $form['advanced']['currency'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Currency'),
      '#default_value' => $this->configuration['currency'],
      '#description' => $this->t('Currency to charge the credit card. You may use tokens. <a href=":uri">Supported currencies.</a>', [':uri' => 'https://stripe.com/docs/currencies']),
      '#parents' => ['settings', 'currency'],
      '#required' => TRUE,
    ];

    $form['token_tree_link'] = $this->tokenManager->buildTreeLink();

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $values = $form_state->getValues();
    foreach ($this->configuration as $name => $value) {
      if (isset($values[$name])) {
        $this->configuration[$name] = $values[$name];
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    $uuid = $this->configFactory->get('system.site')->get('uuid');

    $config = $this->configFactory->get('stripe.settings');

    // Replace tokens.
    $data = $this->tokenManager->replace($this->configuration, $webform_submission);

    try {
      \Stripe\Stripe::setApiKey($config->get('apikey.' . $config->get('environment') . '.secret'));

      $metadata = [
        'uuid' => $uuid,
        'webform' => $webform_submission->getWebform()->label(),
        'webform_id' => $webform_submission->getWebform()->id(),
        'webform_submission_id' => $webform_submission->id(),
      ];

      $metadata += Yaml::decode($data['metadata']);

       // Create a Customer:
      $customer = \Stripe\Customer::create([
        'email' => $data['email'] ?: '',
        'description' => $data['description'] ?: '',
        'source' => $webform_submission->getData($data['stripe_element']),
        'metadata' => $metadata,
      ]);

      if (empty($data['plan_id'])) {
        // Charge the Customer instead of the card:
        $charge = \Stripe\Charge::create([
          'amount' => $data['amount'] * 100,
          'currency' => $data['currency'],
          'customer' => $customer->id,
          'metadata' => $metadata,
        ]);
      }
      else {
        \Stripe\Subscription::create([
          'customer' => $customer->id,
          "plan" => $data['plan_id'],
          'quantity' => $data['quantity'] ?: 1,
          'metadata' => $metadata,
        ]);
      }
    }
    catch (\Stripe\Error\Base $e) {
      drupal_set_message($this->t('Stripe error: %error', ['%error' => $e->getMessage()]), 'error');
    }
 }

}
