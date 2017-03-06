<?php
namespace SapiStudio\SapiMail;

use PhpMimeMailParser\Parser as PhpMimeMailParser;
use Illuminate\Support\Collection;

/**
 * EmailParser
 */
class EmailParser extends PhpMimeMailParser
{
    public $contentPartsData = null;
    protected $defaultMimeTypes = ['text' => 'text/plain', 'html' => 'text/html'];
    protected $defaultPart      = 1;
    protected $messageflags     = [];
    protected $messageUid       = null;
    
    /**
     * EmailParser::make()
     * 
     * @param mixed $raw
     * @return
     */
    public static function make($raw){
        return (new self())->loadMailText($raw);
    }
    
    /**
     * EmailParser::__construct()
     * 
     * @param mixed $messageId
     * @param mixed $flags
     * @return
     */
    public function __construct($messageId=null,$flags=null)
    {
        parent::__construct();
        $this->contentPartsData = (new Collection());
    }
    
    /**
     * EmailParser::loadMailText()
     * 
     * @param mixed $data
     * @return
     */
    public function loadMailText($data)
    {
        $this->setText($data);
        $parts = [];
        foreach ($this->parts as $part_id => $contentPart)
        {
            $contentType = $this->getPart('content-type', $contentPart);
            $total = count(explode('.', $part_id));
            if (in_array($contentType, $this->defaultMimeTypes))
            {
                if(count($this->parts)==1){
                    $keyIndex = $total;
                    $parts[$keyIndex]['type']       = $contentType;
                    $parts[$keyIndex]['headers']    = $this->getContentHeaders($contentPart);
                    $parts[$keyIndex]['headersRaw'] = $this->getPartHeader($contentPart);
                    $parts[$keyIndex]['rawContent'] = $this->getPartComplete($contentPart);
                    $this->contentPartsData->put($contentType,$keyIndex);
                }else
                    $keyIndex = $total - 1;
                $parts[$keyIndex][array_search($contentType, $this->defaultMimeTypes)] = $this->getBody($contentPart);
            }else
            {
                if (!isset($parts[$total]))
                {
                    $parts[$total]['type']       = $contentType;
                    if($total!=$this->defaultPart)
                        $parts[$total]['text']   = $this->getBody($contentPart);
                    $parts[$total]['headers']    = $this->getContentHeaders($contentPart);
                    $parts[$total]['headersRaw'] = $this->getPartHeader($contentPart);
                    $parts[$total]['rawContent'] = $this->getPartComplete($contentPart);
                    $this->contentPartsData->put($contentType,$total);
                }
            }
        }
        $this->contentPartsData->put('data',$parts);
        return $this;
    }
    
    /**
     * EmailParser::getMessage()
     * 
     * @param mixed $format
     * @return
     */
    public function getMessage($format=null){
        $contentPart = $this->contentPartsData->get($format);
        $contentPart = (!$contentPart) ? $this->defaultPart : $contentPart;
        return $this->contentPartsData->get('data')[$contentPart];
    }
    
    /**
     * EmailParser::getContentHeaders()
     * 
     * @param mixed $contentPart
     * @return
     */
    public function getContentHeaders($contentPart)
    {
        $headers = $this->getPart('headers',$contentPart);
        if ($headers) {
            foreach ($headers as $name => &$value) {
                if (is_array($value)) {
                    foreach ($value as &$v) {
                        $v = $this->decodeSingleHeader($v);
                    }
                } else {
                    $value = $this->decodeSingleHeader($value);
                }
            }
            return $headers;
        }
        return false;
    }
    
    /**
     * EmailParser::getBody()
     * 
     * @param mixed $contentPart
     * @return
     */
    protected function getBody($contentPart){
        return $this->charset->decodeCharset($this->decodeContentTransfer($this->getPartBody($contentPart), $this->getEncodingType($contentPart)), $this->getPartCharset($contentPart));
    }
    
    /**
     * EmailParser::getEncodingType()
     * 
     * @param mixed $contentPart
     * @return
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
