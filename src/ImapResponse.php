<?php
namespace SapiStudio\SapiMail;

class ImapResponse
{
    public $ResponseList;
    public $StatusOrIndex;
    public $HumanReadable;
    public $ResponseTag;

    private function __construct($line)
    {
        $this->HumanReadable = $line;
        $this->lineToResponse();
        return $this;
    }

    public static function make($line)
    {
        return new self($line);
    }

    private function lineToResponse()
    {
        $line = preg_replace_callback("/\(.*\)/", function($s) {return str_replace(" ", "+", "$s[0]");}, $this->HumanReadable);
        $this->ResponseList = array_map(function($line) { preg_match("/\((.*?)\)/", $line,$match);if($match){return explode("+",$match[1]);}return trim(trim($line), '"');},explode(' ',$line));
        $this->StatusOrIndex = $this->ResponseList[1];
        $this->ResponseTag   = $this->ResponseList[0];
    }
    
    public static function getStatusResponse($response=null,$status=null){
        if(!$response)
            return false;
        foreach($response as $a=>$ImapResponse){
            if($ImapResponse->StatusOrIndex==$status)
                return $ImapResponse;
        }
        return false;
    }
}