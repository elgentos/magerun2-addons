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

class EnvCreateCommand extends AbstractMagentoCommand
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
          ->setName('env:create')
          ->setDescription('Create env file interactively [elgentos]')
      ;
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int|void
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

        $updateEnvQuestion = new ConfirmationQuestion('<question>env file found. Do you want to update it?</question> <comment>[Y/n]</comment> ', true);
        if (file_exists('app/etc/env.php') && $questionHelper->ask($input, $output, $updateEnvQuestion)) {
            $env = include('app/etc/env.php');
        } else {
            $env = include(__DIR__.'/../stubs/env.php');
        }
        $env = new Dot($env);

        $iterator = new RecursiveIteratorIterator(new RecursiveArrayIterator($env->all()), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $default) {
            if (!$iterator->hasChildren()) {
                for ($p = array(), $i = 0, $z = $iterator->getDepth(); $i <= $z; $i++) {
                    $p[] = $iterator->getSubIterator($i)->key();
                }
                $path = implode('.', $p);
                $default = $this->getDefaultValue($path, $default);
                $question = new Question('<question>' . $path . '</question> <comment>[' . $default . ']</comment> ', $default);
                $newValue = $questionHelper->ask($input, $output, $question);
                $env->set($path, $newValue);
            }
        }

        if (!file_exists('app/etc')) {
            mkdir('app/etc', 0777, true);
        }
        file_put_contents('app/etc/env.php', "<?php\n\nreturn ".$this->var_export($env->all()).";\n");
    }

    /**
     * @param string $path
     * @param $default
     * @return false|string
     */
    private function getDefaultValue(string $path, $default)
    {
        if ($path === 'install.date' && empty($default)) {
            return date('D, d M Y H:i:s T');
        }

        return $default;
    }

    /**
     * @param $var
     * @param string $indent
     * @return string|null
     */
    private function var_export($var, $indent='') {
        switch (gettype($var)) {
            case 'string':
                return '"' . addcslashes($var, "\\\$\"\r\n\t\v\f") . '"';
            case 'array':
                $indexed = array_keys($var) === range(0, count($var) - 1);
                $r = [];
                foreach ($var as $key => $value) {
                    $r[] = $indent.'    '
                        . ($indexed ? '' : $this->var_export($key) . ' => ')
                        . $this->var_export($value, $indent.'    ');
                }
                return "[\n" . implode(",\n", $r) . "\n" . $indent . "]";
            case 'boolean':
                return $var ? 'TRUE' : 'FALSE';
            default:
                return var_export($var, TRUE);
        }
    }


}
