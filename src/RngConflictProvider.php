<?php

/**
 * @file
 * Contains \Drupal\rng_conflict\RngConflictProvider.
 */

namespace Drupal\rng_conflict;

use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\rng\EventManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Database\Query\AlterableInterface;

/**
 * Event conflict provider provider.
 */
class RngConflictProvider implements RngConflictProviderInterface {

  use ContainerAwareTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The RNG event manager.
   *
   * @var \Drupal\rng\EventManagerInterface
   */
  protected $eventManager;

  /**
   * Construct a new RngConflictProvider.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\rng\EventManagerInterface $event_manager
   *   The RNG event manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EventManagerInterface $event_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->eventManager = $event_manager;
  }

  /**
   * @inheritdoc
   */
  public function getSimilarEvents(EntityInterface $event) {
    $storage = $this->entityTypeManager
      ->getStorage($event->getEntityTypeId());
    $event_query = $storage->getQuery();

    foreach (['field_date', 'field_track'] as $field_name) {
      /** @var \Drupal\Core\Field\FieldItemList $field_item_list */
      $field_item_list = $event->{$field_name};

      // Cancel if any fields are empty.
      $field_item_list->filterEmptyItems();
      if (!count($field_item_list)) {
        return [];
      }

      $columns = $field_item_list->getFieldDefinition()
        ->getFieldStorageDefinition()
        ->getColumns();

      foreach ($field_item_list->getValue() as $item) {
        // @todo how to handle multiple item values.
        // Remove fields that will not be saved. (eg. Creating an entity will
        // add a temporary '_attributes' column. etc.
        $item = array_intersect_key($item, $columns);
        foreach ($item as $column => $value) {
          $event_query->condition($field_name . '.' . $column, $value);
        }
      }
    }

    // Similar event entity ID's.
    $ids = $event_query->execute();

    // Unset this event.
    unset($ids[$event->id()]);

    return $storage->loadMultiple($ids);
  }

  /**
   * @inheritdoc
   */
  public function alterQuery(AlterableInterface &$query) {
    /** @var \Drupal\rng\Plugin\EntityReferenceSelection\RNGSelectionBase $handler */
    $handler = $query->getMetaData('entity_reference_selection_handler');

    $event = $handler->eventMeta->getEvent();
    $registrant_entity_type_id = $handler->entityType->id();
    $similar_events = $this->getSimilarEvents($event);

    if ($registrant_ids = $this->getRegistrantsIdsFromEvents($similar_events, $registrant_entity_type_id)) {
      $query->condition('base_table.' . $handler->entityType->getKey('id'), $registrant_ids, 'NOT IN');
    }
  }

  /**
   * Get entity IDs of registrants for events.
   *
   * @param \Drupal\Core\Entity\EntityInterface[] $events
   *   An array of event entity.
   * @param $registrant_entity_type_id
   *   A registrant entity type ID.
   *
   * @return integer[]
   *   An array of registrant entity ID's.
   */
  protected function getRegistrantsIdsFromEvents(array $events, $registrant_entity_type_id) {
    $registrant_ids = [];

    foreach ($events as $event) {
      $event_meta = $this->eventManager->getMeta($event);
      foreach ($event_meta->getRegistrants($registrant_entity_type_id) as $registrant) {
        if ($identity = $registrant->getIdentity()) {
          $registrant_ids[$identity->id()] = $identity->id();
        }
      }
    }

    return $registrant_ids;
  }

}