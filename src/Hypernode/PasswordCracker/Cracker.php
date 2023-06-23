<?php
/**
 * Byte Hypernode Magerun
 *
 * @package     hypernode-Magerun
 * @author      Byte
 * @copyright   Copyright (c) 2017 Byte
 * @license     http://opensource.org/licenses/osl-3.0.php Open Software License 3.0 (OSL-3.0)
 */

namespace Hypernode\PasswordCracker;

use Composer\DependencyResolver\Request;
use Magento\Framework\Encryption\Encryptor;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class Cracker
 *
 * @package Hypernode\PasswordCracker
 */
class Cracker
{
    /** @var \Iterator */
    protected $words;

    /** @var \Iterator */
    protected $rules;

    /** @var Encryptor */
    protected $encryptor;
    private $output;

    public function __construct($output)
    {
        $this->output = $output;
    }

    /**
     * @param \Iterator $words
     * @return $this
     */
    public function setWords(\Iterator $words)
    {
        $this->words = $words;

        return $this;
    }

    /**
     * @return \Iterator
     */
    public function getWords()
    {
        return $this->words;
    }

    /**
     * THe encryptor class must have a validateHash(string $password, string $hash)
     * method. Can't type hint on an interface because designed for working with
     * existing core Magento classes. But don't want to add Magento as a dependency.
     *
     * @param Encryptor $encryptor
     */
    public function setEncryptor($encryptor)
    {
        $this->encryptor = $encryptor;
    }

    /**
     * @return Encryptor
     */
    public function getEncryptor()
    {
        return $this->encryptor;
    }

    /**
     * @param Credential $credential
     * @return Credential
     */
    public function crack(Credential $credential)
    {
        foreach ($this->getWords() as $word) {
            if (!empty($word)) {
                if ($this->output && $this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
                    $this->output->writeln('Attempting ' . $word);
                }
                if ($this->validateWord($word, $credential->getHash())) {
                    $credential->setPassword($word);
                    break;
                }
            }
        }

        return $credential;
    }

    /**
     * @param $attempt
     * @param $hash
     * @return bool
     */
    public function validateWord($attempt, $hash)
    {
        return $this->getEncryptor()
            ->validateHash($attempt, $hash);
    }
}
