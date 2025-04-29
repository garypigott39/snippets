<?php

namespace Drupal\cep427\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controller for extracting node details.
 *
 * Note, this is a 1-off extraction of data so it's a bit hacky.
 *
 * @Thanks to ChatGPT also, for the initial skeleton.
 */
class ExtractController extends ControllerBase {

  /**
   * Constructs an ExtractController object.
   *
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter service.
   */
  public function __construct(protected DateFormatterInterface $dateFormatter) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) : static {
    return new static(
      $container->get('date.formatter')
    );
  }

  /**
   * Extracts node details for nodes tagged with "US Market".
   *
   * @return \Symfony\Component\HttpFoundation\StreamedResponse
   *   CSV style response data.
   *
   * @throws \Exception
   */
  public function extract() : StreamedResponse {
    $names = [
      'US Economics Weekly',
      'Canada Economics Weekly',
      'Japan Economics Weekly',
      'UK Economics Weekly',
      'Europe Economics Weekly',
    ];
    $query = \Drupal::entityQuery('node')
      ->condition('type', 'publication')
      ->condition('field_service.entity.name', $names, 'IN')
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->sort('nid');

    $header = [
      'Node ID',
      'Title',
      'Tag',
      'Publication Date',
      'Table',
    ];

    $rows = [];

    if ($nids = $query->execute()) {
      $nodes = $this->entityTypeManager()
        ->getStorage('node')
        ->loadMultiple($nids);
      /** @var \Drupal\node\NodeInterface $node */
      foreach ($nodes as $node) {
        $body = $node->get('body')->getString();
        if ($tables = $this->getDataTable($body)) {
          /** @var \Drupal\taxonomy\Entity\Term $term */
          $term = $node->get('field_service')->entity;
          // Only take the first table.
          $rows[] = [
            $node->id(),
            $node->getTitle(),
            $term->getName(),
            $this->dateFormatter->format($node->get('published_at')->value, 'short'),
            $tables[0],
          ];
        }
      }
    }

    // Stream the output.
    $response = new StreamedResponse(function () use ($header, $rows) {
      $handle = fopen('php://output', 'w');
      fputcsv($handle, $header);
      foreach ($rows as $row) {
        fputcsv($handle, $row);
      }
      fclose($handle);
    });
    $response->headers->set('Content-Type', 'text/csv');
    $response->headers->set('Content-Disposition', 'attachment;filename="cep427.csv"');
    $response->headers->set('Cache-Control', 'max-age=0');

    return $response;
  }

  /**
   * Get the "matching" data table from the body.
   *
   * @param string $body
   *   The body content to search for the table.
   * @param string $regex
   *   The regex to match the table title/content.
   *
   * @return array
   *   The array of tables found.
   */
  private function getDataTable(string $body, string $regex = '/Main Economic.*Market Forecasts/i') : array {
    $data = [];

    if (empty($body)) {
      return [];
    }

    $doc = new \DOMDocument();
    // Suppress errors due to malformed HTML.
    libxml_use_internal_errors(TRUE);
    $doc->loadHTML($body);
    // Clear the errors.
    libxml_clear_errors();

    foreach ($doc->getElementsByTagName('table') as $table) {
      $rows = $table->getElementsByTagName('tr');
      foreach ($rows as $row) {
        foreach ($row->getElementsByTagName('td') as $col) {
          if (preg_match($regex, $col->textContent)) {

            $this->removeAttributes($table);
            $this->removeTags($table, ['strong', 'span', 'p']);
            if ($html = $this->utfReplace($doc->saveHTML($table))) {
              $data[] = $html;
            }
          }
        }
      }
    }
    return $data;
  }

  /**
   * Remove attributes from a DOM node.
   *
   * @param \DOMElement $element
   *   The DOM element to remove attributes from.
   */
  private function removeAttributes(\DOMElement $element) : void {
    if ($element->hasAttributes()) {
      foreach ($element->attributes as $attribute) {
        /** @var \DOMAttr $attribute */
        $element->removeAttribute($attribute->nodeName);
      }
    }
    // Also each child node.
    foreach ($element->childNodes as $child) {
      if ($child instanceof \DOMElement) {
        $this->removeAttributes($child);
      }
    }
  }

  /**
   * Remove attributes from a DOM node.
   *
   * @param \DOMElement $element
   *   The DOM element to remove attributes from.
   */
  private function removeTags(\DOMElement $table, array $tags) : void {
    foreach ($tags as $tag) {
      $elements = $table->getElementsByTagName($tag);
      // Loop backwards because the live NodeList changes as we modify the DOM.
      for ($i = $elements->length - 1; $i >= 0; $i--) {
        $el = $elements->item($i);
        $doc = $el->ownerDocument;
        // Create a text node with the tag's content.
        $textNode = $doc->createTextNode($el->textContent);

        // Replace the tag with the text node.
        $el->parentNode->replaceChild($textNode, $el);
      }
    }
  }

  /**
   * Replace UTF common characters.
   *
   * @param string $html
   *   HTML string.
   *
   * @return string
   *   Cleaned HTML string.
   */
  private function utfReplace(string $html) : string {
    if (!empty($html)) {
      $utf = [
        '\u003C' => '<',
        '\u003E' => '>',
        '\u0022' => '"',
        '\u0026amp;' => '&',
        '&amp;' => '&',
        '&nbsp;' => ' ',
        "\r\n" => '',
        "\r" => '',
        "\n" => '',
      ];
      foreach ($utf as $key => $value) {
        $html = str_replace($key, $value, $html);
      }
      // Remove excess spaces.
      $html = preg_replace('/\s+/', ' ', $html);
    }
    return $html;
  }

}
