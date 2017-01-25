<?php
/**
 * @file CampaignArticlesResource.php
 *
 * Contains \Drupal\campaign_api\Plugin\rest\resource\CampaignArticlesResource.
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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;


/**
 * Provides a resource for campaign articles.
 * @RestResource(
 *   id = "campaignarticles",
 *   label = @Translation("Campaign Articles Collection"),
 *   uri_paths = {
 *     "canonical" = "/v0/campaign/{id}/article"
 *   }
 * )
 *
 */
class CampaignArticlesResource extends ResourceBase {

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
      $build = [];
      $langcode = $request->query->get('langcode', 'en');

      $campaign = $this->getCampaign($id, $request);

      // Load campaign articles
      $query = $this->entity_query->get('node')
        ->condition('type', 'article')
        ->condition('langcode', $langcode)
        ->condition('field_campaign', $id);

      $nids = $query->execute();

      $articles = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($nids);

      foreach ($articles as $article) {
        $build[] = $this->getArticle($article, $campaign);
      }

      $response = new ResourceResponse($build);
      $response->addCacheableDependency($langcode);

      return $response;
    }
    return new NotFoundHttpException(t('No campaign ID supplied.'));
  }

  /**
   * Load a campaign node entity.
   *
   * @todo This is a copy of the method in CampaignResource class - make it a service?
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

    $build = $this->getNodeResourceArray($campaign);
    $external = (integer) $campaign->get('field_external')->value;
    $build['external'] = !empty($external);
    $build['external_url'] = $campaign->get('field_external_site_url')->getValue();
    return $build;
  }

  protected function getArticle($article, $campaign) {
    $build = $this->getNodeResourceArray($article);
    $build['campaign'] = $campaign;
    return $build;
  }

  protected function getNodeResourceArray(NodeInterface $node) {
    $image_styles = [
      'large' => 'full',
      'medium' => 'medium',
      'thumb' => 'thumbnail'
    ];

    $image_array = [];
    $image_file = File::load($node->get('field_image')->target_id);

    foreach ($image_styles as $name => $style_name) {
      $style = ImageStyle::load($style_name);
      $image_array[$name] = [
        'url' => $style->buildUrl($image_file->getFileUri()),
        'alt' => $node->get('field_image')->alt,
      ];
    }

    $field_body = $node->get('field_body')->view('full');
    $field_body_render = \Drupal::service('renderer')->renderRoot($field_body);
    $field_body_teaser = $node->get('field_body')->view('teaser');
    $field_body_teaser_render = \Drupal::service('renderer')->renderRoot($field_body_teaser);

    $build = [
      'id' => $node->id(),
      'uuid' => $node->uuid(),
      'langcode' => $node->language()->getId(),
      'languages' => [
        $node->language()->getId() => $node->language()->getName(),
        'default' => $node->language()->getId(),
      ],
      'title' => $node->getTitle(),
      'created' => $this->date_format->format($node->getCreatedTime(), NULL, 'c'),
      'image' => $image_array,
      'lead' => $field_body_teaser_render,
      'body' => $field_body_render,
    ];
    if ($node->getType() != 'article') {
      unset($build['lead']);
    }

    return $build;
  }

}
