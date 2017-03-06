<?php

namespace SapiStudio\SapiMail\Transport;

use Swift_Transport;
use Swift_Mime_Message;
use Swift_Events_SendEvent;
use Swift_Events_EventListener;

abstract class Transport implements Swift_Transport
{
    public $plugins = [];

    /**
     * Transport::isStarted()
     * 
     * @return
     */
    public function isStarted()
    {
        return true;
    }

    /**
     * Transport::start()
     * 
     * @return
     */
    public function start()
    {
        return true;
    }

    /**
     * Transport::stop()
     * 
     * @return
     */
    public function stop()
    {
        return true;
    }

    /**
     * Transport::registerPlugin()
     * 
     * @param mixed $plugin
     * @return
     */
    public function registerPlugin(Swift_Events_EventListener $plugin)
    {
        array_push($this->plugins, $plugin);
    }

    /**
     * Transport::beforeSendPerformed()
     * 
     * @param mixed $message
     * @return
     */
    protected function beforeSendPerformed(Swift_Mime_Message $message)
    {
        $event = new Swift_Events_SendEvent($this, $message);

        foreach ($this->plugins as $plugin) {
            if (method_exists($plugin, 'beforeSendPerformed')) {
                $plugin->beforeSendPerformed($event);
            }
        }
    }

    /**
     * Transport::numberOfRecipients()
     * 
     * @param mixed $message
     * @return
     */
    protected function numberOfRecipients(Swift_Mime_Message $message)
    {
        return count(array_merge(
            (array) $message->getTo(), (array) $message->getCc(), (array) $message->getBcc()
        ));
    }
}
