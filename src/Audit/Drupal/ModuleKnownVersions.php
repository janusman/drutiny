<?php

namespace Drutiny\Audit\Drupal;

use Drutiny\Audit;
use Drutiny\Sandbox\Sandbox;
use Drutiny\Annotation\Param;

/**
 * Check the version of Drupal project in a site.
 */
#[Dependency('Drupal.isBootstrapped')]
#[Parameter(
    name: 'rules_url',
    type: Type::STRING,
    mode: Parameter::REQUIRED,
    description: 'URL to tab-separated data containing rules.',
  )]
class ModuleKnownVersions extends Audit
{

    public function configure():void {
      $this->setDeprecated();
    }

    public function moduleVersionMatches($current_version, $version_constraint) {

        // No constraint? No problem!
        if (!trim($version_constraint)) {
            return true;
        }

        # Previous code -- tried to use semantic versioning.
        # Did not work for versions like "8.x-1.2"
        #$expression = "semver_satisfies(\"$current_version\", \"$version_constraint\")";
        #$result = $this->evaluate($expression, 'twig');

        # TODO: Surround with try/catch
        $result = preg_match('/^' . $version_constraint . '$/', $current_version);
        return $result;
    }

    private function getPmList(Sandbox $sandbox) {
        $info = $sandbox->drush(['status' => 'enabled', 'format' => 'json'])->pmList();
        return $info;
    }

    static public function getRules($rules_url) {
        $results = [];
        $raw_data = file_get_contents($rules_url);
        foreach (explode("\n", $raw_data) as $num => $row) {
            // Skip headers
            if ($num == 1) {
                continue;
            }
            // Split into fields
            $fields = str_getcsv($row, "\t");
            // First row contains field names
            if ($num == 0) {
                $field_names = $fields;
                continue;
            }
            // Only 'published' rules
            if ($fields[0] != "active") {
                continue;
            }
            $result_row = [];
            foreach ($field_names as $n => $field_name) {
                $result_row[$field_name] = $fields[$n];
            }
            $results[] = $result_row;
        }
        return $results;
    }

    public function matchDependency($expression) {
        if (!$expression) {
            return true;
        }
        return $this->evaluate($expression, 'twig');
    }

    private function checkModules($pmlist_data, $rules) {
        $results = [];
        foreach($pmlist_data as $module => $module_info) {
            foreach ($rules as $rule) {
                if ($rule['module'] == $module) {
                    if (!$this->matchDependency($rule['dependency_rules_twig'])) {
                        continue;
                    }
                    $current_version = strtolower($module_info['version']);
                    $match_version = $this->moduleVersionMatches($current_version, $rule['version_constraint']);
                    if ($match_version) {
                        $results[] = $rule;
                    }
                }
            }
        }
        return $results;
    }

    public function audit(Sandbox $sandbox)
    {
        # TODO: Use a rules_url parameter instead of hardcoded.
        #$rules_url = $this->getParameter('rules_url');
        $rules_url = 'https://docs.google.com/spreadsheets/d/e/2PACX-1vQxBi8QdvcZtuVnwhqqjcUcOHS4-Pg-nXFpWCEFXxlQ-f37jZwgRMgAxqq2vp4ujYSY-pt-XjDdP3lw/pub?gid=0&single=true&output=tsv';
        $rules = $this->getRules($rules_url);

        if (!$rules) {
            $sandbox->logger()->info("Could not read rules from URL $rules_url");
            return Audit::ERROR;
        }

        $pmlist_data = $this->getPmList($sandbox);
        $results = $this->checkModules($pmlist_data, $rules);
        $this->set('results', $results);

        # TODO: Set the severity to that of the highest-severity issue found.
        if (count($results)==0) {
            return Audit::SUCCESS;
        }
        return Audit::FAILURE;

    }
}
