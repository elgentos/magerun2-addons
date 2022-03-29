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

class ConfigRedisCommand extends AbstractMagentoCommand
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
    private $useL2Cache;

    protected function configure()
    {
        $this
            ->setName('config:redis')
            ->setDescription('Check and optionally configure the Redis configuration [elgentos]');
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

        // Hypernode specific configuration
        if ($this->isHypernode()) {
            $redisPersistentInstance = trim(shell_exec('hypernode-systemctl settings redis_persistent_instance 2>&1'));
            if (!str_contains($redisPersistentInstance, 'True')) {
                $errors['redis_persistent_instance_is_disabled'] = [
                    'message' => 'Redis persistent instance is disabled',
                    'fix' => 'hypernode-systemctl settings redis_persistent_instance True'
                ];
            }
        }

        $actualEnvSettings = include('app/etc/env.php');
        $actualEnvSettings = new Dot($actualEnvSettings);

        // Fetch database prefix
        $databasePrefix = $actualEnvSettings->get('db/table_prefix', null, '/');

        // Check env settings
        $envSettings = new Dot([
            'session' => [
                'save' => 'redis',
                'redis' => [
                    'host' => 'redis',
                    'password' => '',
                    'timeout' => '2.5',
                    'persistent_identifier' => '',
                    'database' => '2',
                    'compression_threshold' => '2048',
                    'compression_library' => 'gzip',
                    'log_level' => '1',
                    'max_concurrency' => '30',
                    'break_after_frontend' => '5',
                    'break_after_adminhtml' => '30',
                    'first_lifetime' => '600',
                    'bot_first_lifetime' => '60',
                    'bot_lifetime' => '7200',
                    'disable_locking' => '0',
                    'min_lifetime' => '60',
                    'max_lifetime' => '2592000'
                ]
            ],
            'cache' => [
                'frontend' => [
                    'default' => [
                        'id_prefix' => $databasePrefix,
                        'backend' => 'Cm_Cache_Backend_Redis',
                        'backend_options' => [
                            'server' => 'redis',
                            'database' => '0',
                            'compress_data' => '0',
                            'compress_tags' => '0',
                            'force_standalone' => '0',
                            'connect_retries' => '1',
                            'read_timeout' => '10',
                            'compress_threshold' => '20480',
                            'compression_lib' => 'gzip'
                        ]
                    ],
                ],
                'page_cache' => [
                    'id_prefix' => $databasePrefix,
                    'backend' => 'Cm_Cache_Backend_Redis',
                    'backend_options' => [
                        'server' => 'redis',
                        'database' => '1',
                        'compress_data' => '0',
                        'compress_tags' => '0',
                        'force_standalone' => '0',
                        'connect_retries' => '1',
                        'read_timeout' => '10',
                        'compress_threshold' => '20480',
                        'compression_lib' => 'gzip'
                    ]
                ]
            ]
        ]);

        if ($this->useL2Cache()) {
            $l2Caching = new Dot([
                'cache' => [
                    'frontend' => [
                        'default' => [
                            'id_prefix' => $databasePrefix,
                            'backend' => '\\Magento\\Framework\\Cache\\Backend\\RemoteSynchronizedCache',
                            'backend_options' => [
                                'remote_backend' => '\\Magento\\Framework\\Cache\\Backend\\Redis',
                                'remote_backend_options' => [
                                    'persistent' => 0,
                                    'server' => 'redis',
                                    'database' => '0',
                                    'port' => '6379',
                                    'password' => '',
                                    'compress_data' => '1',
                                    'preload_keys' => [
                                        $databasePrefix . 'EAV_ENTITY_TYPES:hash',
                                        $databasePrefix . 'GLOBAL_PLUGIN_LIST:hash',
                                        $databasePrefix . 'DB_IS_UP_TO_DATE:hash',
                                        $databasePrefix . 'SYSTEM_DEFAULT:hash'
                                    ]
                                ],
                                'local_backend' => 'Cm_Cache_Backend_File',
                                'local_backend_options' => [
                                    'cache_dir' => '/dev/shm/'
                                ],
                                'use_stale_cache' => false
                            ],
                            'frontend_options' => [
                                'write_control' => false
                            ]
                        ]
                    ]
                ]
            ]);
            $envSettings->merge($l2Caching, [], '/');
        }

        if ($this->isHypernode()) {
            $envSettings->set('session/redis/host', 'localhost', '/');
            $envSettings->set('cache/page_cache/backend_options/server', 'localhost', '/');
            $envSettings->set('cache/frontend/default/backend_options/server', 'localhost', '/');
            if ($this->useL2Cache()) {
                $envSettings->set('cache/default/backend_options/remote_backend_options/server', 'localhost', '/');
            }
        }

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
            $this->output->writeln('<info>No errors found, your Redis configuration is feeling awesome.</info>');
        }

        return 0;
    }

    private function isHypernode(): bool
    {
        return (bool) `which hypernode-systemctl`;
    }

    /**
     * @return bool
     *
     * https://devdocs.magento.com/guides/v2.4/config-guide/cache/two-level-cache.html
     */
    private function useL2Cache()
    {
        if (is_bool($this->useL2Cache)) {
            return $this->useL2Cache;
        }

        $this->useL2Cache = false;
        $confirmation = new ConfirmationQuestion('<question>Do you want to use the L2 cache? Only recommended when running multiple web nodes using 1 Redis instance. </question> <comment>[y/N]</comment> ', false);
        if (
            version_compare($this->productMetaData->getVersion(), '2.3.5', '>=')
            && $this->questionHelper->ask($this->input, $this->output, $confirmation)
        ) {
            $this->useL2Cache = true;
        }
        return $this->useL2Cache;
    }
}
