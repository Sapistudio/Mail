<?php

namespace SapiStudio\SapiMail;

use Swift_Image;
use Swift_Attachment;

class Message
{
    protected $swift;

    /**
     * Message::__construct()
     * 
     * @param mixed $swift
     * @return
     */
    public function __construct($swift)
    {
        $this->swift = $swift;
    }

    /**
     * Message::from()
     * 
     * @param mixed $address
     * @param mixed $name
     * @return
     */
    public function from($address, $name = null)
    {
        $this->swift->setFrom($address, $name);

        return $this;
    }

    /**
     * Message::sender()
     * 
     * @param mixed $address
     * @param mixed $name
     * @return
     */
    public function sender($address, $name = null)
    {
        $this->swift->setSender($address, $name);

        return $this;
    }

    /**
     * Message::returnPath()
     * 
     * @param mixed $address
     * @return
     */
    public function returnPath($address)
    {
        $this->swift->setReturnPath($address);

        return $this;
    }

    /**
     * Message::to()
     * 
     * @param mixed $address
     * @param bool $override
     * @return
     */
    public function to($address,$override = true)
    {
        if ($override) {
            $this->swift->setTo($address, $name);

            return $this;
        }

        return $this->addAddresses($address, 'To');
    }

    /**
     * Message::cc()
     * 
     * @param mixed $address
     * @param mixed $name
     * @return
     */
    public function cc($address, $name = null)
    {
        return $this->addAddresses($address, $name, 'Cc');
    }

    /**
     * Message::bcc()
     * 
     * @param mixed $address
     * @param mixed $name
     * @return
     */
    public function bcc($address, $name = null)
    {
        return $this->addAddresses($address, $name, 'Bcc');
    }

    /**
     * Message::replyTo()
     * 
     * @param mixed $address
     * @param mixed $name
     * @return
     */
    public function replyTo($address, $name = null)
    {
        return $this->addAddresses($address, $name, 'ReplyTo');
    }

    /**
     * Message::addAddresses()
     * 
     * @param mixed $address
     * @param mixed $name
     * @param mixed $type
     * @return
     */
    protected function addAddresses($address, $name, $type)
    {
        if (is_array($address)) {
            $this->swift->{"set{$type}"}($address, $name);
        } else {
            $this->swift->{"add{$type}"}($address, $name);
        }

        return $this;
    }

    /**
     * Message::subject()
     * 
     * @param mixed $subject
     * @return
     */
    public function subject($subject)
    {
        $this->swift->setSubject($subject);

        return $this;
    }

    /**
     * Message::priority()
     * 
     * @param mixed $level
     * @return
     */
    public function priority($level)
    {
        $this->swift->setPriority($level);

        return $this;
    }

    /**
     * Message::attach()
     * 
     * @param mixed $file
     * @param mixed $options
     * @return
     */
    public function attach($file, array $options = [])
    {
        $attachment = $this->createAttachmentFromPath($file);

        return $this->prepAttachment($attachment, $options);
    }
    
    /**
     * Message::removeHeader()
     * 
     * @param mixed $headerName
     * @return
     */
    public function removeHeader($headerName)
    {
        $this->swift->getHeaders()->removeAll($headerName);
        
        return $this;
    }
    
    /**
     * Message::replaceHeaders()
     */
    
    public function replaceHeaders($newHeaders){
        foreach ($newHeaders as $headerName => $headerValue){
            $this->removeHeader($headerName);
            $this->swift->getHeaders()->addTextHeader($headerName, $headerValue);
        }
    }
    
    /**
     * Message::createAttachmentFromPath()
     * 
     * @param mixed $file
     * @return
     */
    protected function createAttachmentFromPath($file)
    {
        return Swift_Attachment::fromPath($file);
    }

    /**
     * Message::attachData()
     * 
     * @param mixed $data
     * @param mixed $name
     * @param mixed $options
     * @return
     */
    public function attachData($data, $name, array $options = [])
    {
        $attachment = $this->createAttachmentFromData($data, $name);

        return $this->prepAttachment($attachment, $options);
    }

    /**
     * Message::createAttachmentFromData()
     * 
     * @param mixed $data
     * @param mixed $name
     * @return
     */
    protected function createAttachmentFromData($data, $name)
    {
        return Swift_Attachment::newInstance($data, $name);
    }

    /**
     * Message::embed()
     * 
     * @param mixed $file
     * @return
     */
    public function embed($file)
    {
        return $this->swift->embed(Swift_Image::fromPath($file));
    }

    /**
     * Message::embedData()
     * 
     * @param mixed $data
     * @param mixed $name
     * @param mixed $contentType
     * @return
     */
    public function embedData($data, $name, $contentType = null)
    {
        $image = Swift_Image::newInstance($data, $name, $contentType);

        return $this->swift->embed($image);
    }

    /**
     * Message::prepAttachment()
     * 
     * @param mixed $attachment
     * @param mixed $options
     * @return
     */
    protected function prepAttachment($attachment, $options = [])
    {
        // First we will check for a MIME type on the message, which instructs the
        // mail client on what type of attachment the file is so that it may be
        // downloaded correctly by the user. The MIME option is not required.
        if (isset($options['mime'])) {
            $attachment->setContentType($options['mime']);
        }

        // If an alternative name was given as an option, we will set that on this
        // attachment so that it will be downloaded with the desired names from
        // the developer, otherwise the default file names will get assigned.
        if (isset($options['as'])) {
            $attachment->setFilename($options['as']);
        }

        $this->swift->attach($attachment);

        return $this;
    }

    /**
     * Message::getSwiftMessage()
     * 
     * @return
     */
    public function getSwiftMessage()
    {
        return $this->swift;
    }

    /**
     * Message::__call()
     * 
     * @param mixed $method
     * @param mixed $parameters
     * @return
     */
    public function __call($method, $parameters)
    {
        $callable = [$this->swift, $method];

        return call_user_func_array($callable, $parameters);
    }
}
