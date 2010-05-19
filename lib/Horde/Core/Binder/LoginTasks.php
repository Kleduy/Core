<?php
/**
 * @category Horde
 * @package  Core
 */
class Horde_Core_Binder_LoginTasks implements Horde_Injector_Binder
{
    public function create(Horde_Injector $injector)
    {
        return new Horde_Core_Factory_LoginTasks($injector);
    }

    public function equals(Horde_Injector_Binder $binder)
    {
        return false;
    }
}
