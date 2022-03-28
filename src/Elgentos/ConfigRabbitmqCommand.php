<?php

namespace Elgentos;

use Elgentos\Dot;
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

class ConfigRabbitmqCommand extends AbstractMagentoCommand
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
            ->setName('config:rabbitmq')
            ->setDescription('Check and optionally configure the RabbitMQ configuration [elgentos]');
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
        $modules = [
            'Magento_Amqp',
            'Magento_AmqpStore',
            'Magento_MessageQueue',
            'Magento_Webapi',
            'Magento_WebapiAsync',
            'Magento_MysqlMq'
        ];
        foreach ($modules as $module) {
            if (!$this->moduleManager->isEnabled($module)) {
                $errors[strtolower($module) . '_is_not_enabled'] = [
                    'message' => $module . ' is not enabled',
                    'fix' => 'bin/magento module:enable ' . $module
                ];
            }
        }

        // Hypernode specific configuration
        // Find out how to fetch the process result. It looks like it's async, can't get it
        // through Symfony/Process or shell_exec() or exec()
        if ($this->isHypernode()) {
            $this->output->writeln('<comment>Make sure Rabbitmq is enabled on this Hypernode. The current setting is:</comment>');
            shell_exec('hypernode-systemctl settings rabbitmq_enabled');
            $this->output->writeln('<comment>If Rabbitmq is disabled, please run hypernode-systemctl settings rabbitmq_enabled True</comment>');
        }

        // Check env settings
        $envSettings = new Dot([
            'lock' => [
                'provider' => 'file',
                'config' => [
                    'path' => 'var/queue_lock'
                ]
            ],
            'cron_consumers_runner' => [
                'cron_run' => true,
                'max_messages' => 1000,
                'consumers' => []
            ],
            'queue' => [
                'amqp' => [
                    'host' => 'rabbitmq',
                    'port' => '5672',
                    'user' => 'guest',
                    'password' => 'guest',
                    'virtualhost' => '/'
                ],
                'consumers_wait_for_messages' => 0,
            ]
        ]);

        if ($this->isHypernode()) {
            $envSettings->set('lock.config.path', '/data/web/shared/var/queue_lock');
            $envSettings->set('queue.amqp.host', 'localhost');
        }

        $actualEnvSettings = include('app/etc/env.php');
        $actualEnvSettings = new Dot($actualEnvSettings);

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

            $confirmation = new ConfirmationQuestion('<question>We can try to automatically fix these errors by running the following commands. Is that okay? </question> <comment>[Y/n]</comment> ', true);
            if ($questionHelper->ask($input, $output, $confirmation)) {
                foreach ($errors as $key => $error) {
                    if (isset($error['fix'])) {
                        $this->output->writeln(sprintf('Attempting to fix error ID %s by running %s', $key, $error['fix']));
                        $process = new Process(explode(' ', $error['fix']));
                        $process->run();
                        if (!$process->isSuccessful()) {
                            $this->output->writeln('<error>' . $process->getOutput() . '</error>');
                        } else {
                            $this->output->writeln('<info>' . $process->getOutput() . '</info>');
                        }
                    }
                }
                $this->output->writeln('Fixing settings in env.php');
                $actualEnvSettings->set($envSettings->all(), null, '/');
                file_put_contents('app/etc/env.php', '<?php return ' . $this->var_export($actualEnvSettings->all(), true) . ';');
                $confirmation = new ConfirmationQuestion('<question>Do you want to run bin/magento app:config:import now? </question> <comment>[Y/n]</comment> ', true);
                if ($questionHelper->ask($input, $output, $confirmation)) {
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
            $this->output->writeln('<info>No errors found, your RabbitMQ configuration is feeling awesome.</info>');
        }

        $confirmation = new ConfirmationQuestion('<question>Do you want to run the update attributes consumers through RabbitMQ instead of Mysql? </question> <comment>[Y/n]</comment> ', true);
        if ($questionHelper->ask($input, $output, $confirmation)) {
            $consumers = [
                'product_action_attribute.update',
                'product_action_attribute.website.update',
            ];

            $updateAttributesAmqp = new Dot([]);
            foreach ($consumers as $consumer) {
                $updateAttributesAmqp->set(sprintf('queue/topics/%s/publisher', $consumer), 'amqp-magento', '/');
                $updateAttributesAmqp->set(sprintf('queue/config/publishers/%s/connections/amqp/name', $consumer), 'amqp', '/');
                $updateAttributesAmqp->set(sprintf('queue/config/publishers/%s/connections/amqp/exchange', $consumer), 'magento', '/');
                $updateAttributesAmqp->set(sprintf('queue/config/publishers/%s/connections/amqp/disabled', $consumer), false, '/');
                $updateAttributesAmqp->set(sprintf('queue/config/publishers/%s/connections/db/name', $consumer), 'db', '/');
                $updateAttributesAmqp->set(sprintf('queue/config/publishers/%s/connections/db/disabled', $consumer), true, '/');
                $updateAttributesAmqp->set(sprintf('queue/consumers/%s/connection', $consumer), 'amqp', '/');
            }

            $array1 = $actualEnvSettings->all();
            $array2 = $updateAttributesAmqp->all();
            $actualEnvSettings = $this->array_merge_recursive_distinct( $array1, $array2);
            file_put_contents('app/etc/env.php', '<?php return ' . $this->var_export($actualEnvSettings, true) . ';');
        }

        return 0;
    }

    private function isHypernode(): bool
    {
        return (bool) `which hypernode-systemctl`;
    }

    private function var_export($expression, $return=FALSE) {
        $export = var_export($expression, TRUE);
        $patterns = [
            "/array \(/" => '[',
            "/^([ ]*)\)(,?)$/m" => '$1]$2',
            "/=>[ ]?\n[ ]+\[/" => '=> [',
            "/([ ]*)(\'[^\']+\') => ([\[\'])/" => '$1$2 => $3',
        ];
        $export = preg_replace(array_keys($patterns), array_values($patterns), $export);
        if ((bool)$return) return $export; else echo $export;
    }

    private function array_merge_recursive_distinct(array &$array1, array &$array2)
    {
        $merged = $array1;

        foreach ($array2 as $key => &$value) {
            if (is_array($value) && isset ($merged [$key]) && is_array($merged [$key])) {
                $merged [$key] = $this->array_merge_recursive_distinct($merged [$key], $value);
            } else {
                $merged [$key] = $value;
            }
        }

        return $merged;
    }
}
