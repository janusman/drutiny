<?php

namespace Drutiny\Report\Twig;

use Drutiny\AssessmentInterface;
use Drutiny\AuditResponse\AuditResponse;
use Drutiny\Policy\Chart;
use Twig\Environment;


class Helper {
  /**
   * Registered as a Twig filter to be used as: "Title here"|heading.
   */
  public static function filterSectionHeading(Environment $env, $heading, $level = 2)
  {
    return $env
      ->createTemplate('<h'.$level.' class="section-title" id="section_{{ heading | u.snake }}">{{ heading }}</h'.$level.'>')
      ->render(['heading' => $heading]);
  }

  /**
   * Registered as a Twig filter to be used as: chart.foo|chart.
   */
  public static function filterChart(Chart|null|array $chart):string
  {
    if (is_null($chart)) {
      return '';
    }
    if (is_array($chart)) {
      $chart = Chart::fromArray($chart);
    }
    $class = 'chart-unprocessed';
    if (isset($chart->htmlClass)) {
        $class .= ' '.$chart->htmlClass;
    }
    $element = '<div class="'.$class.'" ';
    foreach (get_object_vars($chart) as $name => $key) {
      $name = strtolower(preg_replace('/[A-Z]/', '-$0', $name));
      $value = is_array($key) ? implode(',', $key) : $key;
      $element .= 'data-chart-'.$name . '='.json_encode($value).' ' ;
    }
    return $element . '></div>';
  }

  public static function renderAuditReponse(Environment $twig, AuditResponse $response, AssessmentInterface $assessment):string
  {
      // Irrelevant responses should be omitted from rendering.
      if ($response->state->isIrrelevant()) {
        return '';
      }
      $globals = $twig->getGlobals();
      $template = 'report/policy/'.$response->getType().'.'.$globals['ext'].'.twig';
      $globals['logger']->info("Rendering audit response for ".$response->policy->name.' with '.$template);
      $globals['logger']->debug('Keys: ' . implode(', ', array_keys($response->tokens)));
      return $twig->render($template, [
        'audit_response' => $response,
        'assessment' => $assessment,
        'target' => $assessment->getTarget(),
      ]);
  }

  public static function keyed($variable) {
    return is_array($variable) && is_string(key($variable));
  }

  public static function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);

    // Uncomment one of the following alternatives
      $bytes /= pow(1024, $pow);

    return round($bytes, $precision) . ' ' . $units[$pow];
  }

}

 ?>
