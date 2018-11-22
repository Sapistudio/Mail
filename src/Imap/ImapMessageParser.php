<?php
namespace SapiStudio\SapiMail\Imap;

use PhpMimeMailParser\Parser as PhpMimeMailParser;
use Illuminate\Support\Collection;

class ImapMessageParser extends PhpMimeMailParser
{
    public $contentPartsData = null;
    protected $defaultMimeTypes = ['text' => 'text/plain', 'html' => 'text/html'];
    protected $defaultPart      = 1;
    protected $defaultFormat    = null;
    
    /**
     * EmailParser::make()
     */
    public static function make($raw){
        return (new self())->loadMailText($raw);
    }
    
    /**
     * EmailParser::__construct()
     */
    public function __construct($messageId=null,$flags=null)
    {
        parent::__construct();
        $this->contentPartsData = (new Collection());
    }
    
    /**
     * EmailParser::loadMailText()
     */
    public function loadMailText($data)
    {
        $this->setText($data);
        $start = 1;
        
        foreach ($this->parts as $part_id => $contentPart)
        {
            $contentType = $this->getPart('content-type', $contentPart);
            $total       = count(explode('.', $part_id));
            if (in_array($contentType, $this->defaultMimeTypes))
            {
                if(count($this->parts)==1 || $part_id==$this->defaultPart){
                    $keyIndex = $start;
                    $parts[$keyIndex]['type']       = $contentType;
                    $parts[$keyIndex]['headers']    = $this->getContentHeaders($contentPart);
                    $this->contentPartsData->put($contentType,$keyIndex);
                }else{
                    $currentIndex   = $start - 1;
                    $keyIndex       = ($total==$currentIndex) ? $currentIndex - 1 : $currentIndex;
                    $parts[$keyIndex]['headers']    = array_merge($parts[$currentIndex]['headers'],$this->getContentHeaders($contentPart));
                }
                $keyToWrite = ($this->defaultFormat != 'multipart/alternative') ? $this->defaultPart : $keyIndex;
                $parts[$keyToWrite][array_search($contentType, $this->defaultMimeTypes)] = $this->getBody($contentPart);
            }else
            {
                if($start == $this->defaultPart)
                    $this->defaultFormat = $contentType;
                $parts[$start]['type']       = $contentType;
                if($start!=$this->defaultPart)
                    $parts[$start]['text']   = $this->getBody($contentPart);
                $parts[$start]['headers']    = $this->getContentHeaders($contentPart);
                $this->contentPartsData->put($contentType,$start);
                $start++;
            }
        }
        $this->contentPartsData->put('data',$parts);
        return $this;
    }
    
    /**
     * EmailParser::getMessage()
     */
    public function getMessage($format=null){
        $contentPart = (is_null($format)) ? $this->defaultPart : $this->loadMessageFormat($format);                
        return $this->contentPartsData->get('data')[$contentPart];
    }
    
    /**
     * EmailParser::loadMessageFormat()
     */
    public function loadMessageFormat($format){
        return $this->contentPartsData->get($format);   
    }  
            
    /**
     * EmailParser::getContentHeaders()
     */
    public function getContentHeaders($contentPart)
    {
        $headers = $this->getPart('headers',$contentPart);
        if ($headers) {
            foreach ($headers as $name => &$value) {
                if (is_array($value)) {
                    foreach ($value as &$v) {
                        //$v = $this->decodeSingleHeader($v);
                        $v = $this->decodeMimeHeader($v);
                    }
                } else {
                    $value = $this->decodeMimeHeader($value);
                }
            }
            return $headers;
        }
        return false;
    }
    
    /**
     * EmailParser::decodeMimeHeader()
     */
    protected function decodeMimeHeader($str)
    {
        if (strpos($str, '=?') === false)
            return $str;
        $value = mb_decode_mimeheader($str);
        if (strpos($str, '?Q') !== false)
            $value = str_replace('_', ' ', $value);
        return filter_var($value, FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW|FILTER_FLAG_STRIP_HIGH);
    }
    
    /**
     * EmailParser::getBody()
     */
    protected function getBody($contentPart){
        return $this->charset->decodeCharset($this->decodeContentTransfer($this->getPartBody($contentPart), $this->getEncodingType($contentPart)), $this->getPartCharset($contentPart));
    }
    
    /**
     * EmailParser::getEncodingType()
     */
    protected function getEncodingType($contentPart)
    {
        $headers = $this->getPart('headers', $contentPart);
        $encodingType = array_key_exists('content-transfer-encoding', $headers) ? $headers['content-transfer-encoding'] : '';
        if (is_array($encodingType))
            $encodingType = $encodingType[0];
        return $encodingType;
    }
}