<?php

/**
 * @file
 * Contains \Drupal\redirect\Plugin\Field\FieldType\RedirectSourceItem.
 */

namespace Drupal\redirect\Plugin\Field\FieldType;

use Drupal\Component\Utility\Random;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\Core\Url;
use Drupal\Component\Utility\UrlHelper;

/**
 * Plugin implementation of the 'link' field type for redirect source.
 *
 * @FieldType(
 *   id = "redirect_source",
 *   label = @Translation("Redirect source"),
 *   description = @Translation("Stores a redirect source"),
 *   default_widget = "redirect_source",
 *   default_formatter = "redirect_source"
 * )
 */
class RedirectSourceItem extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties['path'] = DataDefinition::create('string')
      ->setLabel(t('Path'));

    $properties['query'] = MapDataDefinition::create()
      ->setLabel(t('Query'));

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return array(
      'columns' => array(
        'path' => array(
          'description' => 'The source path',
          'type' => 'varchar',
          'length' => 2048,
        ),
        'query' => array(
          'description' => 'Serialized array of path queries',
          'type' => 'blob',
          'size' => 'big',
          'serialize' => TRUE,
        ),
      ),
      'indexes' => array(
        'path' => array(array('path', 50)),
      ),
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function generateSampleValue(FieldDefinitionInterface $field_definition) {
    // Set random length for the path.
    $domain_length = mt_rand(7, 15);
    $random = new Random();

    $values['path'] = 'http://www.' . $random->word($domain_length);

    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    return $this->path === NULL || $this->path === '';
  }

  /**
   * {@inheritdoc}
   */
  public static function mainPropertyName() {
    return 'path';
  }

  /**
   * {@inheritdoc}
   */
  public function setValue($values, $notify = TRUE) {
    // Unserialize the values.
    // @todo The storage controller should take care of this, see
    //   SqlContentEntityStorage::loadFieldItems, see
    //   https://www.drupal.org/node/2414835
    if (isset($values['query']) && is_string($values['query'])) {
      $values['query'] = unserialize($values['query']);
    }
    parent::setValue($values, $notify);
  }

  /**
   * {@inheritdoc}
   */
  public function getUrl() {
    return Url::fromUri('base:' . $this->path, ['query' => $this->query]);
  }

  /**
   * {@inheritdoc}
   */
  public function postSave($update) {

    // @todo: Find out if there is a need for including/working with config entities.
    if (($host_entity = $this->getEntity()) instanceof \Drupal\Core\Entity\ContentEntityBase) {

      // Get relevant source & redirect path information.
      $source_path = $this->values['path'];
      $redirect_uri = 'internal:/' . $host_entity->toUrl()->getInternalPath();
      /* 'uri' => 'internal:/' . $host_entity->toUrl()->getInternalPath(),
        //'title' => ($host_entity->get('title')->value ? $host_entity->get('title')->value: ''),
      ]; */

      // Create appropriate Url Objects & thereby check for valid paths.
      $same = FALSE;
      try {
        $source_url = Url::fromUri('internal:/' . $source_path);
        $redirect_url = Url::fromUri($redirect_uri);

        // Prevent infinite looping.
        if ($same = ($source_url->toString() == $redirect_url->toString())) {
          drupal_set_message($this->t(
            'The source path %source is attempting to redirect the page to itself. This will result in an infinite loop.',
            [
              '%source' => $source_path,
            ]
          ), 'error');
        }
      }
      catch (\InvalidArgumentException $e) {
        // Do nothing, we want to only compare the resulting URLs.
      }

      // Search for duplicate.
      $redirects = \Drupal::entityTypeManager()
        ->getStorage('redirect')
        ->loadByProperties(array('redirect_source.path' => $source_path));

      if (!empty($redirects)) {
        $redirect = array_shift($redirects);
        if ($host_entity->isNew() || $redirect->id() != $host_entity->id()) {
          drupal_set_message($this->t(
            'The source path %source is already being redirected. Do you want to <a href="@edit-page">edit the existing redirect</a>?',
            [
              '%source' => $source_path,
              '@edit-page' => $redirect->url('edit-form')
            ]
          ), 'error');
        }
      } else if (!$same) {
        $values = [
          'redirect_source' => $source_path,
          'redirect_redirect' => [
            'uri' => $redirect_uri,
          ],
          'status_code' => 301,
        ];
        if ($title = $host_entity->get('title')->value) {
          $values['redirect_redirect']['title'] = $title;
        }
        $redirect_entity = \Drupal::entityTypeManager()->getStorage('redirect')->create($values);
        $redirect_entity->save();
        drupal_set_message($this->t(
          'The redirect %source has been saved.',
          [
            '%source' => $source_path,
          ]
        ));
      }
    }
  }

}
