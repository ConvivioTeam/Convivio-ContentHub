<?php
/**
 * @file CampaignResource.php
 *
 * Contains \Drupal\campaign_api\Plugin\rest\resource\CampaignResource.
 */

namespace Drupal\campaign_api\Plugin\rest\resource;


use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Entity\Query\QueryException;
use Drupal\Core\Entity\Query\QueryFactory;
use Drupal\file\Entity\File;
use Drupal\image\Entity\ImageStyle;
use Drupal\node\NodeInterface;
use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;


/**
 * Provides a resource for a campaign.
 * @RestResource(
 *   id = "campaign",
 *   label = @Translation("Campaign Collection"),
 *   uri_paths = {
 *     "canonical" = "/v0/campaign/{id}"
 *   }
 * )
 *
 */
class CampaignResource extends ResourceBase {

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

  public function get($id, $unserialized = NULL, Request $request) {
    if ($id && is_numeric($id)) {

      $campaign = $this->getCampaign($id, $request);

      $image_styles = [
        'large' => 'full',
        'medium' => 'medium',
        'thumb' => 'thumbnail'
      ];

      $image_array = [];
      $image_file = File::load($campaign->get('field_image')->target_id);

      foreach ($image_styles as $name => $style_name) {
        $style = ImageStyle::load($style_name);
        $image_array[$name] = [
          'url' => $style->buildUrl($image_file->getFileUri()),
          'alt' => $campaign->get('field_image')->alt,
        ];
      }
      $external = $campaign->get('field_external')->getValue();
      $field_body = $campaign->get('field_body')->view('full');
      $field_body_render = \Drupal::service('renderer')->renderRoot($field_body);

      $build = [
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
        'body' => [
          'formatted' => $field_body_render,
        ],
        'image' => $image_array
      ];

      $response = new ResourceResponse($build);
      $response->addCacheableDependency($langcode);

      return $response;
    }
    return new NotFoundHttpException(t('No campaign ID supplied.'));
  }

  /**
   * Load a campaign node entity.
   *
   * @param $id
   * @param \Symfony\Component\HttpFoundation\Request $request
   * @return \Drupal\node\NodeInterface
   */
  protected function getCampaign($id, Request $request) {
    $langcode = $request->query->get('langcode', 'en');
    $query = $this->entity_query->get('node')
      ->condition('type', 'campaign')
      ->condition('langcode', $langcode)
      ->condition('nid', $id);

    $nids = $query->execute();
    $nid = reset($nids);

    if ($nid != $id) {
      throw new NotFoundHttpException(t('No campaign with ID @id could be found.', array('@id' => $id)));
    }

    /** @var NodeInterface $campaign */
    $campaign = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
    return $campaign;
  }

}
