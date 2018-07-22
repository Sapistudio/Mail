<?php
namespace SapiStudio\SapiMail\Imap;
use Illuminate\Support\Arr;
use Carbon\Carbon;

class ImapMessage
{
    const NO_SUBJECT = '(no subject)';
    private $messageFolder;
    private $messageUid;
    private $messageFlags;
    private $messageContentType;
    private $messageSize;
    private $messageStatus;
    private $messageSubject;
    private $messageId;
    private $messageDate;
    private $messageFrom;
    private $messageReplyTo;
    private $messageTo;
    private $messageCc;
    private $messageBcc;
    private $messagePlain;
    private $messageHtml;
    private $messageAttachments;
    private $messageheaders;
    private $rawMessageContent;
    private $parser;

    /**
     * Message::make()
     * 
     * @param mixed $rawContent
     * @return
     */
    public static function make($rawContent)
    {
        $self         = new self;
        $self->parser = EmailParser::make((is_array($rawContent)) ? implode("\n", $rawContent) : $rawContent);
        return $self;
    }
    
    /**
     * Message::load()
     * 
     * @param mixed $format
     * @return
     */
    public function load($format=null){
        $mail = $this->parser->getMessage($format);
        if(!$mail)
            return false;
        $this->rawMessageContent = $mail['rawContent'];
        $this->messageheaders    = $mail['headers'];
        return $this->SetHtml($mail['html'])->SetPlain($mail['text'])->prepare();
    }
    
    /**
     * ImapMessage::hasFormat()
     * 
     * @param mixed $format
     * @return
     */
    public function hasFormat($format=null){
        return $this->parser->loadMessageFormat($format);   
    }
    
    /**
     * ImapMessage::getHeaders()
     * 
     * @return
     */
    public function getHeaders(){
        return $this->messageheaders;
    }
    
    /**
     * ImapMessage::bodyToHeaders()
     * 
     * @return
     */
    public function bodyToHeaders(){
        $this->messageheaders = $this->stringToHeaders($this->rawMessage());
        return $this->getHeaders();        
    }
    
    /**
     * ImapMessage::stringToHeaders()
     * 
     * @param mixed $string
     * @return
     */
    public function stringToHeaders($string=null){
        if(is_null($string))
            return false;
        $headers=array();
        $separator = "\r\n";
        $line = strtok($string, $separator);
        while ($line !== false)
        {
            $linedata = explode(':', $line);
            $headers[strtolower(trim($linedata[0]))] = trim($linedata[1]);
            $line = strtok($separator);
        }
        return $headers;
    }
        
    /**
     * Message::prepare()
     * 
     * @return
     */
    protected function prepare()
    {
        $this->messageFrom      = ($this->headerExists('From')) ? \mailparse_rfc822_parse_addresses($this->headerGet('From'))[0] : null;
        $this->messageReplyTo   = ($this->headerExists('Reply-to')) ? \mailparse_rfc822_parse_addresses($this->headerGet('Reply-to'))[0] : null;
        $this->messageTo        = ($this->headerExists('To')) ? \mailparse_rfc822_parse_addresses($this->headerGet('To')) : null;
        $this->messageCc        = ($this->headerExists('Cc')) ? \mailparse_rfc822_parse_addresses($this->headerGet('Cc')) : null;
        $this->messageBcc       = ($this->headerExists('Bcc')) ? \mailparse_rfc822_parse_addresses($this->headerGet('Bcc')) : null;
        $this->messageSubject   = $this->headerGet('Subject', self::NO_SUBJECT);
        $this->messageDate      = $this->headerGet('Date');
        $this->messageId        = $this->headerGet('Message-ID');
        return $this;
    }
    
    /**
     * Message::setFlags()
     * 
     * @param mixed $flags
     * @return
     */
    public function setFlags($flags){
        $this->messageFlags  = array_map("strtolower",array_filter(explode(" ",$flags)));
        $this->messageStatus = (in_array('\\seen', $this->messageFlags)) ? true : false;
        return $this;    
    }
    
    /**
     * Message::GetBody()
     * 
     * @return
     */
    public function GetBody(){
        return ($this->Html() != '') ? $this->Html() : str_replace("\n",'',$this->Plain());
    }
    
    /**
     * Message::headerExists()
     * 
     * @param mixed $key
     * @return
     */
    public function headerExists($key)
    {
        return Arr::has($this->messageheaders, strtolower($key));
    }

    /**
     * Message::setOptions()
     * 
     * @param mixed $key
     * @param mixed $value
     * @return
     */
    public function setOptions($key, $value = null)
    {
        $keys = is_array($key) ? $key : [$key => $value];
        foreach ($keys as $key => $value)
            $this->$key = $value;
        return $this;
    }

    /**
     * Message::headerGet()
     * 
     * @param mixed $key
     * @param mixed $default
     * @return
     */
    public function headerGet($key, $default = null)
    {
        return Arr::get($this->messageheaders, strtolower($key), $default);
    }
    
    public function getSendingIp(){
        return ($this->headerExists('x-originating-ip')) ? trim(str_replace(['[',']'],'',$this->headerGet('x-originating-ip'))) : null;
    }
            
    /**
     * Message::Plain()
     * 
     * @return
     */
    public function Plain()
    {
        return $this->messagePlain;
    }

    /**
     * Message::rawMessage()
     * 
     * @return
     */
    public function rawMessage()
    {
        return $this->rawMessageContent;
    }
    
    /**
     * Message::Html()
     * 
     * @return
     */
    public function Html()
    {
        return $this->messageHtml;
    }

    /**
     * Message::SetHtml()
     * 
     * @param mixed $sHtml
     * @return
     */
    public function SetHtml($sHtml)
    {
        $this->messageHtml = $sHtml;
        return $this;
    }

    /**
     * Message::SetPlain()
     * 
     * @param mixed $plain
     * @return
     */
    public function SetPlain($plain)
    {
        $this->messagePlain = $plain;
        return $this;
    }
    
    /**
     * Message::Status()
     * 
     * @return
     */
    public function Status()
    {
        return ($this->messageStatus) ? 'read' : 'unread';
    }
    
    public function isSeen(){
        return $this->messageStatus;
    }
    
    /**
     * Message::Folder()
     * 
     * @return
     */
    public function Folder()
    {
        return $this->messageFolder;
    }

    /**
     * Message::Uid()
     * 
     * @return
     */
    public function Uid()
    {
        return $this->messageUid;
    }

    /**
     * Message::MessageId()
     * 
     * @return
     */
    public function MessageId()
    {
        return $this->sMessageId;
    }

    /**
     * Message::Subject()
     * 
     * @return
     */
    public function Subject()
    {
        return $this->messageSubject;
    }

    /**
     * Message::ContentType()
     * 
     * @return
     */
    public function ContentType()
    {
        return $this->messageContentType;
    }

    /**
     * Message::Size()
     * 
     * @return
     */
    public function Size()
    {
        return $this->messageSize;
    }

    /**
     * Message::Date()
     * 
     * @return
     */
    public function Date()
    {
        return $this->messageDate;
    }

    /**
     * Message::Flags()
     * 
     * @return
     */
    public function Flags()
    {
        return $this->messageFlags;
    }


    /**
     * Message::From()
     * 
     * @return
     */
    public function From()
    {
        return $this->messageFrom;
    }

    /**
     * Message::ReplyTo()
     * 
     * @return
     */
    public function ReplyTo()
    {
        return $this->messageReplyTo;
    }


    /**
     * Message::To()
     * 
     * @return
     */
    public function To()
    {
        return $this->messageTo;
    }

    /**
     * Message::Cc()
     * 
     * @return
     */
    public function Cc()
    {
        return $this->messageCc;
    }

    /**
     * Message::Bcc()
     * 
     * @return
     */
    public function Bcc()
    {
        return $this->messageBcc;
    }

    /**
     * Message::Attachments()
     * 
     * @return
     */
    public function Attachments()
    {
        return $this->messageAttachments;
    }
}
