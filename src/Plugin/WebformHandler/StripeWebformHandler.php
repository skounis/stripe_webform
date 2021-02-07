<?php

namespace Drupal\stripe_webform\Plugin\WebformHandler;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Serialization\Yaml;
use Drupal\webform\Utility\WebformYaml;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Drupal\webform\WebformTokenManagerInterface;
use Drupal\webform\WebformSubmissionConditionsValidatorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Webform submission stripe handler.
 *
 * @WebformHandler(
 *   id = "stripe",
 *   label = @Translation("Stripe"),
 *   category = @Translation("Stripe"),
 *   description = @Translation("Create a customer and charge the card."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_OPTIONAL,
 * )
 */
class StripeWebformHandler extends WebformHandlerBase {

    /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The token manager.
   *
   * @var \Drupal\webform\WebformTokenManagerInterface
   */
  protected $tokenManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->configFactory = $container->get('config.factory');
    $instance->tokenManager = $container->get('webform.token_manager');
    return $instance;
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
      'stripe_customer_create' => '',
      'stripe_charge_create' => '',
      'stripe_subscription_create' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $webform = $this->getWebform();

    $elements = $webform->getElementsInitializedFlattenedAndHasValue('view');
    $options = [];
    foreach ($elements as $key => $element) {
      if ($element['#type'] == 'stripe') {
        $options[$key] = $element['#admin_title'] ?: $element['#title'] ?: $key;
      }
    }

    $form['stripe'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Stripe settings'),
    ];

    $form['stripe']['stripe_element'] = [
      '#type' => 'select',
      '#title' => $this->t('Stripe element'),
      '#required' => TRUE,
      '#options' => ['' => $this->t('-Select-')] + $options,
      '#parents' => ['settings', 'stripe_element'],
      '#default_value' => $this->configuration['stripe_element'] ?: (count($options) == 1 ? $key : ''),
    ];

    $form['stripe']['amount'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Amount'),
      '#default_value' => $this->configuration['amount'],
      '#parents' => ['settings', 'amount'],
      '#description' => $this->t('Amount to charge the credit card. You may use tokens.'),
      '#required' => TRUE,
    ];

    $form['stripe']['plan'] = [
      '#type' => 'details',
      '#title' => t('Subscriptions'),
      '#description' => $this->t('Optional fields to subscribe the customer to a plan instead of a directly charging it.'),
    ];
    $form['stripe']['plan']['plan_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Plan'),
      '#default_value' => $this->configuration['plan_id'],
      '#parents' => ['settings', 'plan_id'],
      '#description' => $this->t('Stripe subscriptions plan id. You may use tokens.'),
    ];
    $form['stripe']['plan']['quantity'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Quantity'),
      '#default_value' => $this->configuration['quantity'],
      '#parents' => ['settings', 'quantity'],
      '#description' => $this->t('Quantity of the plan to subscribe. You may use tokens.'),
    ];

    $form['stripe']['customer'] = [
      '#type' => 'details',
      '#title' => t('Customer information'),
    ];
    $form['stripe']['customer']['email'] = [
      '#type' => 'textfield',
      '#title' => t('E-mail'),
      '#parents' => ['settings', 'email'],
      '#default_value' => $this->configuration['email'],
    ];
    $form['stripe']['customer']['description'] = [
      '#type' => 'textfield',
      '#title' => t('Description'),
      '#parents' => ['settings', 'description'],
      '#default_value' => $this->configuration['description'],
    ];


    $form['stripe']['metadata_details'] = [
      '#type' => 'details',
      '#title' => $this->t('Meta data'),
      '#description' => $this->t('Additional <a href=":url" target="_blank">metadata</a> in YAML format, each line a <em>key: value</em> element. You may use tokens.', [':url' => 'https://stripe.com/docs/api#metadata']),
    ];

    $form['stripe']['metadata_details']['metadata'] = [
      '#type' => 'webform_codemirror',
      '#mode' => 'yaml',
      '#title' => $this->t('Meta data'),
      '#parents' => ['settings', 'metadata'],
      '#default_value' => $this->configuration['metadata'],
    ];

    $form['stripe']['advanced'] = [
      '#type' => 'details',
      '#title' => t('Advanced settings'),
      '#open' => FALSE,
    ];
    $form['stripe']['advanced']['currency'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Currency'),
      '#default_value' => $this->configuration['currency'],
      '#description' => $this->t('Currency to charge the credit card. You may use tokens. <a href=":uri">Supported currencies</a>.', [':uri' => 'https://stripe.com/docs/currencies']),
      '#parents' => ['settings', 'currency'],
      '#required' => TRUE,
    ];
    $form['stripe']['advanced']['stripe_customer_create'] = [
      '#type' => 'webform_codemirror',
      '#mode' => 'yaml',
      '#title' => $this->t('Customer create object'),
      '#parents' => ['settings', 'stripe_customer_create'],
      '#default_value' => $this->configuration['stripe_customer_create'],
      '#description' => $this->t('Additional fields of the stripe API call to <a href=":url" target="_blank">create a customer</a>. You cannot override the keys set by the fields above.', [':url' => 'https://stripe.com/docs/api#create_customer']),
    ];
    $form['stripe']['advanced']['stripe_charge_create'] = [
      '#type' => 'webform_codemirror',
      '#mode' => 'yaml',
      '#title' => $this->t('Charge create object'),
      '#parents' => ['settings', 'stripe_charge_create'],
      '#default_value' => $this->configuration['stripe_charge_create'],
      '#description' => $this->t('Additional fields of the stripe API call to <a href=":url" target="_blank">create a charge</a>. You cannot override the keys set by the fields above.', [':url' => 'https://stripe.com/docs/api#create_charge']),
    ];
    $form['stripe']['advanced']['stripe_subscription_create'] = [
      '#type' => 'webform_codemirror',
      '#mode' => 'yaml',
      '#title' => $this->t('Subscription create object'),
      '#parents' => ['settings', 'stripe_subscription_create'],
      '#default_value' => $this->configuration['stripe_subscription_create'],
      '#description' => $this->t('Additional fields of the stripe API call to <a href=":url" target="_blank">create a subscription</a>. You cannot override the keys set by the fields above.', [':url' => 'https://stripe.com/docs/api#create_subscription']),
    ];

    $form['stripe']['token_tree_link'] = $this->tokenManager->buildTreeLink();

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
    // If update do nothing
    if ($update) {
      return;
    }

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
        'webform_serial_id' => $webform_submission->serial(),
      ];

      if (!empty($data['metadata'])) {
        $metadata += Yaml::decode($data['metadata']);
      }

       // Create a Customer:
      $stripe_customer_create = [
        'email' => $data['email'] ?: '',
        'description' => $data['description'] ?: '',
        'source' => $webform_submission->getElementData($data['stripe_element']),
        'metadata' => $metadata,
      ];

      // Do not try to charge if no source token is provided/generated. 
      // Token is empty or invalid. Valid tokens start with `tok`
      $valid_token_prefix = 'tok';
      if (empty($stripe_customer_create['source']) || !str_starts_with($stripe_customer_create['source'] , $valid_token_prefix )) {
        $message = $this->t('No charge attempt made for webform "%webform" submission: %id', 
          [
            '%webform' => $metadata['webform_id'],
            '%id' => $metadata['webform_submission_id']
          ]);
        $this->getLogger('webform_handler_stripe')->notice($message);
        return;
      }
      if (!empty($data['stripe_customer_create'])) {
        $stripe_customer_create += Yaml::decode($data['stripe_customer_create']);
      }
      $customer = \Stripe\Customer::create($stripe_customer_create);

      if (empty($data['plan_id'])) {
        // Charge the Customer instead of the card:
        $stripe_charge_create = [
          'amount' => $data['amount'] * 100,
          'currency' => $data['currency'],
          'customer' => $customer->id,
          'description' => $stripe_customer_create['description'],
          'metadata' => $metadata,
        ];
        if (!empty($data['stripe_charge_create'])) {
          $stripe_charge_create += Yaml::decode($data['stripe_charge_create']);
        }

        $charge = \Stripe\Charge::create($stripe_charge_create);
      }
      else {
        $stripe_subscription_create = [
          'customer' => $customer->id,
          "plan" => $data['plan_id'],
          'quantity' => $data['quantity'] ?: 1,
          'metadata' => $metadata,
        ];
        if (!empty($data['stripe_subscription_create'])) {
          $stripe_subscription_create += Yaml::decode($data['stripe_subscription_create']);
        }
        \Stripe\Subscription::create($stripe_subscription_create);
      }
    }
    catch (\Stripe\Error\Base $e) {
      drupal_set_message($this->t('Stripe error: %error', ['%error' => $e->getMessage()]), 'error');
    }
 }

}
