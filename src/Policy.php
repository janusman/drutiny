<?php

namespace Drutiny;

use Drutiny\Container;
use Drutiny\Item\Item;
use Drutiny\Policy\ValidationException;
use Drutiny\PolicySource\PolicySource;
use RomaricDrigon\MetaYaml\Exception\NodeValidatorException;
use RomaricDrigon\MetaYaml\MetaYaml;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * A Drutiny policy.
 *
 * @see policy.schema.yml
 */
class Policy extends Item {
  use \Drutiny\Item\ContentSeverityTrait;
  use \Drutiny\Item\ParameterizedContentTrait {
    getParameterDefaults as public useTraitgetParameterDefaults;
  }

  /**
   * @string A written recommendation of what remediation to take if the policy fails.
   */
  protected $remediation;

  /**
   * @string A written failure message template. May contain tokens.
   */
  protected $failure;

  /**
   * @string A written success message. May contain tokens.
   */
  protected $success;

  /**
   * @string A written warning message. May contain tokens.
   */
  protected $warning;

  /**
   * @string An array of dependencies.
   */
  protected $depends = [];

  /**
   * @boolean Determine if a policy is remediable.
   */
  protected $remediable;

  /**
   * @array Chart metadata.
   */
  protected $chart = [];

  /**
   * @string Absolute location of the YAML policy file.
   */
  protected $filepath;

  public static function load($name)
  {
    return PolicySource::loadPolicyByName($name);
  }

  public function export()
  {
    return array_filter([
      'title' => $this->title,
      'name' => $this->name,
      'class' => $this->class,
      'description' => $this->description,
      'type' => $this->type,
      'tags' => $this->tags,
      'success' => $this->success,
      'failure' => $this->failure,
      'warning' => $this->warning,
      'remediation' => $this->remediation,
      'severity' => $this->severity,
      'depends' => $this->depends,
      'chart' => $this->chart
    ]);
  }

  /**
   * Retrieve a property value and token replacement.
   *
   * @param $property
   * @param array $replacements
   * @return string
   * @throws \Exception
   */
  public function __construct(array $info) {

    try {
      $schema = new MetaYaml(Yaml::parseFile(__DIR__ . '/policy.schema.yml'));
      $schema->validate($info);
    }
    catch (NodeValidatorException $e) {
      throw new PolicyValidationException($info, $e);
    }

    $severity = isset($info['severity']) ? $info['severity'] : self::SEVERITY_NORMAL;
    $this->setSeverity($severity);

    parent::__construct($info);

    // Data type policies do not have a severity.
    if ($this->type == 'data') {
      $severity = self::SEVERITY_NONE;
    }
    $this->renderableProperties[] = 'remediation';
    $this->renderableProperties[] = 'success';
    $this->renderableProperties[] = 'failure';
    $this->renderableProperties[] = 'warning';

    $reflect = new \ReflectionClass($this->class);
    $this->remediable = $reflect->implementsInterface('\Drutiny\RemediableInterface');

  }

  /**
   */
  }

  /**
   * Override ParameterizedContentTrait::getParameterDefaults.
   */
  public function getParameterDefaults()
  {
      $defaults = $this->useTraitgetParameterDefaults();

      $audit = (new Registry)->getAuditMedtadata($this->class);

      // Validation. Look for parameters specificed by the policy and not the
      // audit.
      foreach (array_keys($defaults) as $name) {
        if (!isset($audit->params[$name])) {
          Container::getLogger()->warning(strtr('Policy :name documents parameter ":param" not documented by :class.', [
            ':name' => $this->name,
            ':param' => $name,
            ':class' => $this->class,
          ]));
        }
      }

      foreach ($audit->params as $param) {
        if (!isset($defaults[$param->name])) {
          $defaults[$param->name] = isset($param->default) ? $param->default : null;
        }
      }

      $defaults['_chart'] = array_map(function ($chart) {
        $chart += $chart + [
          'type' => 'bar',
          'hide-table' => false,
          'stacked' => false,
          'series' => [],
          'series-labels' => [],
          'labels' => [],
          'title' => ''
        ];

        $el = [];
        foreach ($chart as $attr => $value) {
          $value = is_array($value) ? implode(',', $value) : $value;
          $el[] = $attr . '="' . $value . '"';
        }

        return '[[[' . implode(' ', $el) . ']]]';
      }, $this->chart);

      return $defaults;
  }
}
