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
            if($total == 1){
                $keyIndex = $this->defaultPart;
                $parts[$keyIndex]['headers']    = $this->getContentHeaders($contentPart);
            }elseif($total == 2 && !in_array($contentType, $this->defaultMimeTypes)){
                $keyIndex =array_sum(explode('.', $part_id));
                $parser = (new PhpMimeMailParser())->setText($this->getDecodedBody($contentPart)."\n");
                $parts[$keyIndex]['headers']    = $parser->getHeaders();
                $parts[$keyIndex]['text']       = $parser->getMessageBody('text');
                $parts[$keyIndex]['html']       = $parser->getMessageBody('html');
            }elseif($total == 2 && in_array($contentType, $this->defaultMimeTypes)){
                $parts[$this->defaultPart][array_search($contentType, $this->defaultMimeTypes)] = $this->getDecodedBody($contentPart);
                continue;
            }else{
                continue;
            }
            $this->contentPartsData->put($contentType,$keyIndex);
            $parts[$keyIndex][array_search($contentType, $this->defaultMimeTypes)] = $this->getDecodedBody($contentPart);
            $parts[$keyIndex]['raw']        = $this->getDecodedBody($contentPart);
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
     * EmailParser::getDecodedBody()
     */
    protected function getDecodedBody($contentPart){
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