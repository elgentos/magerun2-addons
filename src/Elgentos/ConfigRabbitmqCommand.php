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
            'Magento_WebapiAsync'
        ];
        foreach ($modules as $module) {
            if (!$this->moduleManager->isEnabled($module)) {
                $errors[strtolower($module) . '_is_not_enabled'] = [
                    'message' => $module . ' is not enabled',
                    'fix' => 'bin/magento module:enable ' . $module
                ];
            }
        }

        if ($this->moduleManager->isEnabled('Magento_MysqlMq')) {
            $errors['magento_mysqlmq_is_enabled'] = [
                'message' => 'Magento_MysqlMq is enabled',
                'fix' => 'bin/magento module:disable Magento_MysqlMq'
            ];
        }

        // Hypernode specific configuration
        // Find out how to fetch the process result. It looks like it's async, can't get it
        // through Symfony/Process or shell_exec() or exec()
        if ($this->isHypernode()) {
            $this->output->writeln('<comment>Make sure Rabbitmq is enabled on this Hypernode. The current setting is:</comment>');
            shell_exec('hypernode-systemctl settings rabbitmq_enabled');
            $this->output->writeln('<comment>If Rabbitmq is disabled, please run hypernode-systemctl settings rabbitmq_enabled True</comment>');
        }

        // Check config settings
//        $settings = [
//
//        ];
//
//        if ($this->isHypernode()) {
//
//        }
//
//        foreach ($settings as $setting => $value) {
//            $settingPath = sprintf($setting, $elasticVersion);
//            $expectedValue = sprintf($value, $elasticVersion);
//            $actualValue = $this->config->getValue($settingPath);
//            if ($actualValue !== $expectedValue) {
//                $errors[$settingPath . '_incorrect'] = [
//                    'message' => sprintf('The value for %s (%s) does not match %s', $settingPath, $actualValue, $expectedValue),
//                    'fix' => sprintf('bin/magento config:set %s %s --lock-env', $settingPath, $expectedValue)
//                ];
//            }
//        }

        if (count($errors)) {
            $errorMessages = array_map(function ($error) {
                return '<error>' . $error . '</error>';
            }, array_column($errors, 'message'));
            $this->output->writeln(implode(PHP_EOL, $errorMessages));

            $confirmation = new ConfirmationQuestion('<question>We can try to automatically fix these errors by running the following commands. Is that okay? </question> <comment>[Y/n]</comment> ', true);
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
            $this->output->writeln('<info>No errors found, your RabbitMQ configuration is feeling awesome.</info>');
        }

        return 0;
    }

    private function isHypernode(): bool
    {
        return (bool) `which hypernode-systemctl`;
    }
}
