<?php

namespace SapiStudio\SapiMail\Transport;

use Swift_Mime_Message;
use Swift_Mime_MimeEntity;
use Illuminate\Filesystem\Filesystem;
use Swift_RfcComplianceException;

class PmtaTransport extends Transport
{
    public $pickupHeader    = 'x-pickup';
    public $receiverHeader  = 'x-Receiver';
    public $senderHeader    = 'x-sender';
        
    /**
     * PmtaTransport::send()
     * 
     * @param mixed $message
     * @param mixed $failedRecipients
     * @return
     */
    public function send(Swift_Mime_Message $message, &$failedRecipients = null)
    {
        $this->beforeSendPerformed($message);
        $pickup = $message->getHeaders()->get($this->pickupHeader)->getFieldBody();
        $message->getHeaders()->removeAll($this->pickupHeader);
        $to = $this->getToAddresses($message);
        if(!$pickup || !$to)
            throw new Swift_RfcComplianceException('Invalid addresses');
        foreach($to as $receiver)
            $message->getHeaders()->addTextHeader($this->receiverHeader, $receiver);
        if(!$message->getHeaders()->get($this->senderHeader))
            throw new Swift_RfcComplianceException('Invalid Sender');
        (new Filesystem)->put($pickup.DIRECTORY_SEPARATOR.md5(uniqid()),$message->toString());
        return $this->numberOfRecipients($message);
    }
    
    /**
     * PmtaTransport::getToAddresses()
     * 
     * @param mixed $message
     * @return
     */
    protected function getToAddresses(Swift_Mime_Message $message)
    {
        $to = [];
        if ($message->getTo()) {
            $to = array_merge($to, array_keys($message->getTo()));
        }
        if ($message->getCc()) {
            $to = array_merge($to, array_keys($message->getCc()));
        }
        if ($message->getBcc()) {
            $to = array_merge($to, array_keys($message->getBcc()));
        }
        return $to;
    }
}
