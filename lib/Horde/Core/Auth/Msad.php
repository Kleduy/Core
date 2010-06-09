<?php
/**
 * The Horde_Core_Auth_Msad class provides Horde-specific code that
 * extends the base LDAP driver.
 *
 * Copyright 2010 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (LGPL). If you did
 * not receive this file, see http://opensource.org/licenses/lgpl-2.1.php
 *
 * @author   Michael Slusarz <slusarz@horde.org>
 * @category Horde
 * @license  http://opensource.org/licenses/lgpl-2.1.php LGPL
 * @package  Core
 */
class Horde_Core_Auth_Msad extends Horde_Auth_Msad
{
    /**
     * Add a set of authentication credentials.
     *
     * @param string $userId      The user ID to add.
     * @param array $credentials  The credentials to use.
     *
     * @throws Horde_Auth_Exception
     */
    public function addUser($userId, $credentials)
    {
        list($userId, $credentials) = $GLOBALS['injector']->getInstance('Horde_Auth')->getAuth()->runHook($userId, $credentials, 'preauthenticate', 'admin');

        parent::addUser($userId, $credentials);
    }

    /**
     * Update a set of authentication credentials.
     *
     * @param string $oldID       The old user ID.
     * @param string $newID       The new user ID.
     * @param array $credentials  The new credentials
     *
     * @throws Horde_Auth_Exception
     */
    public function updateUser($oldID, $newID, $credentials)
    {
        list($oldId, $credentials) = $GLOBALS['injector']->getInstance('Horde_Auth')->getAuth()->runHook($oldId, $credentials, 'preauthenticate', 'admin');

        parent::updateUser($oldID, $newID, $credentials);
    }

    /**
     * Delete a set of authentication credentials.
     *
     * @param string $userId  The user ID to delete.
     *
     * @throws Horde_Auth_Exception
     */
    public function removeUser($userId)
    {
        list($userId, $credentials) = $GLOBALS['injector']->getInstance('Horde_Auth')->getAuth()->runHook($userId, array(), 'preauthenticate', 'admin');

        parent::removeUser($userId, isset($credentials['ldap']) ? $credentials['ldap']['dn'] : null);
    }

}
