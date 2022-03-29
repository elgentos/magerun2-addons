<?php

namespace Elgentos;

use Elgentos\Dot;
use Brick\VarExporter\VarExporter;
use Exception;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Module\Manager;
use N98\Magento\Command\AbstractMagentoCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Process\Process;

class ConfigVarnishCommand extends AbstractMagentoCommand
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
    private ProductMetadataInterface $productMetaData;
    private ScopeConfigInterface $config;
    private $questionHelper;

    protected function configure()
    {
        $this
            ->setName('config:varnish')
            ->setDescription('Check and optionally configure the Varnish configuration [elgentos]');
    }

    /**
     * @param Manager $moduleManager
     * @param ProductMetadataInterface $productMetadata
     * @param ScopeConfigInterface $config
     * @return void
     */
    public function inject(Manager $moduleManager, ProductMetadataInterface $productMetadata, ScopeConfigInterface $config)
    {
        $this->moduleManager = $moduleManager;
        $this->productMetaData = $productMetadata;
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
        $this->questionHelper = $this->getHelperSet()->get('question');

        $this->detectMagento($output);
        if (!$this->initMagento()) {
            return 0;
        }

        // Checks
        $errors = [];
        if (!$this->moduleManager->isEnabled('Magento_PageCache')) {
            $errors['magento_pagecache_is_not_enabled'] = [
                'message' => 'Magento_PageCache is not enabled',
                'fix' => 'bin/magento module:enable Magento_PageCache'
            ];
        }

        // Hypernode specific configuration
        if ($this->isHypernode()) {
            $varnishEnabled = trim(shell_exec('hypernode-systemctl settings varnish_enabled 2>&1'));
            if (!str_contains($varnishEnabled, 'True')) {
                $errors['varnish_is_disabled'] = [
                    'message' => 'Varnish is disabled',
                    'fix' => 'hypernode-systemctl settings varnish_enabled True'
                ];
            }
            $varnishVersion = trim(shell_exec('hypernode-systemctl settings varnish_version 2>&1'));
            preg_match('/\d/', $varnishVersion, $matches);
            $currentVarnishVersion = 0;
            if (isset($matches[0])) {
                $currentVarnishVersion = (int) $matches[0];
            }
            if ($currentVarnishVersion !== $varnishVersion) {
                $errors['varnish_wrong_version'] = [
                    'message' => sprintf('You are running Varnish version %s, you need version %s.', $currentVarnishVersion, $varnishVersion),
                    'fix' => sprintf('hypernode-systemctl settings varnish_version %s.x', $varnishVersion)
                ];
            }

            // Check vhosts
            $vhosts = json_decode(shell_exec('hypernode-manage-vhosts --list --format=json'), true);
            $stores = json_decode(shell_exec('magerun2 sys:store:config:base-url:list --format=json'), true);
            foreach ($stores as $store) {
                $parsedUrl = parse_url($store['secure_baseurl']);
                foreach ($vhosts as $domain => $vhost) {
                    if ($parsedUrl['host'] === $domain && !$vhost['varnish']) {
                        $errors['vhost_' . $domain . '_not_configured_for_varnish'] = [
                            'message' => 'The vhost ' . $domain . ' is not configured for Varnish',
                            'fix' => 'hypernode-manage-vhosts ' . $domain . ' --varnish'
                        ];
                    }
                }
            }
        }

        if ($this->isHypernode()) {
            $confirmation = new ConfirmationQuestion('<question>Do you want to generate & activate the VCL? </question> <comment>[Y/n]</comment> ', true);
            if ($this->questionHelper->ask($input, $output, $confirmation)) {
                // Generate and activate VCL
                shell_exec('bin/magento varnish:vcl:generate > /data/web/varnish.vcl');
                shell_exec('sed -i \'11,17d\' /data/web/varnish.vcl'); // Remove probe
                shell_exec('varnishadm vcl.load mag2 /data/web/varnish.vcl');
                shell_exec('varnishadm vcl.use mag2');
                shell_exec('varnishadm vcl.discard boot');
                shell_exec('varnishadm vcl.discard hypernode');
            }
        }

        $actualEnvSettings = include('app/etc/env.php');
        $actualEnvSettings = new Dot($actualEnvSettings);

        // Check env settings
        $envSettings = new Dot([
            'system/default/full_page_cache/caching_application' => '2',
            'system/default/system/full_page_cache/varnish/access_list' => 'localhost',
            'system/default/system/full_page_cache/varnish/backend_host' => 'localhost',
            'system/default/system/full_page_cache/varnish/backend_port' => '8080',
            'system/default/system/full_page_cache/varnish/grace_period' => '300',
            'http_cache_hosts' => ['host' => 'localhost', 'port' => '6081'],
        ]);

        foreach ($envSettings->flatten('/') as $settingPath => $expectedValue) {
            $actualValue = $actualEnvSettings->get($settingPath, null, '/');
            if ($actualValue !== $expectedValue) {
                if (is_array($expectedValue)) {
                    $expectedValue = serialize($expectedValue);
                    $actualValue = serialize($actualValue);
                }
                $errors[$settingPath . '_incorrect'] = [
                    'message' => sprintf('The value for %s (%s) does not match %s', $settingPath, $actualValue, $expectedValue)
                ];
            }
        }

        if (count($errors)) {
            $errorMessages = array_map(function ($error) {
                return '<error>' . $error . '</error>';
            }, array_column($errors, 'message'));
            $this->output->writeln(implode(PHP_EOL, $errorMessages));

            $confirmation = new ConfirmationQuestion('<question>We can try to automatically fix these errors. Is that okay? </question> <comment>[Y/n]</comment> ', true);
            if ($this->questionHelper->ask($input, $output, $confirmation)) {
                $this->output->writeln('Fixing settings in env.php');
                $actualEnvSettings->set($envSettings->all(), null, '/');
                file_put_contents('app/etc/env.php', '<?php return ' . VarExporter::export($actualEnvSettings->all()) . ';');
                $confirmation = new ConfirmationQuestion('<question>Do you want to run bin/magento app:config:import now? </question> <comment>[Y/n]</comment> ', true);
                if ($this->questionHelper->ask($input, $output, $confirmation)) {
                    $process = new Process(['bin/magento', 'app:config:import']);
                    $process->run();
                    if (!$process->isSuccessful()) {
                        $this->output->writeln('<error>' . $process->getOutput() . '</error>');
                    } else {
                        $this->output->writeln('<info>' . $process->getOutput() . '</info>');
                    }
                }
            }
        } else {
            $this->output->writeln('<info>No errors found, your Varnish configuration is feeling awesome.</info>');
        }

        return 0;
    }

    private function isHypernode(): bool
    {
        return (bool) `which hypernode-systemctl`;
    }

}
