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
    private $hasAttachements = false;
    private $internalDate    = null;

    /**
     * Message::make()
     */
    public static function make($rawContent)
    {
        $rawContent     = trim((is_array($rawContent)) ? implode("\n", $rawContent) : $rawContent);
        $self           = new self;
        $self->parser   = ImapMessageParser::make($rawContent);
        $self->rawMessageContent = $rawContent;
        $rawContent     = preg_replace('/\r\n/',"\n",$rawContent);
        $rawContent     = preg_replace('/\r/',"\n",$rawContent);
        preg_match("/(.*?)\n\n(.*)/s",$rawContent,$overview);
        if(!isset($overview[2]) || !$overview){
            $self->messageheaders = $self->parseHeader($rawContent);
            return $self->prepare();
        }
        return $self->loadMessagePart();
    }
    
    private function parseHeader($str){
        $str = explode("\n",preg_replace('/\n\s+/',' ',$str));
        $h = array();
        foreach($str as $k=>$v){
            if(!$v) continue;
            $p=strpos($v,':');
            $headerName         = strtolower(substr($v,0,$p));
            $h[$headerName]     = filter_var(trim(substr($v,$p+1)), FILTER_UNSAFE_RAW, FILTER_FLAG_ENCODE_LOW|FILTER_FLAG_STRIP_HIGH);
        }
        return array_map('mb_decode_mimeheader',$h);
    }
    
    public function setAttachements($attach = false){
        $this->hasAttachements = $attach;
        return $this;
    }
    
    public function hasAttachements(){
        return $this->hasAttachements;
    }
    
    public function getAttachementList(){
        $attachs        = [];
        $attachments    = $this->parser->getAttachments([true]);
        if (count($attachments) > 0) {
	       foreach ($attachments as $attachment) {
		      $attachs[] = $attachment->getFilename();
	       }
        }
        return $attachs;
    }
    
    public function getAttachement($name = ''){
        $attachments    = $this->parser->getAttachments([true]);
        if (count($attachments) > 0) {
	       foreach ($attachments as $attachment) {
	           if($attachment->getFilename() == $name)
                    return $attachment;
	       }
        }
        return false;
    }
    
    /**
     * Message::loadMessagePart()
     */
    public function loadMessagePart($format=null){
        $mail = $this->parser->getMessage($format);
        if(!$mail)
            return false;
        $this->messageheaders    = $mail['headers'];
        return $this->SetHtml($mail['html'])->SetPlain(nl2br($mail['text']))->prepare();
    }
    
    /**
     * ImapMessage::hasFormat()
     */
    public function hasFormat($format=null){
        return $this->parser->loadMessageFormat($format);   
    }
    
    /**
     * ImapMessage::getHeaders()
     */
    public function getHeaders(){
        return $this->messageheaders;
    }
    
    /**
     * ImapMessage::bodyToHeaders()
     */
    public function bodyToHeaders(){
        $this->messageheaders = $this->stringToHeaders($this->rawMessage());
        return $this->getHeaders();        
    }
    
    /**
     * ImapMessage::stringToHeaders()
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
    
    public function headerToString(){
        $ret = '';
        foreach($this->getHeaders() as $key=>$headerValue){
            $ret .=(is_array($headerValue)) ? implode(" ",$headerValue) : $headerValue."\n";
        }
        return $ret;
    }
        
    /**
     * Message::prepare()
     */
    protected function prepare()
    {
        $this->messageFrom      = ($this->headerExists('From')) ? \mailparse_rfc822_parse_addresses($this->headerGet('From'))[0] : null;
        $this->messageReplyTo   = ($this->headerExists('Reply-to')) ? \mailparse_rfc822_parse_addresses($this->headerGet('Reply-to'))[0] : null;
        $this->messageTo        = ($this->headerExists('To')) ? \mailparse_rfc822_parse_addresses($this->headerGet('To')) : null;
        $this->messageCc        = ($this->headerExists('Cc')) ? \mailparse_rfc822_parse_addresses($this->headerGet('Cc')) : null;
        $this->messageBcc       = ($this->headerExists('Bcc')) ? \mailparse_rfc822_parse_addresses($this->headerGet('Bcc')) : null;
        $this->messageSubject   = $this->headerGet('Subject', self::NO_SUBJECT);
        $this->messageDate      = $this->headerGet('Date',null);
        $this->messageId        = $this->headerGet('Message-ID');
        return $this;
    }
    
    /**
     * Message::setFlags()
     */
    public function setFlags($flags){
        $this->messageFlags  = array_map("strtolower",array_filter(explode(" ",$flags)));
        $this->messageStatus = (in_array('\\seen', $this->messageFlags)) ? true : false;
        return $this;    
    }
    
    /**
     * Message::setInternalDate()
     */
    public function setInternalDate($date = null){
        $this->internalDate  = trim($date);
        if(trim($this->messageDate)=='')
            $this->messageDate = $this->internalDate;
        return $this;    
    }
    
    /**
     * Message::GetBody()
     */
    public function GetBody(){
        return ($this->Html() != '') ? $this->Html() : str_replace("\n",'',$this->Plain());
    }
    
    /**
     * Message::headerExists()
     */
    public function headerExists($key)
    {
        return Arr::has($this->messageheaders, strtolower($key));
    }

    /**
     * Message::setOptions()
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
     */
    public function headerGet($key, $default = null)
    {
        $arrValue = Arr::get($this->messageheaders, strtolower($key), $default);
        return filter_var($arrValue, FILTER_UNSAFE_RAW, FILTER_FLAG_ENCODE_LOW|FILTER_FLAG_STRIP_HIGH);
    }
    
    public function getSendingIp(){
        return ($this->headerExists('x-originating-ip')) ? trim(str_replace(['[',']'],'',$this->headerGet('x-originating-ip'))) : null;
    }
            
    /**
     * Message::Plain()
     */
    public function Plain()
    {
        return $this->messagePlain;
    }

    /**
     * Message::rawMessage()
     */
    public function rawMessage()
    {
        return $this->rawMessageContent;
    }
    
    /**
     * Message::Html()
     */
    public function Html()
    {
        return $this->messageHtml;
    }

    /**
     * Message::SetHtml()
     */
    public function SetHtml($sHtml)
    {
        $this->messageHtml = $sHtml;
        return $this;
    }

    /**
     * Message::SetPlain()
     */
    public function SetPlain($plain)
    {
        $this->messagePlain = $plain;
        return $this;
    }
    
    /**
     * Message::Status()
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
     */
    public function Folder()
    {
        return $this->messageFolder;
    }

    /**
     * Message::Uid()
     */
    public function Uid()
    {
        return $this->messageUid;
    }

    /**
     * Message::MessageId()
     */
    public function MessageId()
    {
        return $this->sMessageId;
    }

    /**
     * Message::Subject()
     */
    public function Subject()
    {
        return $this->messageSubject;
    }

    /**
     * Message::ContentType()
     */
    public function ContentType()
    {
        return $this->messageContentType;
    }

    /**
     * Message::Size()
     */
    public function Size()
    {
        return $this->messageSize;
    }

    /**
     * Message::Date()
     */
    public function Date()
    {
        return $this->messageDate;
    }

    /**
     * Message::Flags()
     */
    public function Flags()
    {
        return $this->messageFlags;
    }

    /**
     * Message::From()
     */
    public function From()
    {
        return $this->messageFrom;
    }

    /**
     * Message::ReplyTo()
     */
    public function ReplyTo()
    {
        return $this->messageReplyTo;
    }

    /**
     * Message::To()
     */
    public function To()
    {
        return $this->messageTo;
    }

    /**
     * Message::Cc()
     */
    public function Cc()
    {
        return $this->messageCc;
    }

    /**
     * Message::Bcc()
     */
    public function Bcc()
    {
        return $this->messageBcc;
    }

    /**
     * Message::Attachments()
     */
    public function Attachments()
    {
        return $this->messageAttachments;
    }
}