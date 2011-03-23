<?php
/**
 * A Horde_Injector:: based Horde_Auth:: factory.
 *
 * PHP version 5
 *
 * @category Horde
 * @package  Core
 * @author   Michael Slusarz <slusarz@horde.org>
 * @license  http://www.fsf.org/copyleft/lgpl.html LGPL
 * @link     http://pear.horde.org/index.php?package=Core
 */

/**
 * A Horde_Injector:: based Horde_Auth:: factory.
 *
 * Copyright 2010-2011 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (LGPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/lgpl.html.
 *
 * @category Horde
 * @package  Core
 * @author   Michael Slusarz <slusarz@horde.org>
 * @license  http://www.fsf.org/copyleft/lgpl.html LGPL
 * @link     http://pear.horde.org/index.php?package=Core
 */
class Horde_Core_Factory_Auth extends Horde_Core_Factory_Base
{
    /**
     * Singleton instances.
     *
     * @var array
     */
    private $_instances = array();

    /**
     * Return the Horde_Auth:: instance.
     *
     * @param string $app  The application to authenticate to.
     *
     * @return Horde_Auth_Base  The singleton instance.
     * @throws Horde_Auth_Exception
     */
    public function create($app = null)
    {
        if (is_null($app)) {
            $app = 'horde';
        }

        if (isset($this->_instances[$app])) {
            return $this->_instances[$app];
        }

        $base_params = array(
            'app' => $app,
            'logger' => $this->_injector->getInstance('Horde_Log_Logger')
        );

        if ($app == 'horde') {
            $base_params['base'] = $this->_create(
                $GLOBALS['conf']['auth']['driver'],
                Horde::getDriverConfig('auth', $driver)
            );
        }

        $this->_instances[$app] = Horde_Auth::factory(
            'Horde_Core_Auth_Application',
            $base_params
        );

        return $this->_instances[$app];
    }

    /**
     * Returns a Horde_Auth_Base driver for the given driver/configuration.
     *
     * @param string $driver  Driver name.
     * @param array $params   Driver parameters.
     *
     * @return Horde_Auth_Base  Authentication object.
     */
    protected function _create($driver, array $params)
    {
        /* Get proper driver name now that we have grabbed the
         * configuration. */
        if (strcasecmp($driver, 'application') === 0) {
            $driver = 'Horde_Core_Auth_Application';
        } elseif (strcasecmp($driver, 'httpremote') === 0) {
            /* BC */
            $driver = 'Http_Remote';
        } elseif (strcasecmp($driver, 'ldap') === 0) {
            $driver = 'Horde_Core_Auth_Ldap';
        } elseif (strcasecmp($driver, 'msad') === 0) {
            $driver = 'Horde_Core_Auth_Msad';
        } elseif (strcasecmp($driver, 'shibboleth') === 0) {
            $driver = 'Horde_Core_Auth_Shibboleth';
        } elseif (strcasecmp($driver, 'imsp') === 0) {
            $driver = 'Horde_Core_Auth_Imsp';
        } else {
            $driver = Horde_String::ucfirst(Horde_String::lower(basename($driver)));
        }

        $lc_driver = Horde_String::lower($driver);
        switch ($lc_driver) {
        case 'composite':
            $params['admin_driver'] = $this->_create($params['admin_driver']['driver'], $params['admin_driver']['params']);
            $params['auth_driver'] = $this->_create($params['auth_driver']['driver'], $params['auth_driver']['params']);
            break;

        case 'cyrsql':
        case 'cyrus':
            $imap_config = array(
                'hostspec' => empty($params['hostspec']) ? null : $params['hostspec'],
                'password' => $params['cyrpass'],
                'port' => empty($params['port']) ? null : $params['port'],
                'secure' => ($params['secure'] == 'none') ? null : $params['secure'],
                'username' => $params['cyradmin']
            );

            try {
                $ob = Horde_Imap_Client::factory('Socket', $imap_config);
                $ob->login();
                $params['imap'] = $ob;
            } catch (Horde_Imap_Client_Exception $e) {
                throw new Horde_Auth_Exception($e);
            }

            if ($lc_driver == 'cyrus') {
                $params['backend'] = $this->getOb($params['backend']['driver'], $params['backend']['params']);
            }

            $params['charset'] = 'UTF-8';
            break;

        case 'http_remote':
            $params['client'] = $this->_injector->getInstance('Horde_Core_Factory_HttpClient')->create();
            break;

        case 'imap':
            $params['charset'] = 'UTF-8';
            break;

        case 'horde_core_auth_imsp':
            $params['imsp'] = $this->_injector->getInstance('Horde_Core_Factory_Imsp')->create();
            break;

        case 'kolab':
            $params['kolab'] = $this->_injector
                ->getInstance('Horde_Kolab_Session');
            break;

        case 'horde_core_auth_ldap':
        case 'horde_core_auth_msad':
            $params['ldap'] = $this->_injector
                ->getInstance('Horde_Core_Factory_Ldap')
                ->create('horde', 'auth');
            break;

        case 'customsql':
        case 'sql':
            if (!empty($params['driverconfig']) &&
                $params['driverconfig'] == 'horde') {
                $params['db'] = $this->_injector
                    ->getInstance('Horde_Db_Adapter');
            } else {
                $params['db'] = $this->_injector
                    ->getInstance('Horde_Core_Factory_Db')
                    ->create('horde', 'auth');
            }
            break;
        }

        $params['default_user'] = $GLOBALS['registry']->getAuth();
        $params['logger'] = $this->_injector->getInstance('Horde_Log_Logger');

        $auth_ob = Horde_Auth::factory($driver, $params);
        if ($driver == 'Horde_Core_Auth_Application') {
            $this->_instances[$params['app']] = $auth_ob;
        }

        return $auth_ob;
    }

}
