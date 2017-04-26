<?php

namespace SapiStudio\SapiMail;

use Closure;
use Swift_Mailer;
use Swift_Message;
use Swift_MimePart;
use InvalidArgumentException;

class Mailer
{
    protected $swift;
    protected $from;
    protected $calls                = [];
    protected $message              = null;
    protected $addHeaders           = [];
    protected $removeHeaders        = [];
    protected $failedRecipients     = [];
    
    /**
     * Mailer::__construct()
     * 
     * @param mixed $swift
     * @return
     */
    public function __construct(Swift_Mailer $swift)
    {
        $this->swift = $swift;
    }
    
    /**
     * Mailer::from()
     * 
     * @param mixed $users
     * @return
     */
    public function from($users)
    {
        $this->from = $users;
        return $this;
    }
    
    /**
     * Mailer::addHeader()
     * 
     * @param mixed $name
     * @param mixed $value
     * @return
     */
    public function addHeader($name,$value='')
    {
        if(is_array($name))
            $this->addHeaders = array_merge($this->addHeaders,$name);
        else
            $this->addHeaders[$name] = $value;
        $this->addHeaders = array_change_key_case($this->addHeaders,CASE_LOWER );
        var_dump($this->addHeaders);
        return $this;
    }
    
    /**
     * Mailer::getHeader()
     * 
     * @param mixed $name
     * @param mixed $value
     * @return
     */
    public function getHeader($name='')
    {
        $name = strtolower($name);
        return (isset($this->addHeaders[$name])) ? $this->addHeaders[$name] : '';
    }
    
    /**
     * Mailer::removeHeader()
     * 
     * @param mixed $name
     * @param mixed $value
     * @return
     */
    public function removeHeader($name)
    {
        $this->removeHeaders[] = $name;
        return $this;
    }
    
    /**
     * Mailer::raw()
     * 
     * @param mixed $text
     * @return
     */
    public function raw($text)
    {
        return $this->send(null,null,$text);
    }

    /**
     * Mailer::plain()
     * 
     * @param mixed $plain
     * @return
     */
    public function plain($plain)
    {
        return $this->send(null,$plain);
    }
    
    /**
     * Mailer::html()
     * 
     * @param mixed $html
     * @param mixed $plain
     * @return
     */
    public function html($html,$plain)
    {
        return $this->send($html,$plain);
    }

    /**
     * Mailer::send()
     * 
     * @param mixed $view
     * @param mixed $plain
     * @param mixed $raw
     * @return
     */
    public function send($view=null,$plain=null,$raw=null)
    {
        $this->createMessage();
        $this->addContent($view, $plain, $raw);
        $this->message->setCharset($this->_charset);
        $this->message->setEncoder($this->_encoder);
        if($this->calls){
            /** Dynamically pass missing methods to the Swift message.*/
            foreach($this->calls as $method=>$parameters)
                call_user_func_array([$this->message, $method], $parameters);
        }
        $this->parseHeaders();
        $this->sendSwiftMessage($this->message->getSwiftMessage());
        $this->resetMessage();
    }
    
    /**
     * Mailer::parseHeaders()
     * 
     * @return void
     */
    private function parseHeaders(){
        if ($this->addHeaders && is_array($this->addHeaders)){
            uksort($this->addHeaders, function() { return rand() > rand(); });           
            foreach ($this->addHeaders as $name => $value){
                if(is_array($value)){
                    foreach($value as $a=>$val){
                        $this->message->getHeaders()->addTextHeader($name, $val);
                    }
                }else
                    $this->message->getHeaders()->addTextHeader($name, $value);
            }
        }
        if ($this->removeHeaders && is_array($this->removeHeaders)){
            foreach ($this->removeHeaders as $k => $value){
                echo $header;
                $this->message->getHeaders()->removeAll($value);
            }
        }
    }
    
    /**
     * Mailer::resetMessage()
     * 
     * @return
     */
    public function resetMessage()
    {
        $this->calls = null;
        $this->addHeaders = null;
        $this->removeHeaders = null;
        $this->message = null;
        return $this;
    }
    
    /**
     * Mailer::addContent()
     * 
     * @param mixed $view
     * @param mixed $plain
     * @param mixed $raw
     * @return
     */
    protected function addContent($view,$plain,$raw)
    {
        if (isset($view)){
            $this->message->setBody($view, 'text/html');
        }
        if (isset($plain)){
            $method = isset($view) ? 'addPart' : 'setBody';
            $this->message->$method($plain, 'text/plain');
        }
        if (isset($raw)) {
            $method = (isset($view) || isset($plain)) ? 'addPart' : 'setBody';
            $this->message->$method($raw, 'text/plain');
        }
    }
    
    /**
     * Mailer::sendSwiftMessage()
     * 
     * @param mixed $message
     * @return
     */
    protected function sendSwiftMessage($message)
    {
        try {
            return $this->swift->send($message, $this->failedRecipients);
        } finally {
            $this->swift->getTransport()->stop();
        }
    }

    /**
     * Mailer::createMessage()
     * 
     * @return
     */
    protected function createMessage()
    {
        $message = new Message(new Swift_Message);
        $message->from($this->from)->setEncoder($this->_encoder);
        $this->message = $message;
    }

    /**
     * Mailer::getSwiftMailer()
     * 
     * @return
     */
    public function getSwiftMailer()
    {
        return $this->swift;
    }

    /**
     * Mailer::failures()
     * 
     * @return
     */
    public function failures()
    {
        return $this->failedRecipients;
    }

    /**
     * Mailer::setSwiftMailer()
     * 
     * @param mixed $swift
     * @return
     */
    public function setSwiftMailer($swift)
    {
        $this->swift = $swift;
    }
    
    /**
     * Mailer::__call()
     * 
     * @param mixed $method
     * @param mixed $parameters
     * @return
     */
    public function __call($method, $parameters)
    {
        $this->calls[$method] = $parameters;
        return $this;
    }
    
    /**
     * Mailer::__get()
     * 
     * @param mixed $property
     * @return
     */
    public function __get($property)
    {
        return (isset($this->calls[$property])) ? $this->calls[$property] : null;
    }
}
