<?php

namespace Drutiny\Console\Command;

use Drutiny\Attribute\AsSource;
use Drutiny\PolicyFactory;
use Drutiny\PolicySource\PolicySourceInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Drutiny\PolicySource\PushablePolicySourceInterface;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Exception\InvalidArgumentException;

/**
 *
 */
class PolicyPushCommand extends DrutinyBaseCommand
{
    use LanguageCommandTrait;

    public function __construct(
      protected PolicyFactory $policyFactory,
      protected LoggerInterface $logger
    )
    {
      parent::__construct();
    }
  /**
   * @inheritdoc
   */
    protected function configure()
    {
        $this
        ->setName('policy:push')
        ->setDescription('Push a policy to a policy source.')
        ->addArgument(
          'policy',
          InputArgument::REQUIRED,
          'The name of the policy to push.'
        )
        ->addArgument(
            'source',
            InputArgument::OPTIONAL,
            'The name of the source to push too.'
        );
    }

  /**
   * @inheritdoc
   */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $source = $this->getPushSource($input, $output);

        $io = new SymfonyStyle($input, $output);

        if (!($source instanceof PushablePolicySourceInterface)) {
          $io->error('Source does not support policy push');
          return 1;
        }
        $policy = $this->policyFactory->loadPolicyByName($input->getArgument('policy'));

        try {
          $policy = $source->push($policy);
        }
        catch (IdentityProviderException $e)
        {
          $this->logger->error(get_class($e));
          $this->logger->error($e->getMessage());
          return 2;
        }

        $io->success(sprintf('Policy %s successfully pushed to %s. Visit %s',
          $input->getArgument('policy'),
          $input->getArgument('source'),
          $policy->uri
        ));

        return 0;
    }

    /**
     * Find an appropriate source to push too.
     */
    protected function getPushSource(InputInterface $input, OutputInterface $output):PushablePolicySourceInterface
    {
      $io = new SymfonyStyle($input, $output);
      if ($source_name = $input->getArgument('source')) {
        return $this->policyFactory->getSource($input->getArgument('source'));
      }
      $sources = array_filter($this->policyFactory->sources, function (AsSource $source) {
        return $this->policyFactory->getSource($source->name) instanceof PushablePolicySourceInterface;
      });
      if (count($sources) == 1) {
        $name = array_shift($sources)->name;
        if ($io->confirm("Push policy to source '$name'?")) {
          return $this->policyFactory->getSource($name);
        }
        throw new InvalidArgumentException("There are no pushable sources to push policies too.");
      }
      if (count($sources) == 0) {
        throw new InvalidArgumentException("There are no pushable sources to push policies too.");
      }
      $choice = $io->choice("Which source would you like to push to?", array_keys($sources));
      return $this->policyFactory->getSource($sources[$choice]);
    }
}
