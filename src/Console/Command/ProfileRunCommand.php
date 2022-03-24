<?php

namespace Drutiny\Console\Command;

use Async\Process;
use Drutiny\Assessment;
use Drutiny\AssessmentManager;
use Drutiny\Report\FilesystemFormatInterface;
use Drutiny\Target\InvalidTargetException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Style\SymfonyStyle;


/**
 * Run a profile and generate a report.
 */
class ProfileRunCommand extends DrutinyBaseCommand
{
    use ReportingCommandTrait;
    use DomainSourceCommandTrait;
    use LanguageCommandTrait;

    const EXIT_INVALID_TARGET = 114;

  /**
   * @inheritdoc
   */
    protected function configure()
    {
        parent::configure();

        $this
        ->setName('profile:run')
        ->setDescription('Run a profile of checks against a target.')
        ->addArgument(
            'profile',
            InputArgument::REQUIRED,
            'The name of the profile to run.'
        )
        ->addArgument(
            'target',
            InputArgument::REQUIRED,
            'The target to run the policy collection against.'
        )
        ->addOption(
            'uri',
            'l',
            InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
            'Provide URLs to run against the target. Useful for multisite installs. Accepts multiple arguments.',
            []
        )
        ->addOption(
            'exit-on-severity',
            'x',
            InputOption::VALUE_OPTIONAL,
            'Send an exit code to the console if a policy of a given severity fails. Defaults to none (exit code 0). (Options: none, low, normal, high, critical)',
            FALSE
        )
        ->addOption(
            'exclude-policy',
            'e',
            InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
            'Specify policy names to exclude from the profile that are normally listed.',
            []
        )
        ->addOption(
            'include-policy',
            'p',
            InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
            'Specify policy names to include in the profile in addition to those listed in the profile.',
            []
        )
        ->addOption(
            'report-summary',
            null,
            InputOption::VALUE_NONE,
            'Flag to additionally render a summary report for all targets audited.'
        )
        ->addOption(
            'title',
            't',
            InputOption::VALUE_OPTIONAL,
            'Override the title of the profile with the specified value.',
            false
        )
        ;
        $this->configureReporting();
        $this->configureDomainSource();
        $this->configureLanguage();
    }

  /**
   * {@inheritdoc}
   */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $progress = $this->getProgressBar(6);
        $progress->start();
        $progress->setMessage("Loading profile..");

        $this->initLanguage($input);

        $console = new SymfonyStyle($input, $output);

        $profile = $this->getProfileFactory()
          ->loadProfileByName($input->getArgument('profile'))
          ->setReportPerSite(true);

        $progress->advance();
        $progress->setMessage("Loading policy definitions..");

        // Override the title of the profile with the specified value.
        if ($title = $input->getOption('title')) {
            $profile->title = $title;
        }

        // Setup the reporting formats. These are intiated before the auditing
        // incase there is a failure in establishing the format.
        $progress->setMessage("Loading formats..");
        $formats = $this->getFormats($input, $profile);

        // Allow command line to add policies to the profile.
        $included_policies = $input->getOption('include-policy');
        foreach ($included_policies as $policy_name) {
            $this->getLogger()->debug("Loading policy definition: $policy_name");
            $profile->addPolicies([
              $policy_name => ['name' => $policy_name]
            ]);
        }
        $progress->advance();
        $progress->setMessage("Loading targets..");

        // Allow command line omission of policies highlighted in the profile.
        // WARNING: This may remove policy dependants which may make polices behave
        // in strange ways.
        $profile->excluded_policies = $input->getOption('exclude-policy') ?? [];

        // Get the URLs.
        $uris = $input->getOption('uri');

        $domains = [];
        foreach ($this->parseDomainSourceOptions($input) as $source => $options) {
            $this->getLogger()->debug("Loading domains from $source.");
            $domains = array_merge($this->getDomainSource()->getDomains($source, $options), $domains);
        }
        $progress->advance();
        $progress->setMessage("Loading policies..");

        if (!empty($domains)) {
          // Merge domains in with the $uris argument.
          // Omit the "default" key that is present by default.
            $uris = array_merge($domains, ($uris === ['default']) ? [] : $uris);
        }

        $results = [];

        $profile->setReportingPeriod($this->getReportingPeriodStart($input), $this->getReportingPeriodEnd($input));

        $definitions = $profile->getAllPolicyDefinitions();
        $max_steps = $progress->getMaxSteps() + count($definitions) + count($uris);
        $progress->setMaxSteps($max_steps);

        $policies = [];
        foreach ($profile->getAllPolicyDefinitions() as $policyDefinition) {
            $this->getLogger()->debug("Loading policy from definition: " . $policyDefinition->name);
            $policies[] = $policyDefinition->getPolicy($this->getPolicyFactory());
            $progress->advance();
        }
        $progress->advance();

        $uris = empty($uris) ? [null] : $uris;

        $forkManager = $this->getForkManager();

        foreach ($uris as $uri) {
            $forkManager->fork()
            ->run(function (Process $fork) use ($policies, $input, $uri, $profile) {
              $fork->setTitle($input->getArgument('target'));
              $target = $this->getTargetFactory()->create($input->getArgument('target'), $uri);

              $uri = $target->getUri();
              $fork->setTitle($uri);

              $this->getLogger()->info("Evaluating $uri.");
              $assessment = $this->getContainer()->get('assessment')->setUri($uri);
              $assessment->assessTarget($target, $policies, $profile->getReportingPeriodStart(), $profile->getReportingPeriodEnd());
              return $assessment;
            })
            // Write the report to the provided formats.
            ->onSuccess(function (Assessment $a, Process $f) use ($formats, $profile, $input, $console) {
              foreach ($formats as $format) {
                  $format->setNamespace($this->getReportNamespace($input, $f->getTitle()));
                  $format->render($profile, $a);
                  foreach ($format->write() as $written_location) {
                    $console->success("Writen $written_location");
                  }
              }
            })
            ->onError(function ($e, Process $fork) {
              $this->getLogger()->error("Assessment of ".$fork->getTitle()." failed: " . ((string) $e));
            });
        }
        $progress->advance();

        $exit_codes = [0];
        $results = [];
        $assessment_manager = new AssessmentManager();

        foreach ($forkManager->getForkResults() as $assessment) {
            $progress->advance();
            $assessment_manager->addAssessment($assessment);
            $exit_codes[] = $assessment->getSeverityCode();
        }
        $progress->finish();
        $progress->clear();

        if ($input->getOption('report-summary')) {

            $report_filename = strtr($filepath, [
              'uri' => 'multiple_target',
            ]);

            $format->setOptions([
              'content' => $format->loadTwigTemplate('report/profile.multiple_target')
            ]);
            $format->setOutput(($filepath != 'stdout') ? new StreamOutput(fopen($report_filename, 'w')) : $output);
            $format->render($profile, $assessment_manager)->write();

            if ($filepath != 'stdout') {
              $console->success(sprintf("%s report written to %s", $format->getName(), $report_filename));
            }
        }

        // Do not use a non-zero exit code when no severity is set (Default).
        $exit_severity = $input->getOption('exit-on-severity');
        if ($exit_severity === FALSE) {
            return 0;
        }
        $exit_code = max($exit_codes);

        return $exit_code >= $exit_severity ? $exit_code : 0;
    }
}
