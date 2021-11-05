<?php

namespace Elgentos;

use Elgentos\Dot;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;
use N98\Magento\Command\AbstractMagentoCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class GenerateXdebugSkipFilters extends AbstractMagentoCommand
{
    /**
     * @var InputInterface
     */
    protected $input;
    /**
     * @var OutputInterface
     */
    protected $output;

    protected function configure()
    {
      $this
          ->setName('generate:xdebug-skip-filters')
          ->setDescription('Generate the Xdebug Skip Filter configuration [elgentos]')
      ;
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int|void
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $questionHelper = $this->getHelperSet()->get('question');

        $this->detectMagento($output);
        if (!$this->initMagento()) {
            return 0;
        }

        if (!file_exists('.idea/php.xml')) {
            $output->writeln('<error>.idea/php.xml file not found - please open project in PhpStorm');
            return 1;
        }

        $confirmation = new ConfirmationQuestion('<question>This will append data to your .idea/php.xml file. Are you sure?</question> <comment>[Y/n]</comment> ', true);
        if (!$questionHelper->ask($input, $output, $confirmation)) {
            return 0;
        }

        $confirmation = new ConfirmationQuestion('<question>In order for this to work, you will need to run bin/magento setup:di:compile to generate the interceptors and proxies first. Do you want to run it now?</question> <comment>[y/N]</comment> ', false);
        if ($questionHelper->ask($input, $output, $confirmation)) {
            $command = $this->getApplication()->find('setup:di:compile');
            $command->run($input, $output);
        }

        $ideaPhp = new \SimpleXMLElement(file_get_contents('.idea/php.xml'));
        $i = $stepFilterConfig = 0;
        foreach ($ideaPhp->component as $component) {
            if ((string)$component->attributes()->name === 'PhpStepFilterConfiguration') {
                $stepFilterConfig = $component;
                break;
            }
        }
        if (!$stepFilterConfig) {
            $stepFilterConfig = $ideaPhp->addChild('component');
            $stepFilterConfig->addAttribute('name', 'PhpStepFilterConfiguration');
        }
        $skippedFiles = $stepFilterConfig->addChild('skipped_files');

        $interceptors = array_filter(explode(PHP_EOL, shell_exec('find generated -type f')), static function ($file) {
            return stripos($file, 'Interceptor');
        });

        $confirmation = new ConfirmationQuestion('<question>Do you want to skip Interceptors in Xdebug stepping (' . count($interceptors) . ' proxies found)?</question> <comment>[Y/n]</comment> ', true);
        if ($questionHelper->ask($input, $output, $confirmation)) {
            foreach ($interceptors as $interceptor) {
                $skippedFiles->addChild('skipped_file')->addAttribute('file', '$PROJECT_DIR$/' . $interceptor);
                $i++;
            }
        }

        $proxies = array_filter(explode(PHP_EOL, shell_exec('find generated -type f')), static function ($file) {
            return stripos($file, 'Proxy');
        });

        $confirmation = new ConfirmationQuestion('<question>Do you want to skip Proxies in Xdebug stepping (' . count($proxies) . ' proxies found)?</question> <comment>[Y/n]</comment> ', true);
        if ($questionHelper->ask($input, $output, $confirmation)) {
            foreach ($proxies as $proxy) {
                $skippedFiles->addChild('skipped_file')->addAttribute('file', '$PROJECT_DIR$/' . $proxy);
                $i++;
            }
        }

        $xmlDocument = new \DOMDocument('1.0');
        $xmlDocument->preserveWhiteSpace = false;
        $xmlDocument->formatOutput = true;
        $xmlDocument->loadXML($ideaPhp->asXML());
        file_put_contents('.idea/php.xml', $xmlDocument->saveXML());

        $output->writeln('<info>Wrote ' . $i . ' filenames to the PHP Debug Step Filter configuration</info>');
    }


}
