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
    protected $nextUid          = 0;
    protected $bufferResponse   = null;
    protected $commandResponse  = null;
    protected $socket           = null;
    protected $currentMailbox   = null;
    protected $mailboxes        = [];
    protected $isConnected      = false;
    private $debugging          = false;
    private $rawCommands        = [];
    protected $mailsPagination  = 25;
    
    /** ImapClient::make()*/
    public static function make(){
        return new self;
    }
    
    /** ImapClient::testConnection()  */
    public static function testConnection($host=null,$port = null, $ssl = true, $tls = false){
        return self::make()->initConnection($host,$port, $ssl, $tls)->LogoutAndDisconnect();
    }
    
    /** ImapClient::initConnection() */
    public function initConnection($host=null,$port = null, $ssl = true, $tls = false)
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
        $contextOpts = ['ssl' => ['verify_peer' => false,'verify_peer_name' => false, 'allow_self_signed' => true]];
        if ($this->BindIp != '')
            $contextOpts['socket'] = ['bindto' => $this->BindIp . ':0'];
        $context        = stream_context_create($contextOpts);
        $this->socket = stream_socket_client($host . ':' . $this->port . '', $errno, $errstr, self::TIMEOUT, STREAM_CLIENT_CONNECT, $context);
        if (!$this->socket)
            throw new Exception(sprintf(exception::SERVER_ERROR, $host));
        if ($this->makeResponseLine()->StatusOrIndex != ResponseStatus::OK)
        {
            $this->LogoutAndDisconnect();
            throw new Exception(sprintf(exception::SERVER_ERROR, $host));
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
        $this->isConnected = true;        
        return $this;
    }
    
    /** ImapClient::loginToMailbox()*/
    public function loginToMailbox($user,$password)
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
    
    /** ImapClient::isConnected() */
    public function isConnected(){
        return $this->isConnected;
    }
    
    /** ImapClient::SetBind()  */
    public function SetBind($ip)
    {
        $this->BindIp = $ip;
    }
    
    /** ImapClient::setMailsPagination() */
    public function setMailsPagination($totalMails){
        $this->mailsPagination = $totalMails;
        return $this;
    }
    
    /** ImapClient::LogoutAndDisconnect()*/
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

    /** ImapClient::getActiveMailbox() */
    public function getActiveMailbox()
    {
        return $this->currentMailbox;
    }
   
    /** ImapClient::getEmailTotal() */
    public function getEmailTotal()
    {
        return $this->mailboxTotal;
    }

    /** ImapClient::getNextUid()*/
    public function getNextUid()
    {
        return $this->nextUid;
    }
    
    /** ImapClient::search()  */
    public function search(array $filter, $start = 0, $range = 10, $or = false, $body = false)
    {
        if (!$this->isConnected())
            return false;
        $search = 'ALL';
        $response = $this->sendCommand(FetchTypes::UID_SEARCH.' '.$search);
        if ($this->responseStatus())
        {
            $response   = $response[0];
            $uids       = array_reverse(array_slice($response->ResponseList, 2));
            $this->mailboxTotal = count($uids);
            $uids = array_chunk($this->search(),$this->mailsPagination);
            return array_reverse($this->getEmailResponse(FetchTypes::UID_FETCH, [implode(',',$uids[$offsetPage]), $this->getList([FetchTypes::UID,FetchTypes::FLAGS,FetchTypes::BODYSTRUCTURE,FetchTypes::RFC822_SIZE,FetchTypes::BODY_HEADER_PEEK])]));;
        }
        return [];
    }

    /** ImapClient::MessageList()*/
    public function MessageList($offsetPage = 0)
    {
        $offsetPage = ($offsetPage > 0) ? $offsetPage - 1 : $offsetPage;/** page one equals no page*/
        if (!$this->isConnected())
            return false;
        if ($this->mailboxTotal == 0 || $offsetPage > $this->mailboxTotal)
            return [];
        $range      = $this->mailsPagination > 0 ? $this->mailsPagination : 1;
        $offsetPage = $range * $offsetPage;
        $max = $this->mailboxTotal - $offsetPage;
        if ($max < 1)
            $max = $this->mailboxTotal;
        $min = $max - $range + 1;
        if ($min < 1)
            $min = 1;
        $set = $min . ':' . $max;
        if ($min == $max)
            $set = $min;
        $emails = array_reverse($this->getEmailResponse(FetchTypes::FETCH, [$set, $this->getList([FetchTypes::UID,FetchTypes::FLAGS,FetchTypes::BODYSTRUCTURE,FetchTypes::RFC822_SIZE,FetchTypes::INTERNALDATE,FetchTypes::BODY_HEADER_PEEK])]));
        return $emails;
    }

    /** ImapClient::getEmailsDetails() */
    public function getEmailsDetails($uid)
    {
        if (!$this->isConnected())
            return false;          
        if ($this->mailboxTotal == 0)
            return [];
        if (is_array($uid))
            $uid = implode(',', $uid);
        $response = $this->getEmailResponse(FetchTypes::UID_FETCH,[$uid, $this->getList([FetchTypes::UID,FetchTypes::FLAGS,FetchTypes::BODYSTRUCTURE,FetchTypes::RFC822_SIZE,FetchTypes::INTERNALDATE,FetchTypes::BODY])]);
        return (is_numeric($uid)) ? $response[$uid] : $response;
    }

    /** ImapClient::getUids() */
    public function getUids()
    {
        return $this->getEmailResponse(FetchTypes::FETCH,[1 . ':' . $this->mailboxTotal, $this->getList([FetchTypes::UID])]);
    }

    /** ImapClient::FolderSelect() */
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
                $this->nextUid = filter_var($line->ResponseList[3], FILTER_SANITIZE_NUMBER_INT);
                continue;
            }
            if (strpos($line->HumanReadable,FetchResponse::RECENT) !== false){
                $this->unseenMails = filter_var($line->ResponseList[1], FILTER_SANITIZE_NUMBER_INT);
                continue;
            }
            if ($this->mailboxTotal && $this->nextUid && $this->unseenMails)
                break;
        }
        $this->currentMailbox = $mailbox;
        //$this->MessageSetSeen('1:*',false);
        //$this->MessageMove(66,'Sent');
        //$this->createFolder('returned');
        return $this;
    }
    
    /** ImapClient::getMailboxes() */
    public function getMailboxes()
    {
        if (!$this->isConnected())
            return false;
        $response  = $this->sendCommand(FetchTypes::F_LIST, $this->escape('', '*'));
        $mailboxes = [];
        $aTypes    = [FolderResponseStatus::MESSAGES,FolderResponseStatus::UNSEEN,FolderResponseStatus::UIDNEXT,FolderResponseStatus::HIGHESTMODSEQ];
        foreach ($response as $line)
        {
            $folder   = Imap\ImapFolder::make($line->ResponseList[4],$line->ResponseList[3],$line->ResponseList[2]);
            $response = Imap\ImapResponse::getStatusResponse($this->sendCommand(FetchTypes::STATUS,$this->escape($line->ResponseList[4]).'('.implode(' ',$aTypes).')'),FetchTypes::STATUS);
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
    
    /** ImapClient::MessageMove()*/
    public function MessageMove($uid, $mailbox)
    {
        if (!$this->isConnected())
            return false;
        $this->sendCommand(FetchTypes::UID_COPY,[$uid,$mailbox]);
        return $this->MessageRemove($uid);
    }
	
    /** ImapClient::MoveMessages()*/
    public function MoveMessages($uids=[], $mailbox)
    {
        if (!$this->isConnected())
            return false;
        return $this->sendCommand(FetchTypes::UID_MOVE,[implode(',',$uids),$mailbox]);
        //return $this->MessageRemove($uid);
    }
	 
    /** ImapClient::MessageCopy()*/
    public function MessageCopy($uid,$mailbox)
    {
        if (!$this->isConnected())
            return false;
        return $this->sendCommand(FetchTypes::UID_COPY,[$uid,$mailbox]);
    }
    
    /** ImapClient::MessageSetSeen()*/
    public function MessageSetSeen($Uid, $bSetAction = true)
	{
        $this->MessageStore([$Uid,$bSetAction ? FetchTypes::ADD_FLAGS_SILENT : FetchTypes::REMOVE_FLAGS_SILENT,$this->getList([MessageFlag::SEEN])],is_numeric($Uid) ? true : false);
	}
    
    /** ImapClient::MessageSetSeenAll()*/
    public function MessageSetSeenAll($bSetAction = true)
	{
        $this->MessageStore(['1:*',$bSetAction ? FetchTypes::ADD_FLAGS_SILENT : FetchTypes::REMOVE_FLAGS_SILENT,$this->getList([MessageFlag::SEEN])],false);
	}
    
	/** ImapClient::MessageStore() */
	protected function MessageStore($parameters=[], $IsUid = true)
	{
	   if (!$this->isConnected())
            return false;
		return $this->sendCommand(($IsUid) ? FetchTypes::UID_STORE : FetchTypes::STORE,$parameters);
	}
    
    /** ImapClient::MessageRemove()*/
    public function MessageRemove($uid)
    {
        $this->MessageStore([$uid, FetchTypes::ADD_FLAGS_SILENT ,$this->getList([MessageFlag::DELETED])]);
        return $this->MessageExpunge();
    }

    /** ImapClient::MessageExpunge()*/
    public function MessageExpunge()
    {
        $this->sendCommand(FetchTypes::EXPUNGE);
        return $this;
    }

    /** ImapClient::FolderCreate() */
    public function FolderCreate($mailbox)
    {
        $this->sendCommand(FetchTypes::CREATE, $this->escape($mailbox));
    }
    
    /**  ImapClient::FolderDelete() */
    public function FolderDelete($mailbox)
    {
        $this->sendCommand(FetchTypes::DELETE, $this->escape($mailbox));
    }
    
    /** ImapClient::FolderDelete() */
    public function emptyFolder($mailbox)
    {
        $uids = $this->getUids();
        if($uids){
            foreach($uids as $index=>$uidId){
                $this->MessageRemove($uidId);
            }
        }
        $this->FolderDelete($mailbox);
        $this->FolderCreate($mailbox);
    }
    
    /** ImapClient::getEmailResponse()*/
    private function getEmailResponse($command, $parameters = [])
    {
        $currentEmail = $index = 0;
        $uids         = [];
        $response = $this->sendCommand($command, $parameters);
        if(!$response || !$this->responseStatus())
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
                preg_match("/INTERNALDATE \"(.*?)\" /", $details['line'], $internalDate);
                $hasAttachement = (preg_match("/attachment/", $details['line'])) ? true : false;
                $iUids = filter_var($searchUids[1], FILTER_SANITIZE_NUMBER_INT);
                $uids[] = $iUids;
                if($emailRaw){
                    $emails[$iUids] = Imap\ImapMessage::make($emailRaw)->setInternalDate($internalDate[1])->setAttachements($hasAttachement)->setFlags($searchFlags[1])->setOptions(['messageFolder' => $this->currentMailbox, 'messageUid' => $iUids, 'messageSize' => filter_var($searchSize[1],FILTER_SANITIZE_NUMBER_INT)]);
                }
            }
        }
        if(!$emails)
            return $uids;
        return $emails;
    }
    
    /** ImapClient::getList() */
    private function getList($array)
    {
        $list = [];
        foreach ($array as $key => $value)
            $list[] = !is_array($value) ? $value : $this->getList($v);
        return '(' . implode(' ', $list) . ')';
    }
    
    /** ImapClient::responseStatus() */
    protected function responseStatus(){
        return ($this->commandResponse->StatusOrIndex == ResponseStatus::OK) ? true : false;
    }
    
    /** ImapClient::sendCommand()*/
    protected function sendCommand($command, $parameters = [])
    {
        if (!$this->putRawCommand($command, $parameters))
            return false;
        return $this->receiveResponse();
    }

    /** ImapClient::makeResponseLine() */
    protected function makeResponseLine()
    {
        return Imap\ImapResponse::make(trim($this->getRawResponseLine(),"\n"));
    }
    
    /** ImapClient::getRawResponseLine()*/
    protected function getRawResponseLine()
    {
        $line = fgets($this->socket);
        if ($line === false)
            $this->LogoutAndDisconnect();
        $this->debug('Receiving: ' . $line);
        return $line;
    }

    /** ImapClient::receiveResponse()*/
    protected function receiveResponse()
    {
        $this->bufferResponse = [];
        $start = time();
        while (time() < ($start + self::TIMEOUT))
        {
            $line     = trim($this->getRawResponseLine(),"\n");
            $response = Imap\ImapResponse::make($line);
            $this->bufferResponse[] = $response;
            if ($response->ResponseTag == 'TAG' . $this->currentTag){
                $this->commandResponse = array_pop($this->bufferResponse);
                return $this->bufferResponse;
            }
        }
        return null;
    }

    /** ImapClient::putRawCommand()*/
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

    /** ImapClient::debug()*/
    private function debug($string)
    {
        if($this->debugging)
            echo $string;
        return $this;
    }

    /** ImapClient::escape()*/
    private function escape($string)
    {
        if (func_num_args() < 2)
            return (strpos($string, "\n") !== false) ? ['{' . strlen($string) . '}', $string] : '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $string) . '"';
        foreach (func_get_args() as $string)
            $result[] = $this->escape($string);
        return $result;
    }
}
