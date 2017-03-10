<?php
namespace SapiStudio\SapiMail;

class ImapFolder
{
    private $sNameRaw;
    private $sFullNameRaw;
    private $Delimiter;
    private $aFlags;
    private $aFlagsLowerCase;
    private $details = [];

    private function __construct($sFullNameRaw, $Delimiter, array $aFlags)
    {
        $this->sNameRaw     = '';
        $this->sFullNameRaw = '';
        $this->sDelimiter   = '';
        $this->aFlags       = [];
        $Delimiter = 'NIL' === \strtoupper($Delimiter) ? '' : $Delimiter;
        if (empty($Delimiter))
            $Delimiter = '.';
        if (!\is_array($aFlags) || !\is_string($Delimiter) || 1 < \strlen($Delimiter) || !\is_string($sFullNameRaw) || 0 === \strlen($sFullNameRaw))
        {
            throw new \MailSo\Base\Exceptions\InvalidArgumentException();
        }
        $this->sFullNameRaw = $sFullNameRaw;
        $this->sDelimiter   = $Delimiter;
        $this->aFlags       = $aFlags;
        $this->aFlagsLowerCase = \array_map('strtolower', $this->aFlags);
        $this->sFullNameRaw = 'INBOX' . $this->sDelimiter === \substr(\strtoupper($this->sFullNameRaw), 0, 5 + \strlen($this->sDelimiter)) ? 'INBOX' . \substr($this->sFullNameRaw, 5) : $this->sFullNameRaw;
        if ($this->IsInbox())
            $this->sFullNameRaw = 'INBOX';
        $this->sNameRaw = $this->sFullNameRaw;
        if (0 < \strlen($this->sDelimiter))
        {
            $aNames = \explode($this->sDelimiter, $this->sFullNameRaw);
            $this->sNameRaw = \end($aNames);
        }
    }

    public static function make($sFullNameRaw, $sDelimiter = '.', $aFlags =[])
    {
        return new self($sFullNameRaw, $sDelimiter, $aFlags);
    }

    public function NameRaw()
    {
        return $this->sNameRaw;
    }

    public function FullNameRaw()
    {
        return $this->sFullNameRaw;
    }

    public function Delimiter()
    {
        return $this->sDelimiter;
    }

    public function Flags()
    {
        return $this->aFlags;
    }

    public function FlagsLowerCase()
    {
        return $this->aFlagsLowerCase;
    }

    public function IsSelectable()
    {
        return !\in_array('\noselect', $this->aFlagsLowerCase);
    }

    public function IsInbox()
    {
        return 'INBOX' === \strtoupper($this->sFullNameRaw) || \in_array('\inbox', $this->aFlagsLowerCase);
    }
    
    public function getDetails($key=null){
        return (isset($this->details[$key])) ? $this->details[$key] : null;
    }
    
    public function setDetails($details=[]){
        $this->details = $details;
        return $this;
    }
}