<?php

namespace Drutiny\Console\Command;

use Drutiny\Settings;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 *
 */
class InfoCommand extends DrutinyBaseCommand
{
    public function __construct(
        protected Settings $settings
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
        ->setName('info')
        ->setDescription('Show build information');
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        $project_dir = $this->settings->get('project_dir');
        $name = $this->settings->get('name');
        $composer_lock = $project_dir . '/composer.lock';
        if (!file_exists($composer_lock)) {
            $io->error("Could not find dependency information.");
            return self::ERROR;
        }
        $composer_info = json_decode(file_get_contents($composer_lock), true);

        $io->info("$name is built using the following packages.");

        $io->table(['Package', 'Version', 'License', 'Authors'], array_map(function ($package) {
            $authors = array_map(
                fn (array $a) => $a['name'],
                $package['authors'] ?? []
            );

            $authors = implode(', ', $authors);

            return [$package['name'], $package['version'], implode(', ', $package['license'] ?? []), $authors];
        }, $composer_info['packages']));

        return 0;
    }
}
