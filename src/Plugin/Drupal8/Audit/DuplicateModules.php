<?php

namespace Drutiny\Plugin\Drupal8\Audit;

use Drutiny\Audit;
use Drutiny\Policy\Dependency;
use Drutiny\Sandbox\Sandbox;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Duplicate modules.
 */
#[Dependency('Drupal.isVersion8orLater', Dependency::ON_FAIL_OMIT)]
class DuplicateModules extends Audit
{

  /**
   * @inheritdoc
   */
    public function audit(Sandbox $sandbox)
    {

        $command = <<<CMD
find \$DRUSH_ROOT -name '*.info.yml' -type f |\
grep -Ev '/themes/|/test' |\
grep -oe '[^/]*\.info.yml' | sed -e 's/.info.yml//' | sort |\
uniq -c | sort -nr | awk '{print $2": "$1}'
CMD;

        $output = $this->target->execute(Process::fromShellCommandline($command));

        if (empty($output)) {
            return true;
        }

      // Ignore modules where there are only 1 of them.
        $module_count = array_filter(Yaml::parse($output), function ($count) {
            return $count > 1;
        });

        $this->set('duplicate_modules', array_keys($module_count));

        return count($module_count) == 0;
    }
}
