<?php
namespace SapiStudio\SapiMail;
use SapiStudio\SapiMail\Enumerations\FolderResponseStatus;
use SapiStudio\SapiMail\Enumerations\FetchResponse;
use SapiStudio\SapiMail\Enumerations\FetchTypes;
use SapiStudio\SapiMail\Enumerations\MessageFlag;
use SapiStudio\SapiMail\Enumerations\ResponseStatus;

class ImapClient
{
    public $BindIp              = null;
    const TIMEOUT               = 30;
    protected $host             = null;
    protected $port             = null;
    protected $ssl              = false;
    protected $tls              = false;
    protected $username         = null;
    protected $password         = null;
    protected $currentTag       = 0;
    protected $mailboxTotal     = 0;
    protected $next             = 0;
    protected $bufferResponse   = null;
    protected $commandResponse  = null;
    protected $socket           = null;
    protected $currentMailbox   = null;
    protected $mailboxes        = [];
    protected $isConnected      = false;
    private $debugging          = false;
    private $rawCommands        = [];
    
    /**
     * ImapClient::make()
     * 
     * @return
     */
    public static function make(){
        return new self;
    }
    
    /**
     * ImapClient::connect()
     * 
     * @param mixed $host
     * @param mixed $port
     * @param bool $ssl
     * @param bool $tls
     * @param mixed $timeout
     * @param bool $test
     * @return
     */
    public function connect($host,$port = null, $ssl = false, $tls = false,$timeout = self::TIMEOUT, $test = false)
    {
        if (is_null($port))
            $port   = $ssl ? 993 : 143;
        $this->host = $host;
        $this->port = $port;
        $this->ssl  = $ssl;
        $this->tls  = $tls;                
        if ($this->socket)
            return $this;
        $host = $this->host;
        if ($this->ssl)
            $host = 'ssl://' . $host;
        $context = stream_context_create(['ssl' => ['verify_peer' => false,'verify_peer_name' => false, 'allow_self_signed' => true]]);
        if ($this->BindIp != '')
            $context = ['socket' => ['bindto' => $this->BindIp . ':0']];
        $this->socket = stream_socket_client($host . ':' . $this->port . '', $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (!$this->socket)
            throw new Exception(exception::SERVER_ERROR);           
        if ($this->makeResponseLine()->StatusOrIndex != ResponseStatus::OK)
        {
            $this->LogoutAndDisconnect();
            throw new Exception(exception::SERVER_ERROR);
        }
        if ($this->tls)
        {
            $this->putRawCommand(FetchTypes::STARTTLS);
            if (!stream_socket_enable_crypto($this->socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT))
            {
                $this->LogoutAndDisconnect();
                throw new Exception(exception::TLS_ERROR);
            }
        }
        if ($test)
        {
            fclose($this->socket);
            $this->socket = null;
            return $this;
        }
        $this->isConnected = true;        
        return $this;
    }
    
    /**
     * ImapClient::Login()
     * 
     * @param mixed $user
     * @param mixed $password
     * @return
     */
    public function Login($user,$password)
    {
        if (!$this->isConnected())
            return false;
        $this->sendCommand(FetchTypes::LOGIN, $this->escape($user,$password));
        if (!$this->responseStatus())
        {
            $this->LogoutAndDisconnect();
            throw new Exception(exception::LOGIN_ERROR);
        }
        return $this;
    }
    
    /**
     * ImapClient::isConnected()
     * 
     * @return
     */
    public function isConnected(){
        return $this->isConnected;
    }
    
    /**
     * ImapClient::SetBind()
     * 
     * @param mixed $ip
     * @return
     */
    public function SetBind($ip)
    {
        $this->BindIp = $ip;
    }

    /**
     * ImapClient::LogoutAndDisconnect()
     * 
     * @return
     */
    public function LogoutAndDisconnect()
    {
        if ($this->socket)
        {
            $this->putRawCommand(FetchTypes::LOGOUT);
            fclose($this->socket);
            $this->socket = null;
        }
        return $this;
    }

    /**
     * ImapClient::getActiveMailbox()
     * 
     * @return
     */
    public function getActiveMailbox()
    {
        return $this->currentMailbox;
    }
   
    /**
     * ImapClient::getEmailTotal()
     * 
     * @return
     */
    public function getEmailTotal()
    {
        return $this->mailboxTotal;
    }

    /**
     * ImapClient::getNextUid()
     * 
     * @return
     */
    public function getNextUid()
    {
        return $this->next;
    }

    /**
     * ImapClient::MessageList()
     * 
     * @param integer $start
     * @param integer $range
     * @return
     */
    public function MessageList($start = 0, $range = 1)
    {
        if (!$this->isConnected())
            return false;
        if ($this->mailboxTotal == 0 || $start>$this->mailboxTotal)
            return [];
        $range = $range > 0 ? $range : 1;
        $start = $start >= 0 ? $start : 0;
        $max = $this->mailboxTotal - $start;
        if ($max < 1)
            $max = $this->mailboxTotal;
        $min = $max - $range + 1;
        if ($min < 1)
            $min = 1;
        $set = $min . ':' . $max;
        if ($min == $max)
            $set = $min;
        $emails = array_reverse($this->getEmailResponse(FetchTypes::FETCH, [$set, $this->getList([FetchTypes::UID,FetchTypes::FLAGS,FetchTypes::BODY_HEADER_PEEK,FetchTypes::RFC822_SIZE])]));
        return $emails;
    }

    /**
     * ImapClient::getEmailsDetails()
     * 
     * @param mixed $uid
     * @return
     */
    public function getEmailsDetails($uid)
    {
        if (!$this->isConnected())
            return false;          
        if ($this->mailboxTotal == 0)
            return [];
        if (is_array($uid))
            $uid = implode(',', $uid);
        $response = $this->getEmailResponse(FetchTypes::UID_FETCH,[$uid, $this->getList([FetchTypes::UID,FetchTypes::FLAGS,FetchTypes::BODY,FetchTypes::RFC822_SIZE])]);
        return (is_numeric($uid)) ? $response[$uid] : $response;
    }

    /**
     * ImapClient::getUids()
     * 
     * @return
     */
    public function getUids()
    {
        return $this->getEmailResponse(FetchTypes::FETCH,[1 . ':' . $this->mailboxTotal, $this->getList([FetchTypes::UID])]);
    }

    /**
     * ImapClient::FolderSelect()
     * 
     * @param mixed $mailbox
     * @return
     */
    public function FolderSelect($mailbox)
    {
        if (!$this->isConnected() || !$mailbox)
            return false;
        $response = $this->sendCommand(FetchTypes::SELECT, $this->escape($mailbox));
        if (!$this->responseStatus())
            return false;
        foreach ($response as $line)
        {
            if (strpos($line->HumanReadable,FetchResponse::EXISTS) !== false){
                $this->mailboxTotal = $line->ResponseList[1];
                continue;
            }
            if (strpos($line->HumanReadable,FetchResponse::UIDNEXT) !== false){
                $this->next = filter_var($line->ResponseList[3], FILTER_SANITIZE_NUMBER_INT);
                continue;
            }
            if (strpos($line->HumanReadable,FetchResponse::RECENT) !== false){
                $this->unseenMails = filter_var($line->ResponseList[1], FILTER_SANITIZE_NUMBER_INT);
                continue;
            }
            if ($this->mailboxTotal && $this->next && $this->unseenMails)
                break;
        }
        $this->currentMailbox = $mailbox;
        //$this->MessageSetSeen('1:*',false);
        //$this->MessageMove(66,'Sent');
        //$this->createFolder('returned');
        return $this;
    }
    
    /**
     * ImapClient::getMailboxes()
     * 
     * @return
     */
    public function getMailboxes()
    {
        if (!$this->isConnected())
            return false;
        $response  = $this->sendCommand(FetchTypes::F_LIST, $this->escape('', '*'));
        $mailboxes = [];
        $aTypes    = [FolderResponseStatus::MESSAGES,FolderResponseStatus::UNSEEN,FolderResponseStatus::UIDNEXT,FolderResponseStatus::HIGHESTMODSEQ];
        foreach ($response as $line)
        {
            $folder   = ImapFolder::make($line->ResponseList[4],$line->ResponseList[3],$line->ResponseList[2]);
            $response = ImapResponse::getStatusResponse($this->sendCommand(FetchTypes::STATUS,$this->escape($line->ResponseList[4]).'('.implode(' ',$aTypes).')'),FetchTypes::STATUS);
            if($response){
                $data = array_chunk($response->ResponseList[3], 2);
                foreach($data as $a=>$b){
                    list($key,$value)=$b;
                    $details[$key]=$value;
                }
            }
            $folder->setDetails($details);
            $mailboxes[] = $folder;
        }
        return $mailboxes;
    }
    
    /**
     * ImapClient::MessageMove()
     * 
     * @param mixed $uid
     * @param mixed $mailbox
     * @return
     */
    public function MessageMove($uid, $mailbox)
    {
        if (!$this->isConnected())
            return false;
        $this->sendCommand(FetchTypes::UID_COPY,[$uid,$mailbox]);
        return $this->MessageRemove($uid);
    }
	
    public function MoveMessages($uids=[], $mailbox)
    {
        if (!$this->isConnected())
            return false;
        return $this->sendCommand(FetchTypes::UID_MOVE,[implode(',',$uids),$mailbox]);
        //return $this->MessageRemove($uid);
    }
	 
    /**
     * ImapClient::MessageCopy()
     * 
     * @param mixed $uid
     * @param mixed $mailbox
     * @return
     */
    public function MessageCopy($uid,$mailbox)
    {
        if (!$this->isConnected())
            return false;
        return $this->sendCommand(FetchTypes::UID_COPY,[$uid,$mailbox]);
    }
    
    
    
    /**
     * ImapClient::MessageSetSeen()
     * 
     * @param mixed $Uid
     * @param bool $bSetAction
     * @return
     */
    public function MessageSetSeen($Uid, $bSetAction = true)
	{
        $this->MessageStore([$Uid,$bSetAction ? FetchTypes::ADD_FLAGS_SILENT : FetchTypes::REMOVE_FLAGS_SILENT,$this->getList([MessageFlag::SEEN])],is_numeric($Uid) ? true : false);
	}
    
    /**
     * ImapClient::MessageSetSeenAll()
     * 
     * @param bool $bSetAction
     * @return
     */
    public function MessageSetSeenAll($bSetAction = true)
	{
        $this->MessageStore(['1:*',$bSetAction ? FetchTypes::ADD_FLAGS_SILENT : FetchTypes::REMOVE_FLAGS_SILENT,$this->getList([MessageFlag::SEEN])],false);
	}
    
	/**
	 * ImapClient::MessageStore()
	 * 
	 * @param mixed $parameters
	 * @param bool $IsUid
	 * @return
	 */
	protected function MessageStore($parameters=[], $IsUid = true)
	{
	   if (!$this->isConnected())
            return false;
		return $this->sendCommand(($IsUid) ? FetchTypes::UID_STORE : FetchTypes::STORE,$parameters);
	}
    
    /**
     * ImapClient::MessageRemove()
     * 
     * @param mixed $uid
     * @return
     */
    public function MessageRemove($uid)
    {
        $this->MessageStore([$uid, FetchTypes::ADD_FLAGS_SILENT ,$this->getList([MessageFlag::DELETED])]);
        return $this->MessageExpunge();
    }

    /**
     * ImapClient::MessageExpunge()
     * 
     * @return
     */
    public function MessageExpunge()
    {
        $this->sendCommand(FetchTypes::EXPUNGE);
        return $this;
    }

    /**
     * ImapClient::FolderCreate()
     * 
     * @param mixed $mailbox
     * @return
     */
    public function FolderCreate($mailbox)
    {
        $result = $this->sendCommand(FetchTypes::CREATE, $this->escape($mailbox));
    }
    
    /**
     * ImapClient::FolderDelete()
     * 
     * @param mixed $mailbox
     * @return
     */
    public function FolderDelete($mailbox)
    {
        $result = $this->sendCommand(FetchTypes::DELETE, $this->escape($mailbox));
    }
    
    /**
     * ImapClient::getEmailResponse()
     * 
     * @param mixed $command
     * @param mixed $parameters
     * @return
     */
    private function getEmailResponse($command, $parameters = [])
    {
        $currentEmail = $index = 0;
        $uids         = [];
        $response = $this->sendCommand($command, $parameters);
        if(!$response)
            return false;
        foreach($response as $key => $responseLine){
            $line = $responseLine->HumanReadable;
            $response[$index] = $line;
            if (isset($responseLine->ResponseList[2]) && $responseLine->ResponseList[2] == FetchTypes::FETCH)
            {
                $mailsData[$currentEmail]['line'] = $line;
                if (isset($mailsData[$currentEmail - 1]))
                    $mailsData[$currentEmail - 1]['end'] = $index - 1;
                $mailsData[$currentEmail]['start'] = $index + 1;
                $currentEmail++;
            }
            if ($key == count($response)-1)
            {
                if (isset($mailsData[$currentEmail - 1]))
                    $mailsData[$currentEmail - 1]['end'] = $index - 1;
                break;
            }
            $index++;
        }
        if ($mailsData)
        {
            foreach ($mailsData as $key => $details)
            {
                $emailRaw = array_slice($response, $details['start'], ($details['end'] - $details['start'] + 1));
                if (!empty($emailRaw) && strpos(trim($emailRaw[count($emailRaw) - 1]), ')') === 0)
                    $emailRaw[count($emailRaw) - 1]=null;
                preg_match("/.*UID\s(\d+).*/", $details['line'], $searchUids);
                preg_match("/FLAGS \((.*?)\)/", $details['line'], $searchFlags);
                preg_match("/RFC822.SIZE (.*?) /", $details['line'], $searchSize);
                $iUids = filter_var($searchUids[1], FILTER_SANITIZE_NUMBER_INT);
                $uids[] = $iUids;
                if($emailRaw){
                    $emails[$iUids] = ImapMessage::make($emailRaw)->setFlags($searchFlags[1])->setOptions(['messageFolder' => $this->currentMailbox, 'messageUid' => $iUids, 'messageSize' => filter_var($searchSize[1],FILTER_SANITIZE_NUMBER_INT)])->load();
                }
            }
        }
        if(!$emails)
            return $uids;
        return $emails;
    }

    /**
     * Imap::getList()
     * 
     * @param mixed $array
     * @return
     */
    /**
     * ImapClient::getList()
     * 
     * @param mixed $array
     * @return
     */
    private function getList($array)
    {
        $list = [];
        foreach ($array as $key => $value)
            $list[] = !is_array($value) ? $value : $this->getList($v);
        return '(' . implode(' ', $list) . ')';
    }

    /**
     * Imap::search()
     * 
     * @param mixed $filter
     * @param integer $start
     * @param integer $range
     * @param bool $or
     * @param bool $body
     * @return
     */
    /**
     * ImapClient::search()
     * 
     * @param mixed $filter
     * @param integer $start
     * @param integer $range
     * @param bool $or
     * @param bool $body
     * @return
     */
    public function search(array $filter, $start = 0, $range = 10, $or = false, $body = false)
    {
        if (!$this->isConnected())
            return false;
        $search = $not = [];
        foreach ($filter as $where)
        {
            if (is_string($where))
            {
                $search[] = $where;
                continue;
            }
            if ($where[0] == 'NOT')
            {
                $not = $where[1];
                continue;
            }
            $item = $where[0] . ' "' . $where[1] . '"';
            if (isset($where[2]))
                $item .= ' "' . $where[2] . '"';
            $search[] = $item;
        }

        if ($or && count($search) > 1)
        {
            $query = null;
            while ($item = array_pop($search))
            {
                $query = (is_null($query)) ? $item : (strpos($query, 'OR') !== 0) ? 'OR (' . $query . ') (' . $item . ')' : 'OR (' . $item . ') (' . $query . ')';
            }
            $search = $query;
        } else
            $search = implode(' ', $search);
        $response = $this->sendCommand(FetchTypes::UID_SEARCH.' '.$search);
        if ($this->responseStatus())
        {
            $uids = explode(' ', $response[0]);
            array_shift($uids);
            array_shift($uids);
            foreach ($uids as $i => $uid)
            {
                if (in_array($uid, $not))
                    unset($uids[$i]);
            }
            if (empty($uids))
                return [];
            $uids = array_reverse($uids);
            $count = 0;
            foreach ($uids as $i => $id)
            {
                if ($i < $start)
                {
                    unset($uids[$i]);
                    continue;
                }
                $count++;
                if ($range != 0 && $count > $range)
                {
                    unset($uids[$i]);
                    continue;
                }
            }
            return $this->getUniqueEmails($uids, $body);
        }
        return [];
    }

    /**
     * Imap::searchTotal()
     * 
     * @param mixed $filter
     * @param bool $or
     * @return
     */
    /**
     * ImapClient::searchTotal()
     * 
     * @param mixed $filter
     * @param bool $or
     * @return
     */
    public function searchTotal(array $filter, $or = false)
    {
        if (!$this->isConnected())
            return false;
        $search = array();
        foreach ($filter as $where)
        {
            $item = $where[0] . ' "' . $where[1] . '"';
            if (isset($where[2]))
                $item .= ' "' . $where[2] . '"';
            $search[] = $item;
        }
        $search   = ($or) ? 'OR (' . implode(') (', $search) . ')' : implode(' ', $search);
        $response = $this->sendCommand(FetchTypes::UID_SEARCH.' '.$search);
        if ($this->responseStatus())
        {
            $uids = explode(' ', $response[0]);
            array_shift($uids);
            array_shift($uids);
            return count($uids);
        }
        return 0;
    }
    
    /**
     * ImapClient::responseStatus()
     * 
     * @return
     */
    protected function responseStatus(){
        return ($this->commandResponse->StatusOrIndex == ResponseStatus::OK) ? true : false;
    }
    
    /**
     * ImapClient::sendCommand()
     * 
     * @param mixed $command
     * @param mixed $parameters
     * @return
     */
    protected function sendCommand($command, $parameters = [])
    {
        if (!$this->putRawCommand($command, $parameters))
            return false;
        return $this->receiveResponse();
    }

    /**
     * ImapClient::makeResponseLine()
     * 
     * @return
     */
    protected function makeResponseLine()
    {
        return ImapResponse::make(trim($this->getRawResponseLine(),"\n"));
    }
    
    /**
     * ImapClient::getRawResponseLine()
     * 
     * @return
     */
    protected function getRawResponseLine()
    {
        $line = fgets($this->socket);
        if ($line === false)
            $this->LogoutAndDisconnect();
        $this->debug('Receiving: ' . $line);
        return $line;
    }

    /**
     * ImapClient::receiveResponse()
     * 
     * @return
     */
    protected function receiveResponse()
    {
        $this->bufferResponse = [];
        $start = time();
        while (time() < ($start + self::TIMEOUT))
        {
            $line     = trim($this->getRawResponseLine(),"\n");
            $response = ImapResponse::make($line);
            $this->bufferResponse[] = $response;
            if ($response->ResponseTag == 'TAG' . $this->currentTag){
                $this->commandResponse = array_pop($this->bufferResponse);
                return $this->bufferResponse;
            }
        }
        return null;
    }

    /**
     * ImapClient::putRawCommand()
     * 
     * @param mixed $command
     * @param mixed $parameters
     * @return
     */
    protected function putRawCommand($command, $parameters = [])
    {
        $this->currentTag++;
        $line = 'TAG' . $this->currentTag . ' ' . $command;
        if (!is_array($parameters))
            $parameters = array($parameters);
        foreach ($parameters as $parameter)
        {
            if (is_array($parameter))
            {
                if (fputs($this->socket, $line . ' ' . $parameter[0] . "\r\n") === false)
                    return false;
                if (strpos($this->getRawResponseLine(), '+ ') === false)
                    return false;
                $line = $parameter[1];
            } else
                $line .= ' ' . $parameter;
        }
        $this->rawCommands['TAG' . $this->currentTag]=$line;
        $this->debug('Sending: ' . $line."\n");
        return fputs($this->socket, $line . "\r\n");
    }

    /**
     * ImapClient::debug()
     * 
     * @param mixed $string
     * @return
     */
    private function debug($string)
    {
        if ($this->debugging)
            echo $string;
        return $this;
    }

    /**
     * ImapClient::escape()
     * 
     * @param mixed $string
     * @return
     */
    private function escape($string)
    {
        if (func_num_args() < 2)
            return (strpos($string, "\n") !== false) ? ['{' . strlen($string) . '}', $string] : '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $string) . '"';
        foreach (func_get_args() as $string)
            $result[] = $this->escape($string);
        return $result;
    }
}
