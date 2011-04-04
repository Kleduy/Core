<?php
/**
 * @category Horde
 * @package  Core
 */
class Horde_Core_Factory_Notification extends Horde_Core_Factory_Base
{
    public function create()
    {
        $notify = new Horde_Notification_Handler(
            new Horde_Core_Notification_Storage_Session()
        );

        $notify->addType('default', '*', 'Horde_Core_Notification_Status');
        $notify->addType('status', 'horde.*', 'Horde_Core_Notification_Status');

        $notify->addDecorator(new Horde_Notification_Handler_Decorator_Alarm($this->_injector->getInstance('Horde_Core_Factory_Alarm'), $GLOBALS['registry']->getAuth()));
        $notify->addDecorator(new Horde_Core_Notification_Hordelog());

        return $notify;
    }
}
