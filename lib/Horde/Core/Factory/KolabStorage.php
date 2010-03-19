<?php
/**
 * A Horde_Injector:: based Horde_Kolab_Storage:: factory.
 *
 * PHP version 5
 *
 * @category Horde
 * @package  Core
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.fsf.org/copyleft/lgpl.html LGPL
 * @link     http://pear.horde.org/index.php?package=Core
 */

/**
 * A Horde_Injector:: based Horde_Kolab_Storage:: factory.
 *
 * Copyright 2009-2010 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (LGPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/lgpl.html.
 *
 * @category Horde
 * @package  Core
 * @author   Gunnar Wrobel <wrobel@pardus.de>
 * @license  http://www.fsf.org/copyleft/lgpl.html LGPL
 * @link     http://pear.horde.org/index.php?package=Core
 */
class Horde_Core_Factory_KolabStorage
{
    /**
     * The injector.
     *
     * @var Horde_Injector
     */
    private $_injector;

    /**
     * Constructor.
     *
     * @param Horde_Injector $injector The injector to use.
     */
    public function __construct(
        Horde_Injector $injector
    ) {
        $this->_injector      = $injector;
        $this->_setup();
    }

    /**
     * Setup the machinery to create Horde_Kolab_Session objects.
     *
     * @return NULL
     */
    private function _setup()
    {
        $this->_setupConfiguration();
    }

    /**
     * Provide configuration settings for Horde_Kolab_Session.
     *
     * @return NULL
     */
    private function _setupConfiguration()
    {
        $configuration = array();

        //@todo: Update configuration parameters
        if (!empty($GLOBALS['conf']['kolab']['imap'])) {
            $configuration = $GLOBALS['conf']['kolab']['imap'];
        }
        if (!empty($GLOBALS['conf']['kolab']['storage'])) {
            $configuration = $GLOBALS['conf']['kolab']['storage'];
        }

        $this->_injector->setInstance(
            'Horde_Kolab_Storage_Configuration', $configuration
        );
    }

    /**
     * Return the Horde_Kolab_Storage:: instance.
     *
     * @return Horde_Kolab_Storage The storage handler.
     */
    public function getStorage()
    {
        $configuration = $this->_injector->getInstance('Horde_Kolab_Storage_Configuration');

        $session = $this->_injector->getInstance('Horde_Kolab_Session');

        $mail = $session->getMail();
        if (empty($mail)) {
            return false;
        }
        $params = array(
            'hostspec' => $session->getImapServer(),
            'username' => Horde_Auth::getAuth(),
            'password' => Horde_Auth::getCredential('password'),
            'secure'   => true
        );

        $master = Horde_Kolab_Storage_Driver::factory(
            'Imap',
            $params
        );

        return new Horde_Kolab_Storage(
            $master,
            'Imap',
            $params
        );
    }
}
