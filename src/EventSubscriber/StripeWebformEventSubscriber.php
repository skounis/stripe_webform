<?php

namespace Drupal\stripe_webform\EventSubscriber;


use Drupal\stripe_webform\Event\StripeWebformEvents;
use Drupal\stripe\Event\StripeEvents;
use Drupal\stripe\Event\StripeWebhookEvent;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class StripeWebformEventSubscriber implements EventSubscriberInterface {


  /**
   * The entity manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entity_type_manager;

  protected $event_dispatcher;

  /**
   * Constructs a new instance.
   *
   * @param EventDispatcherInterface $dispatcher
   *   An EventDispatcherInterface instance.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EventDispatcherInterface $dispatcher, EntityTypeManagerInterface $entity_type_manager) {
    $this->event_dispatcher = $dispatcher;
    $this->entity_type_manager = $entity_type_manager;
  }

  public function handle(StripeWebhookEvent $event) {
    $stripe_event = $event->getEvent();
    $webform_submission_id = null;

    if (isset($stripe_event['data']['object']['metadata']['webform_submission_id'])) {
      $webform_submission_id = $stripe_event['data']['object']['metadata']['webform_submission_id'];
    }
    elseif (isset($stripe_event['data']['object']['customer'])) {
      $customer = $stripe_event['data']['object']['customer'];
      $customer = \Stripe\Customer::retrieve($customer);

      if (isset($customer['metadata']['webform_submission_id'])) {
        $webform_submission_id = $customer['metadata']['webform_submission_id'];
      }
    }

    if ($webform_submission_id) {
      $webform_submission = $this->entity_type_manager
        ->getStorage('webform_submission')->load($webform_submission_id);
      $webhook_event = new StripeWebformWebhookEvent($stripe_event['type'], $webform_submission, $stripe_event);
      $this->event_dispatcher
        ->dispatch(StripeWebformWebhookEvent::EVENT_NAME, $webhook_event);
    }

  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[StripeEvents::WEBHOOK][] = array('handle');
    return $events;
  }
}
