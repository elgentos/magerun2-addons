<?php

namespace Elgentos;

use Hypernode\PasswordCracker\Credential;

/**
 * Class AdminPasswordCommand
 * @package Hypernode\Magento\Command\Hypernode\Crack
 */
class AdminPasswordCommand extends AbstractCrackCommand
{
    protected function configure()
    {
        $this
            ->setName('elgentos:crack:admin-passwords')
            ->setDescription('Attempt to crack admin credentials');

        parent::configure();
    }

    /**
     * @return array
     */
    protected function getCredentials()
    {
        $admins = $this->getAdmins();
        $credentials = array();
        foreach ($admins as $admin) {
            $credentials[] = new Credential($admin->getPassword(), $admin->getUsername());
        }

        return $credentials;
    }

    /**
     * @return mixed
     */
    protected function getAdmins()
    {
        $admins = $this->adminUserCollection->getItems();
        $this->applyUserFilter($admins);
        $this->applyStatusFilter($admins);

        return $admins;
    }
}
