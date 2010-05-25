<?php
/**
 * The SQL implementation of Horde_Core_Auth_Signup.
 *
 * Copyright 2008-2010 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (LGPL). If you did
 * not receive this file, see http://opensource.org/licenses/lgpl-2.1.php
 *
 * @author   Duck <duck@obala.net>
 * @category Horde
 * @license  http://opensource.org/licenses/lgpl-2.1.php LGPL
 * @package  Core
 */
class Horde_Core_Auth_Signup_Sql extends Horde_Core_Auth_Signup_Base
{
    /**
     * Configuration parameters.
     *
     * @var array
     */
    protected $_params = array();

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->_params = array_merge($this->_params, array(
            'table' => 'horde_signups'
        ), Horde::getDriverConfig('signup', 'Sql'));
    }

    /**
     * Stores the signup data in the backend.
     *
     * @params Horde_Core_Auth_Signup_SqlObject $signup  Signup data.
     *
     * @throws Horde_Db_Exception
     */
    protected function _queueSignup($signup)
    {
        $query = 'INSERT INTO ' . $this->_params['table']
            . ' (user_name, signup_date, signup_host, signup_data) VALUES (?, ?, ?, ?) ';
        $values = array(
            $signup->getName(),
            time(),
            $_SERVER['REMOTE_ADDR'],
            serialize($signup->getData())
        );

        $GLOBALS['injector']->getInstance('Horde_Db_Adapter_Base')->insert($query, $values);
    }

    /**
     * Checks if a user exists in the system.
     *
     * @param string $user  The user to check.
     *
     * @return boolean  True if the user exists.
     * @throws Horde_Db_Exception
     */
    public function exists($user)
    {
        if (empty($GLOBALS['conf']['signup']['queue'])) {
            return false;
        }

        $query = 'SELECT 1 FROM ' . $this->_params['table'] .
                 ' WHERE user_name = ?';
        $values = array($user);

        return (bool)$GLOBALS['injector']->getInstance('Horde_Db_Adapter_Base')->selectValue($query, $values);
    }

    /**
     * Get a user's queued signup information.
     *
     * @param string $username  The username to retrieve the queued info for.
     *
     * @return Horde_Core_Auth_Signup_SqlObject $signup  The object for the
     *                                                   requested signup.
     * @throws Horde_Exception
     * @throws Horde_Db_Exception
     */
    public function getQueuedSignup($username)
    {
        $query = 'SELECT * FROM ' . $this->_params['table'] .
                 ' WHERE user_name = ?';
        $values = array($username);

        $result = $GLOBALS['injector']->getInstance('Horde_Db_Adapter_Base')->selectOne($query, $values);
        if (empty($result)) {
            throw new Horde_Exception(sprintf(_("User \"%s\" does not exist."), $username));
        }
        $object = new Horde_Core_Auth_Signup_SqlObject($data['user_name']);
        $object->setData($data);

        return $object;
    }

    /**
     * Get the queued information for all pending signups.
     *
     * @return array  An array of signup objects, one for each signup in the
     *                queue.
     * @throws Horde_Db_Exception
     */
    public function getQueuedSignups()
    {
        $query = 'SELECT * FROM ' . $this->_params['table'] .
                 ' ORDER BY signup_date';

        $result = $GLOBALS['injector']->getInstance('Horde_Db_Adapter_Base')->selectAll($query);
        if (empty($result)) {
            return array();
        }

        $signups = array();
        foreach ($result as $signup) {
            $object = new Horde_Core_Auth_Signup_SqlObject($signup['user_name']);
            $object->setData($signup);
            $signups[] = $object;
        }

        return $signups;
    }

    /**
     * Remove a queued signup.
     *
     * @param string $username  The user to remove from the signup queue.
     *
     * @throws Horde_Db_Exception
     */
    public function removeQueuedSignup($username)
    {
        $query = 'DELETE FROM ' . $this->_params['table'] .
                 ' WHERE user_name = ?';
        $values = array($username);

        $GLOBALS['injector']->getInstance('Horde_Db_Adapter_Base')->delete($query, $values);
    }

    /**
     * Return a new signup object.
     *
     * @param string $name  The signups's name.
     *
     * @return Horde_Core_Auth_Signup_SqlObject  A new signup object.
     * @throws InvalidArgumentException
     */
    public function newSignup($name)
    {
        if (empty($name)) {
            throw new InvalidArgumentException('Signup names must be non-empty.');
        }

        return new Horde_Core_Auth_Signup_SqlObject($name);
    }

}
