<?php
/**
 * @file CampaignsResource.php
 *
 * Contains \Drupal\campaign_api\Plugin\rest\resource\CampaignsResource.
 */

namespace Drupal\campaign_api\Plugin\rest\resource;


use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Datetime\Entity\DateFormat;
use Drupal\Core\Entity\Query\QueryFactory;
use Drupal\file\Entity\File;
use Drupal\image\Entity\ImageStyle;
use Drupal\node\NodeInterface;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;


/**
 * Provides a resource for campaigns.
 * @RestResource(
 *   id = "campaigns",
 *   label = @Translation("Campaigns Collection"),
 *   uri_paths = {
 *     "canonical" = "/v0/campaign"
 *   }
 * )
 *
 */
class CampaignsResource extends ResourceBase {

  /**
   * @var QueryFactory
   */
  protected $entity_query;

  /** @var  DateFormatter */
  protected $date_format;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, array $serializer_formats, LoggerInterface $logger, QueryFactory $entity_query, DateFormatter $date_format) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->entity_query = $entity_query;
    $this->date_format = $date_format;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('rest'),
      $container->get('entity.query'),
      $container->get('date.formatter')
    );
  }

  /**
   * Responds to GET requests
   *
   * Returns an array of Campaign data.
   *
   * @param null $unserialized
   * @param \Symfony\Component\HttpFoundation\Request $request
   * @return \Drupal\rest\ResourceResponse
   */
  public function get($unserialized = NULL, Request $request) {
    $build = [];
    // Default langcode
    $langcode = $request->query->get('langcode', 'en');

    // Load campaign nodes
    $query = $this->entity_query->get('node')
      ->condition('type', 'campaign')
      ->condition('langcode', $langcode);

    $nids = $query->execute();
    $campaigns = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($nids);

    // Array of image styles to support
    $image_styles = [
      'large' => 'full',
      'medium' => 'medium',
      'thumb' => 'thumbnail'
    ];

    /** @var NodeInterface $campaign */
    foreach ($campaigns as $campaign) {
      $image_array = [];
      $image_file = File::load($campaign->get('field_image')->target_id);

      foreach ($image_styles as $name => $style_name) {
        $style = ImageStyle::load($style_name);
        $image_array[$name] = [
          'url' => $style->buildUrl($image_file->getFileUri()),
          'alt' => $campaign->get('field_image')->alt,
        ];
      }
      $external = (integer) $campaign->get('field_external')->getValue();
      $build[] = [
        'id' => $campaign->id(),
        'uuid' => $campaign->uuid(),
        'langcode' => $campaign->language()->getId(),
        'languages' => [
          $campaign->language()->getId() => $campaign->language()->getName(),
          'default' => $campaign->language()->getId(),
        ],
        'title' => $campaign->getTitle(),
        'created' => $this->date_format->format($campaign->getCreatedTime(), NULL, 'c'),
        'external' => !empty($external),
        'external_url' => $campaign->get('field_external_site_url')->getValue(),
        'description' => [
          'formatted' => check_markup($campaign->get('body')->value, $campaign->get('body')->format),
          'raw' => $campaign->get('body')->value,
        ],
        'image' => $image_array
      ];
    }

    $response = new ResourceResponse($build);
    $response->addCacheableDependency($langcode);

    return $response;
  }

}
