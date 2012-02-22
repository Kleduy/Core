<?php
/**
 * Horde backend. Provides the communication between horde data and
 * ActiveSync server.
 *
 * Copyright 2010-2012 Horde LLC (http://www.horde.org/)
 *
 * @author  Michael J. Rubinsky <mrubinsk@horde.org>
 * @package Core
 */
class Horde_Core_ActiveSync_Driver extends Horde_ActiveSync_Driver_Base
{
    /**
     *  Server folder ids for non-email folders.
     *  We use the @ modifiers to avoid issues in the (fringe) case of
     *  having email folders named like contacts etc...
     */
    const APPOINTMENTS_FOLDER_UID = '@Calendar@';
    const CONTACTS_FOLDER_UID     = '@Contacts@';
    const TASKS_FOLDER_UID        = '@Tasks@';

    const SPECIAL_SENT = 'sent';
    const SPECIAL_SPAM = 'spam';
    const SPECIAL_TRASH = 'trash';
    const SPECIAL_DRAFTS = 'drafts';


    /**
     * Mappings for server uids -> display names. Populated in the const'r
     * so we can use localized text.
     *
     * @var array
     */
    private $_displayMap = array();

    /**
     * Cache message stats
     *
     * @var array  An array of stat hashes
     */
    private $_modCache;

    /**
     * Horde connector instance
     *
     * @var Horde_Core_ActiveSync_Connector
     */
    private $_connector;

    /**
     * Imap client
     *
     * @var Horde_Imap_Client_Socket
     */
    private $_imap;

    /**
     * Folder cache
     *
     * @var array
     */
    private $_folders = array();

    /**
     * Email folder cache
     *
     * @var array
     */
    private $_emailFolders = array();

    private $_specialFolders = array();

    /**
     * Authentication object
     *
     * @var Horde_Auth_Base
     */
     private $_auth;

    /**
     * Const'r
     * <pre>
     * Required params (in addition to the base class' requirements):
     *   connector => Horde_ActiveSync_Driver_Horde_Connector_Registry object
     *   auth      => Horde_Auth object
     * </pre>
     *
     * @param array $params  Configuration parameters.
     *
     * @return Horde_ActiveSync_Driver_Horde
     */
    public function __construct(array $params = array())
    {
        parent::__construct($params);
        if (empty($this->_params['connector']) || !($this->_params['connector'] instanceof Horde_Core_ActiveSync_Connector)) {
            throw new InvalidArgumentException('Missing required connector object.');
        }

        if (empty($this->_params['auth']) || !($this->_params['auth'] instanceof Horde_Auth_Base)) {
            throw new InvalidArgumentException('Missing required Auth object');
        }

        if (!empty($this->_params['imap'])) {
            $this->_imap = $this->_params['imap'];
            unset($this->_params['imap']);
        }

        $this->_connector = $params['connector'];
        $this->_auth = $params['auth'];
        unset($this->_params['connector']);
        unset($this->_params['auth']);

        // Build the displaymap
        $this->_displayMap = array(
            self::APPOINTMENTS_FOLDER_UID => Horde_ActiveSync_Translation::t('Calendar'),
            self::CONTACTS_FOLDER_UID => Horde_ActiveSync_Translation::t('Contacts'),
            self::TASKS_FOLDER_UID => Horde_ActiveSync_Translation::t('Tasks')
        );
    }

    /**
     * Authenticate to Horde
     *
     * @see framework/ActiveSync/lib/Horde/ActiveSync/Driver/Horde_ActiveSync_Driver_Base#Logon($username, $domain, $password)
     */
    public function logon($username, $password, $domain = null)
    {
        $this->_logger->info('Horde_ActiveSync_Driver_Horde::logon attempt for: ' . $username);
        parent::logon($username, $password, $domain);

        return $this->_auth->authenticate($username, array('password' => $password));
    }

    /**
     * Clean up
     *
     * @see framework/ActiveSync/lib/Horde/ActiveSync/Driver/Horde_ActiveSync_Driver_Base#Logoff()
     */
    public function logOff()
    {
        $this->_connector->clearAuth();
        $this->_logger->info('User ' . $this->_user . ' logged off');
        return true;
    }

    /**
     * Setup sync parameters. The user provided here is the user the backend
     * will sync with. This allows you to authenticate as one user, and sync as
     * another, if the backend supports this.
     *
     * @param string $user      The username to sync as on the backend.
     *
     * @return boolean
     */
    public function setup($user)
    {
        parent::setup($user);
        $this->_modCache = array();
        return true;
    }

    /**
     * Get the wastebasket folder
     *
     * @see framework/ActiveSync/lib/Horde/ActiveSync/Driver/Horde_ActiveSync_Driver_Base#getWasteBasket()
     */
    public function getWasteBasket()
    {
        $this->_logger->debug('Horde::getWasteBasket()');

        return false;
    }

    /**
     * Return an array of stats for the server's folder list.
     *
     * @return array  An array of folder stats
     */
    public function getFolderList()
    {
        $this->_logger->debug('Horde::getFolderList()');
        $folderlist = $this->getFolders();
        $folders = array();
        foreach ($folderlist as $f) {
            $folders[] = $this->statFolder($f->serverid, $f->parentid, $f->displayname);
        }

        return $folders;
    }

    /**
     * Return an array of the server's folder objects.
     *
     * @return array  An array of Horde_ActiveSync_Message_Folder objects.
     * @since 2.0
     */
    public function getFolders()
    {
        if (empty($this->_folders)) {
            ob_start();
            $this->_logger->debug('Horde::getFolders()');
            try {
                $supported = $this->_connector->horde_listApis();
            } catch (Exception $e) {
                $this->_logger->err($e->getMessage());
                $this->_endBuffer();
                return array();
            }
            $folders = array();
            if (array_search('calendar', $supported)) {
                $folders[] = $this->getFolder(self::APPOINTMENTS_FOLDER_UID);
            }

            if (array_search('contacts', $supported)) {
                $folders[] = $this->getFolder(self::CONTACTS_FOLDER_UID);
            }

            if (array_search('tasks', $supported)) {
                $folders[] = $this->getFolder(self::TASKS_FOLDER_UID);
            }

            if (array_search('mail', $supported)) {
                $folders = array_merge($folders, $this->_getMailFolders());
            }

            if ($errors = Horde::endBuffer()) {
                $this->_logger->err('Unexpected output: ' . $errors);
            }
            $this->_endBuffer();

            $this->_folders = $folders;
        }

        return $this->_folders;
    }

    /**
     * Factory for Horde_ActiveSync_Message_Folder objects.
     *
     * @param string $id   The folder's server id.
     *
     * @return Horde_ActiveSync_Message_Folder
     * @throws Horde_ActiveSync_Exception
     */
    public function getFolder($id)
    {
        $this->_logger->debug('Horde::getFolder(' . $id . ')');

        switch ($id) {
        case self::APPOINTMENTS_FOLDER_UID:
            $folder = $this->_buildNonMailFolder(
                $id,
                0,
                Horde_ActiveSync::FOLDER_TYPE_APPOINTMENT,
                $this->_displayMap[self::APPOINTMENTS_FOLDER_UID]);
            break;
        case self::CONTACTS_FOLDER_UID:
            $folder = $this->_buildNonMailFolder(
               $id,
               0,
               Horde_ActiveSync::FOLDER_TYPE_CONTACT,
               $this->_displayMap[self::CONTACTS_FOLDER_UID]);
            break;
        case self::TASKS_FOLDER_UID:
            $folder = $this->_buildNonMailFolder(
                $id,
                0,
                Horde_ActiveSync::FOLDER_TYPE_TASK,
                $this->_displayMap[self::TASKS_FOLDER_UID]);
            break;
        default:
            // Must be a mail folder
            $folders = $this->_getMailFolders();
            foreach ($folders as $folder) {
                if ($folder->serverid == $id) {
                    return $folder;
                }
            }
            $this->_logger->err('Folder ' . $id . ' unknown');
            throw new Horde_ActiveSync_Exception('Folder ' . $id . ' unknown');
        }

        return $folder;
    }

    /**
     * Stat folder. Note that since the only thing that can ever change for a
     * folder is the name, we use that as the 'mod' value.
     *
     * @param string $id     The folder id
     * @param mixed $parent  The parent folder (or 0 if none). @since 2.0
     * @param mixed $mod     Modification indicator. For folders, this is the
     *                       name of the folder, since that's the only thing
     *                       that can change. @since 2.0
     * @return a stat hash
     */
    public function statFolder($id, $parent = 0, $mod = null)
    {
        $this->_logger->debug('Horde::statFolder(' . $id . ')');

        $folder = array();
        $folder['id'] = $id;
        $folder['mod'] = empty($mod) ? $id : $mod;
        $folder['parent'] = $parent;

        return $folder;
    }

    /**
     * Get a list of server changes that occured during the specified time
     * period.
     *
     * @param Horde_ActiveSync_Folder_Base $folder
     *      The ActiveSync folder object to request changes for.
     * @param integer $from_ts     The starting timestamp
     * @param integer $to_ts       The ending timestamp
     * @param integer $cutoffdate  The earliest date to retrieve back to
     *
     * @return array A list of messge uids that have changed in the specified
     *               time period.
     */
    public function getServerChanges($folder, $from_ts, $to_ts, $cutoffdate)
    {
        $this->_logger->debug(
            sprintf("Horde_ActiveSync_Driver_Horde::getServerChanges(%s, $from_ts, $to_ts, $cutoffdate)",
                    (string)$folder));

        $changes = array(
            'add' => array(),
            'delete' => array(),
            'modify' => array()
        );

        ob_start();
        switch ($folder->class()) {
        case Horde_ActiveSync::CLASS_CALENDAR
            if ($from_ts == 0) {
                // Can't use History if it's a first sync
                $startstamp = (int)$cutoffdate;
                $endstamp = time() + 32140800; //60 * 60 * 24 * 31 * 12 == one year
                try {
                    $changes['add'] = $this->_connector->calendar_listUids($startstamp, $endstamp);
                } catch (Horde_Exception $e) {
                    $this->_logger->err($e->getMessage());
                    $this->_endBuffer();
                    return array();
                }
            } else {
                try {
                    $changes = $this->_connector->getChanges('calendar', $from_ts, $to_ts);
                } catch (Horde_Exception $e) {
                    $this->_logger->err($e->getMessage());
                    $this->_endBuffer();
                    return array();
                }
            }
            break;

        case Horde_ActiveSync::CLASS_CONTACT:
            // Can't use History for first sync
            if ($from_ts == 0) {
                try {
                    $changes['add'] = $this->_connector->contacts_listUids();
                } catch (Horde_Exception $e) {
                    $this->_logger->err($e->getMessage());
                    $this->_endBuffer();
                    return array();
                }
                $edits = $deletes = array();
            } else {
                try {
                    $changes = $this->_connector->getChanges('contacts', $from_ts, $to_ts);
                } catch (Horde_Exception $e) {
                    $this->_logger->err($e->getMessage());
                    $this->_endBuffer();
                    return array();
                }
            }
            break;

        case Horde_ActiveSync::CLASS_TASK:
            // Can't use History for first sync
            if ($from_ts == 0) {
                try {
                    $changes['add'] = $this->_connector->tasks_listUids();
                } catch (Horde_Exception $e) {
                    $this->_logger->err($e->getMessage());
                    $this->_endBuffer();
                    return array();
                }
            } else {
                try {
                    $changes = $this->_connector->getChanges('tasks', $from_ts, $to_ts);
                } catch (Horde_Exception $e) {
                    $this->_logger->err($e->getMessage());
                    $this->_endBuffer();
                    return array();
                }
            }
            break;
        case Horde_ActiveSync::CLASS_EMAIL:
            // Email request.
            try {
                $folder = &$this->_connector->mail_getMessageList(
                    $folder,
                    array('sincedate' => (int)$cutoffdate));
            } catch (Horde_Exception $e) {
                $this->_logger->err($e->getMessage());
                $this->_endBuffer();
                return array();
            }
            $changes['add'] = $folder->added();
            $changes['delete'] = $folder->removed();
            $changes['modify'] = $folder->changed();
        }

        $results = array();

        // Server additions
        foreach ($changes['add'] as $add) {
            $results[] = array(
                'id' => $add,
                'type' => Horde_ActiveSync::CHANGE_TYPE_CHANGE,
                'flags' => Horde_ActiveSync::FLAG_NEWMESSAGE);
        }

        // Server changes
        foreach ($changes['modify'] as $change) {
            $results[] = array(
                'id' => $change,
                'type' => Horde_ActiveSync::CHANGE_TYPE_FLAGS);
        }

        // Server Deletions
        foreach ($changes['delete'] as $deleted) {
            $results[] = array(
                'id' => $deleted,
                'type' => Horde_ActiveSync::CHANGE_TYPE_DELETE);
        }
        $this->_endBuffer();

        return $results;
    }

    /**
     * Get a message from the backend
     *
     * @see framework/ActiveSync/lib/Horde/ActiveSync/Driver/Horde_ActiveSync_Driver_Base#getMessage
     */
    public function getMessage($folderid, $id, $truncsize, $mimesupport = 0)
    {
        $this->_logger->debug('Horde::getMessage(' . $folderid . ', ' . $id . ')');
        ob_start();
        $message = false;
        $folder = $this->getFolder($folderid);
        switch ($folder->type) {
        case Horde_ActiveSync::FOLDER_TYPE_APPOINTMENT:
            try {
                $message = $this->_connector->calendar_export($id);
                // Nokia MfE requires the optional UID element.
                if (!$message->getUid()) {
                    $message->setUid($id);
                }
            } catch (Horde_Exception $e) {
                $this->_logger->err($e->getMessage());
                $this->_endBuffer();
                return false;
            }
            break;

        case Horde_ActiveSync::FOLDER_TYPE_CONTACT:
            try {
                $message = $this->_connector->contacts_export($id);
            } catch (Horde_Exception $e) {
                $this->_logger->err($e->getMessage());
                $this->_endBuffer();
                return false;
            }
            break;

        case Horde_ActiveSync::FOLDER_TYPE_TASK:
            try {
                $message = $this->_connector->tasks_export($id);
            } catch (Horde_Exception $e) {
                $this->_logger->err($e->getMessage());
                $this->_endBuffer();
                return false;
            }
            break;

        case Horde_ActiveSync::FOLDER_TYPE_INBOX:
        case Horde_ActiveSync::FOLDER_TYPE_SENTMAIL:
        case Horde_ActiveSync::FOLDER_TYPE_WASTEBASKET:
        case Horde_ActiveSync::FOLDER_TYPE_DRAFTS:
        case Horde_ActiveSync::FOLDER_TYPE_USER_MAIL:
            try {
                $messages = $this->_connector->mail_getMessages(
                    $folder,
                    $id,
                    array('truncation' => $truncsize));
            } catch (Horde_Exception $e) {
                $this->_logger->err($e->getMessage());
                $this->_endBuffer();
                return false;
            }
            $this->_endBuffer();
            return array_pop($messages);
            break;

        default:
            $this->_endBuffer();
            return false;
        }
        if (strlen($message->body) > $truncsize) {
            $message->body = Horde_String::substr($message->body, 0, $truncsize);
            $message->bodytruncated = 1;
        } else {
            // Be certain this is set.
            $message->bodytruncated = 0;
        }

        $this->_endBuffer();
        return $message;
    }

    /**
     * Get message stat data
     *
     * @see framework/ActiveSync/lib/Horde/ActiveSync/Driver/Horde_ActiveSync_Driver_Base#statMessage($folderId, $id)
     */
    public function statMessage($folderid, $id)
    {
        return $this->_smartStatMessage($folderid, $id, true);
    }

    /**
     * Delete a message
     *
     * @see framework/ActiveSync/lib/Horde/ActiveSync/Driver/Horde_ActiveSync_Driver_Base#deleteMessage($folderid, $id)
     */
    public function deleteMessage($folderid, $id)
    {
        $this->_logger->debug('Horde::deleteMessage(' . $folderid . ', ' . $id . ')');
        ob_start();
        switch ($folderid) {
        case self::APPOINTMENTS_FOLDER_UID:
            try {
                $this->_connector->calendar_delete($id);
            } catch (Horde_Exception $e) {
                $this->_logger->err($e->getMessage());
                $this->_endBuffer();
            }
            break;

        case self::CONTACTS_FOLDER_UID:
            try {
                $this->_connector->contacts_delete($id);
            } catch (Horde_Exception $e) {
                $this->_logger->err($e->getMessage());
                $this->_endBuffer();
            }
            break;

        case self::TASKS_FOLDER_UID:
            try {
                $this->_connector->tasks_delete($id);
            } catch (Horde_Exception $e) {
                $this->_logger->err($e->getMessage());
                $this->_endBuffer();
            }
            break;
        default:
            $this->_endBuffer();
        }

        $this->_endBuffer();
    }

    /**
     * Add/Edit a message
     *
     * @param string $folderid  The server id for the folder the message belongs
     *                          to.
     * @param string $id        The server's uid for the message if this is a
     *                          change to an existing message.
     * @param Horde_ActiveSync_Message_Base $message  The activesync message
     * @param object $device  The device information
     *
     * @see framework/ActiveSync/lib/Horde/ActiveSync/Driver/Horde_ActiveSync_Driver_Base#changeMessage($folderid, $id, $message)
     */
    public function changeMessage($folderid, $id, $message, $device)
    {
        $this->_logger->debug('Horde::changeMessage(' . $folderid . ', ' . $id . ')');
        ob_start();
        $stat = false;
        switch ($folderid) {
        case self::APPOINTMENTS_FOLDER_UID:
            if (!$id) {
                try {
                    $id = $this->_connector->calendar_import($message);
                } catch (Horde_Exception $e) {
                    $this->_logger->err($e->getMessage());
                    $this->_endBuffer();
                    return false;
                }
                // There is no history entry for new messages, so use the
                // current time for purposes of remembering this is from the PIM
                $stat = $this->_smartStatMessage($folderid, $id, false);
                $stat['mod'] = time();
            } else {
                // ActiveSync messages do NOT contain the serverUID value, put
                // it in ourselves so we can have it during import/change.
                $message->setServerUID($id);
                if (!empty($device->supported[self::APPOINTMENTS_FOLDER_UID])) {
                    $message->setSupported($device->supported[self::APPOINTMENTS_FOLDER_UID]);
                }
                try {
                    $this->_connector->calendar_replace($id, $message);
                } catch (Horde_Exception $e) {
                    $this->_logger->err($e->getMessage());
                    $this->_endBuffer();
                    return false;
                }
                $stat = $this->_smartStatMessage($folderid, $id, false);
            }
            break;

        case self::CONTACTS_FOLDER_UID:
            if (!$id) {
                try {
                    $id = $this->_connector->contacts_import($message);
                } catch (Horde_Exception $e) {
                    $this->_logger->err($e->getMessage());
                    $this->_endBuffer();
                    return false;
                }
                $stat = $this->_smartStatMessage($folderid, $id, false);
                $stat['mod'] = time();
            } else {
                if (!empty($device->supported[self::CONTACTS_FOLDER_UID])) {
                    $message->setSupported($device->supported[self::CONTACTS_FOLDER_UID]);
                }
                try {
                    $this->_connector->contacts_replace($id, $message);
                } catch (Horde_Exception $e) {
                    $this->_logger->err($e->getMessage());
                    $this->_endBuffer();
                    return false;
                }
                $stat = $this->_smartStatMessage($folderid, $id, false);
            }
            break;

        case self::TASKS_FOLDER_UID:
            if (!$id) {
                try {
                    $id = $this->_connector->tasks_import($message);
                } catch (Horde_Exception $e) {
                    $this->_logger->err($e->getMessage());
                    $this->_endBuffer();
                    return false;
                }
                $stat = $this->_smartStatMessage($folderid, $id, false);
                $stat['mod'] = time();
            } else {
                if (!empty($device->supported[self::TASKS_FOLDER_UID])) {
                    $message->setSupported($device->supported[self::TASKS_FOLDER_UID]);
                }
                try {
                    $this->_connector->tasks_replace($id, $message);
                } catch (Horde_Exception $e) {
                    $this->_logger->err($e->getMessage());
                    $this->_endBuffer();
                    return false;
                }
                $stat = $this->_smartStatMessage($folderid, $id, false);
            }
            break;

        default:
            $this->_endBuffer();
            return false;
        }

        $this->_endBuffer();
        return $stat;
    }

    /**
     * Returns array of items which contain contact information
     *
     * @param string $query  The text string to match against any textual ANR
     *                       (Automatic Name Resolution) properties. Exchange's
     *                       searchable ANR properties are currently:
     *                       firstname, lastname, alias, displayname, email
     * @param string $range  The range to return (for example, 1-50).
     *
     * @return array with 'rows' and 'range' keys
     */
    public function getSearchResults($query, $range)
    {
        $return = array('rows' => array(),
                        'range' => $range);

        ob_start();
        try {
            $results = $this->_connector->contacts_search($query);
        } catch (Horde_ActiveSync_Exception $e) {
            $this->_logger->err($e->getMessage());
            $this->_endBuffer();
            return $return;
        }

        /* Honor range, and don't bother if no results */
        $count = count($results);
        if (!$count) {
            return $return;
        }
        $this->_logger->info('Horde::getSearchResults found ' . $count . ' matches.');

        preg_match('/(.*)\-(.*)/', $range, $matches);
        $return_count = $matches[2] - $matches[1];
        $rows = array_slice($results, $matches[1], $return_count + 1, true);
        $rows = array_pop($rows);
        foreach ($rows as $row) {
            $return['rows'][] = array(
                Horde_ActiveSync::GAL_ALIAS => !empty($row['alias']) ? $row['alias'] : '',
                Horde_ActiveSync::GAL_DISPLAYNAME => $row['name'],
                Horde_ActiveSync::GAL_EMAILADDRESS => !empty($row['email']) ? $row['email'] : '',
                Horde_ActiveSync::GAL_FIRSTNAME => $row['firstname'],
                Horde_ActiveSync::GAL_LASTNAME => $row['lastname'],
                Horde_ActiveSync::GAL_COMPANY => !empty($row['company']) ? $row['company'] : '',
                Horde_ActiveSync::GAL_HOMEPHONE => !empty($row['homePhone']) ? $row['homePhone'] : '',
                Horde_ActiveSync::GAL_PHONE => !empty($row['workPhone']) ? $row['workPhone'] : '',
                Horde_ActiveSync::GAL_MOBILEPHONE => !empty($row['cellPhone']) ? $row['cellPhone'] : '',
                Horde_ActiveSync::GAL_TITLE => !empty($row['title']) ? $row['title'] : '',
            );
        }

        $this->_endBuffer();
        return $return;
    }

    /**
     * Sends the email represented by the rfc822 string received by the PIM.
     * Currently only used when meeting requests are sent from the PIM.
     *
     * @param string $rfc822    The rfc822 mime message
     * @param boolean $forward  Indicates if this is a forwarded message
     * @param boolean $reply    Indicates if this is a reply
     * @param boolean $parent   Parent message in thread.
     *
     * @return boolean
     */
    public function sendMail($rfc822, $forward = false, $reply = false, $parent = false)
    {
        $headers = Horde_Mime_Headers::parseHeaders($rfc822);
        $message = Horde_Mime_Part::parseMessage($rfc822);

        // Message requests do not contain the From, since it is assumed to
        // be from the user of the AS account.
        $ident = $GLOBALS['injector']
            ->getInstance('Horde_Core_Factory_Identity')
            ->create($this->_user);
        $name = $ident->getValue('fullname');
        $from_addr = $ident->getValue('from_addr');

        $mail = new Horde_Mime_Mail();
        $mail->addHeaders($headers->toArray());
        $mail->addHeader('From', $name . '<' . $from_addr . '>');

        $body_id = $message->findBody();
        if ($body_id) {
            $part = $message->getPart($body_id);
            $body = $part->getContents();
            $mail->setBody($body);
        } else {
            $mail->setBody('No body?');
        }

        foreach ($message->contentTypeMap() as $id => $type) {
            $mail->addPart($type, $message->getPart($id)->toString());
        }

        $mail->send($GLOBALS['injector']->getInstance('Horde_Mail'));

        return true;
    }

    public function setReadFlag($folderId, $id, $flags)
    {
        $this->_connector->mail_setReadFlag($folderId, $id, $flags);
    }

    /**
     * Return the list of mail server folders.
     *
     * @return array  An array of Horde_ActiveSync_Message_Folder objects.
     */
    private function _getMailFolders()
    {
        if (empty($this->_mailFolders)) {
            $this->_logger->debug('Polling Horde_ActiveSync_Driver_Horde::_getMailFolders()');
            $folders = array();
            $imap_folders = $this->_connector->mail_folderlist();
            foreach ($imap_folders as $imap_name => $folder) {
                $folders[] = $this->_getMailFolder($imap_name, $folder);
            }
            $this->_mailFolders = $folders;
        }

        return $this->_mailFolders;
    }

    /**
     * Return a folder object representing an email folder. Attempt to detect
     * special folders appropriately.
     *
     * @param string $sid  The UTF7IMAP encoded server name.
     * @param array $f      An array describing the folder, as returned from
     *                      mail/folderlist.
     *
     * @return Horde_ActiveSync_Message_Folder
     */
    private function _getMailFolder($sid, $f)
    {
        $folder = new Horde_ActiveSync_Message_Folder();
        $folder->serverid = $sid;
        $folder->displayname = $f['label'];
        $folder->parentid = '0';

        if (empty($this->_specialFolders)) {
            $this->_specialFolders = $this->_connector->mail_getSpecialFolders();
        }

        // Short circuit for INBOX
        if (strcasecmp($sid, 'INBOX') === 0) {
            $folder->type = Horde_ActiveSync::FOLDER_TYPE_INBOX;
            return $folder;
        }

        // Check for known, supported special folders.
        foreach ($this->_specialFolders as $key => $value) {
            if (!is_array($value)) {
                $value = array($value);
            }
            foreach ($value as $mailbox) {
                switch ($key) {
                case self::SPECIAL_SENT:
                    if ($sid == $mailbox->basename) {
                        $folder->type = Horde_ActiveSync::FOLDER_TYPE_SENTMAIL;
                        return $folder;
                    }
                    break;
                case self::SPECIAL_TRASH:
                    if ($sid == $mailbox->basename) {
                        $folder->type = Horde_ActiveSync::FOLDER_TYPE_WASTEBASKET;
                        return $folder;
                    }
                    break;

                case self::SPECIAL_DRAFTS:
                    if ($sid == $mailbox->basename) {
                        $folder->type = Horde_ActiveSync::FOLDER_TYPE_DRAFTS;
                        return $folder;
                    }
                    break;
                }
            }
        }

        // Not a known folder, set it to user mail.
        $folder->type = Horde_ActiveSync::FOLDER_TYPE_USER_MAIL;
        return $folder;
    }

    /**
     * Build a stat structure for an email message.
     *
     * @return array
     */
    public function statMailMessage($folderid, $id)
    {
        $folder = $this->getFolder($folderid);
        $messages = $this->_connector->mail_getMessages($folder, array($id));
        if (!count($messages)) {
            // Message gone.
            return false;
        }
        $message = array_pop($messages);
        $envelope = $message->getEnvelope();
        $stat = array(
            'id' => $id,
            'mod' => $envelope->date,
            'flags' => 0
        );
        // $message is Horde_ActiveSync_Message_Mail object.
        $stat['flags'] = $message->read;

        return $stat;
    }

    /**
     * Helper to build a folder object for non-email folders.
     *
     * @param string $id      The folder's server id.
     * @param stirng $parent  The folder's parent id.
     * @param integer $type   The folder type.
     * @param string $name    The folder description.
     *
     * @return  Horde_ActiveSync_Message_Folder  The folder object.
     */
    private function _buildNonMailFolder($id, $parent, $type, $name)
    {
        $folder = new Horde_ActiveSync_Message_Folder();
        $folder->serverid = $id;
        $folder->parentid = $parent;
        $folder->type = $type;
        $folder->displayname = $name;

        return $folder;
    }

    /**
     *
     * @param string  $folderid  The folder id
     * @param string  $id        The message id
     * @param boolean $hint      Use the cached data, if available?
     *
     * @return message stat hash
     */
    private function _smartStatMessage($folderid, $id, $hint)
    {
        ob_start();
        $this->_logger->debug('ActiveSync_Driver_Horde::_smartStatMessage:' . $folderid . ':' . $id);
        $statKey = $folderid . $id;
        $mod = false;

        if ($hint && isset($this->_modCache[$statKey])) {
            $mod = $this->_modCache[$statKey];
        } else {
            try {
                switch ($folderid) {
                case self::APPOINTMENTS_FOLDER_UID:
                    $mod = $this->_connector->calendar_getActionTimestamp($id, 'modify');
                    break;

                case self::CONTACTS_FOLDER_UID:
                    $mod = $this->_connector->contacts_getActionTimestamp($id, 'modify');
                    break;

                case self::TASKS_FOLDER_UID:
                    $mod = $this->_connector->tasks_getActionTimestamp($id, 'modify');
                    break;

                default:
                    try {
                        return $this->statMailMessage($folderid, $id);
                    } catch (Horde_ActiveSync_Exception $e) {
                        $this->_endBuffer();
                        return false;
                    }

                }
            } catch (Horde_Exception $e) {
                $this->_logger->err($e->getMessage());
                $this->_endBuffer();
                return array('id' => '', 'mod' => 0, 'flags' => 1);
            }
            $this->_modCache[$statKey] = $mod;
        }

        $message = array();
        $message['id'] = $id;
        $message['mod'] = $mod;
        $message['flags'] = 1;

        $this->_endBuffer();
        return $message;
    }

    private function _endBuffer()
    {
        if ($output = ob_get_clean()) {
            $this->_logger->err('Unexpected output: ' . $output);
        }
    }

}
