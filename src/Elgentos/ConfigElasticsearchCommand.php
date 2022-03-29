<?php

namespace Elgentos;

use Exception;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Module\Manager;
use N98\Magento\Command\AbstractMagentoCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Process\Process;

if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle !== '' && mb_strpos($haystack, $needle) !== false;
    }
}

class ConfigElasticsearchCommand extends AbstractMagentoCommand
{
    /**
     * @var InputInterface
     */
    protected $input;
    /**
     * @var OutputInterface
     */
    protected $output;
    private Manager $moduleManager;

    protected function configure()
    {
        $this
            ->setName('config:elasticsearch')
            ->setDescription('Check and optionally configure the Elasticsearch configuration [elgentos]');
    }

    /**
     * @param Manager $moduleManager
     * @param ScopeConfigInterface $config
     * @return void
     */
    public function inject(Manager              $moduleManager,
                           ScopeConfigInterface $config)
    {
        $this->moduleManager = $moduleManager;
        $this->config = $config;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|void
     * @throws Exception
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

        // Checks
        $errors = [];
        if (!$this->moduleManager->isEnabled('Magento_Elasticsearch')) {
            $errors['magento_elasticsearch_is_not_enabled'] = [
                'message' => 'Magento_Elasticsearch is not enabled',
                'fix' => 'bin/magento module:enable Magento_Elasticsearch'
            ];
        }

        $elasticVersion = 7; // Default recommended version. When both implementation extensions are enabled, use 7
        $isElastic6Enabled = $this->moduleManager->isEnabled('Magento_Elasticsearch6');
        $isElastic7Enabled = $this->moduleManager->isEnabled('Magento_Elasticsearch7');
        if (
            !$isElastic6Enabled
            && !$isElastic7Enabled
        ) {
            $errors['magento_elasticsearch6or7_is_not_enabled'] = [
                'message' => 'Magento_Elasticsearch6 or Magento_Elasticsearch7 is not enabled - you need at least one of the two',
                'fix' => 'bin/magento module:enable Magento_Elasticsearch7'
            ];
        } else if (
            $isElastic6Enabled
            && !$isElastic7Enabled
        ) {
            $elasticVersion = 6;
        }

        // Hypernode specific configuration
        if ($this->isHypernode()) {
            $elasticSearchEnabled = trim(shell_exec('hypernode-systemctl settings elasticsearch_enabled 2>&1'));
            if (!str_contains($elasticSearchEnabled, 'True')) {
                $errors['elasticsearch_is_disabled'] = [
                    'message' => 'ElasticSearch is disabled',
                    'fix' => 'hypernode-systemctl settings elasticsearch_enabled True'
                ];
            }
            $elasticSearchVersion = trim(shell_exec('hypernode-systemctl settings elasticsearch_version 2>&1'));
            preg_match('/\d/', $elasticSearchVersion, $matches);
            $currentElasticVersion = 0;
            if (isset($matches[0])) {
                $currentElasticVersion = (int) $matches[0];
            }
            if ($currentElasticVersion !== $elasticVersion) {
                $errors['varnish_wrong_version'] = [
                    'message' => sprintf('You are running ElasticSearch version %s, you need version %s.', $currentElasticVersion, $elasticVersion),
                    'fix' => sprintf('hypernode-systemctl settings elasticsearch_version %s.x', $elasticVersion)
                ];
            }
        }

        // Check config settings
        $settings = [
            'catalog/search/elasticsearch%d_server_hostname' => 'elasticsearch%d',
            'catalog/search/elasticsearch%d_server_port' => 9200,
            'catalog/search/elasticsearch%d_index_prefix' => 'magento2',
            'catalog/search/elasticsearch%d_enable_auth' => 0,
            'catalog/search/elasticsearch%d_server_timeout' => 15,
            'catalog/search/engine' => 'elasticsearch%d'
        ];

        if ($this->isHypernode()) {
            $settings['catalog/search/elasticsearch%d_server_hostname'] = 'localhost';
        }

        if ($this->hasElasticsuite()) {
            $settings['catalog/search/engine'] = 'elasticsuite';
            $settings['smile_elasticsuite_core_base_settings/es_client/servers'] = 'elasticsearch%d:9200';
            if ($this->isHypernode()) {
                $settings['smile_elasticsuite_core_base_settings/es_client/servers'] = 'localhost:9200';
            }
        }

        foreach ($settings as $setting => $value) {
            $settingPath = sprintf($setting, $elasticVersion);
            $expectedValue = sprintf($value, $elasticVersion);
            $actualValue = $this->config->getValue($settingPath);
            if ($actualValue !== $expectedValue) {
                $errors[$settingPath . '_incorrect'] = [
                    'message' => sprintf('The value for %s (%s) does not match %s', $settingPath, $actualValue, $expectedValue),
                    'fix' => sprintf('bin/magento config:set %s %s --lock-env', $settingPath, $expectedValue)
                ];
            }
        }

        if (count($errors)) {
            $errorMessages = array_map(function ($error) {
                return '<error>' . $error . '</error>';
            }, array_column($errors, 'message'));
            $this->output->writeln(implode(PHP_EOL, $errorMessages));

            $confirmation = new ConfirmationQuestion('<question>We can try to automatically fix these errors. Is that okay? </question> <comment>[Y/n]</comment> ', true);
            if ($questionHelper->ask($input, $output, $confirmation)) {
                foreach ($errors as $key => $error) {
                    $this->output->writeln(sprintf('Attempting to fix error ID %s by running %s', $key, $error['fix'])) ;
                    $process = new Process(explode(' ', $error['fix']));
                    $process->run();
                    if (!$process->isSuccessful()) {
                        $this->output->writeln('<error>' . $process->getOutput() . '</error>');
                    } else {
                        $this->output->writeln('<info>' . $process->getOutput() . '</info>');
                    }
                }
            }
        } else {
            $this->output->writeln('<info>No errors found, your Elasticsearch configuration is feeling awesome.</info>');
        }

        return 0;
    }

    private function isHypernode(): bool
    {
        return (bool) `which hypernode-systemctl`;
    }

    private function hasElasticsuite()
    {
        return $this->moduleManager->isEnabled('Smile_ElasticsuiteCore');
    }
}
