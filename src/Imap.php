<?php
namespace SapiStudio\SapiMail;
/**
 * This file is part of a fork of  the Eden PHP Library.
 * (c) 2014-2016 Openovate Labs
 *
 * Copyright and license information can be found at LICENSE.txt
 * distributed with this package.
 */

class Imap
{
    public $BindIp = '';
    /**
     * @const int TIMEOUT Connection timeout
     */
    const TIMEOUT = 30;
    /**
     * @const string NO_SUBJECT Default subject
     */
    const NO_SUBJECT = '(no subject)';
    /**
     * @var string $host The IMAP Host
     */
    protected $host = null;
    /**
     * @var string|null $port The IMAP port
     */
    protected $port = null;
    /**
     * @var bool $ssl Whether to use SSL
     */
    protected $ssl = false;
    /**
     * @var bool $tls Whether to use TLS
     */
    protected $tls = false;
    /**
     * @var string|null $username The mailbox user name
     */
    protected $username = null;
    /**
     * @var string|null $password The mailbox password
     */
    protected $password = null;
    /**
     * @var int $tag The tag number
     */
    protected $tag = 0;
    /**
     * @var int $total The total main in mailbox
     */
    protected $total = 0;
    /**
     * @var int $next for pagination
     */
    protected $next = 0;
    /**
     * @var string|null $buffer Mail body
     */
    protected $buffer = null;
    /**
     * @var [RESOURCE] $socket The socket connection
     */
    protected $socket = null;
    /**
     * @var string|null $mailbox The mailbox name
     */
    protected $mailbox = null;
    /**
     * @var array $mailboxes The list of mailboxes
     */
    protected $mailboxes = array();
    /**
     * @var bool $debugging If true outputs the logs
     */
    private $debugging = false;
    
    /**
     * Imap::__construct()
     * 
     * @param mixed $host
     * @param mixed $user
     * @param mixed $pass
     * @param mixed $port
     * @param bool $ssl
     * @param bool $tls
     * @return
     */
    public function __construct($host, $user, $pass, $port = null, $ssl = false, $tls = false)
    {
        if (is_null($port))
            $port = $ssl ? 993 : 143;
        $this->host     = $host;
        $this->username = $user;
        $this->password = $pass;
        $this->port     = $port;
        $this->ssl      = $ssl;
        $this->tls      = $tls;
    }
    
    /**
     * Imap::connect()
     * 
     * @param mixed $timeout
     * @param bool $test
     * @return
     */
    public function connect($timeout = self::TIMEOUT, $test = false)
    {
        if ($this->socket)
            return $this;
        $host = $this->host;
        if ($this->ssl)
            $host = 'ssl://' . $host;
        $errno = 0;
        $errstr = '';
        // Connect
        $context = stream_context_create(['ssl' => ['verify_peer' => false,'verify_peer_name' => false, 'allow_self_signed' => true]]);
        if ($this->BindIp != '')
            $context = array('socket' => array('bindto' => $this->BindIp . ':0'));
        $socket_context = stream_context_create($socket_options);
        $this->socket = stream_socket_client($host . ':' . $this->port . '', $errno, $errstr,
            $timeout, STREAM_CLIENT_CONNECT, $context);
        if (!$this->socket)
            throw new Exception(Exception::SERVER_ERROR);
        if (strpos($this->getLine(), '* OK') === false)
        {
            $this->disconnect();
            throw new Exception(Exception::SERVER_ERROR);
        }
        if ($this->tls)
        {
            $this->send('STARTTLS');
            if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT))
            {
                $this->disconnect();
                throw new Exception(Exception::TLS_ERROR);
            }
        }
        if ($test)
        {
            fclose($this->socket);
            $this->socket = null;
            return $this;
        }
        //login
        $result = $this->call('LOGIN', $this->escape($this->username, $this->password));
        if (!is_array($result) || strpos(implode(' ', $result), 'OK') === false)
        {
            $this->disconnect();
            throw new Exception(Exception::LOGIN_ERROR);
        }
        return $this;
    }
    
    /**
     * Imap::SetBind()
     * 
     * @param mixed $ip
     * @return
     */
    public function SetBind($ip)
    {
        $this->BindIp = $ip;
    }
    
    /**
     * Imap::disconnect()
     * 
     * @return
     */
    public function disconnect()
    {
        if ($this->socket)
        {
            $this->send('LOGOUT');
            fclose($this->socket);
            $this->socket = null;
        }
        return $this;
    }
    
    /**
     * Imap::getActiveMailbox()
     * 
     * @return
     */
    public function getActiveMailbox()
    {
        return $this->mailbox;
    }
   
    /**
     * Imap::getEmails()
     * 
     * @param integer $start
     * @param integer $range
     * @param bool $body
     * @return
     */
    public function getEmails($start = 0, $range = 10, $body = false)
    {
        //if not connected
        if (!$this->socket)
            $this->connect();
        //if the total in this mailbox is 0
        //it means they probably didn't select a mailbox
        //or the mailbox selected is empty
        if ($this->total == 0)
            return [];
        //if start is an array
        if (is_array($start))
        {
            $set = implode(',', $start);
            //just ignore the range parameter
        } else
        {
            $range = $range > 0 ? $range : 1;
            $start = $start >= 0 ? $start : 0;
            //calculate max (ex. 300 - 4 = 296)
            $max = $this->total - $start;
            //if max is less than 1
            if ($max < 1)
                $max = $this->total;
            //calculate min (ex. 296 - 15 + 1 = 282)
            $min = $max - $range + 1;
            //if min less than 1
            if ($min < 1)
                $min = 1;
            $set = $min . ':' . $max;
            if ($min == $max)
                $set = $min;
        }
        $items = ['UID','FLAGS','BODY[HEADER]'];
        if ($body)
            $items = ['UID','FLAGS','BODY[]'];
        $emails = $this->getEmailResponse('FETCH', array($set, $this->getList($items)));
        $emails = array_reverse($emails);
        return $emails;
    }

    /**
     * Imap::getUids()
     * 
     * @return
     */
    public function getUids()
    {
        $set = 1 . ':' . $this->total;
        return $this->getEmailResponse('FETCH',[$set, $this->getList(['UID'])]);
    }
    
    /**
     * Imap::getEmailTotal()
     * 
     * @return
     */
    public function getEmailTotal()
    {
        return $this->total;
    }
    
    /**
     * Imap::getNextUid()
     * 
     * @return
     */
    public function getNextUid()
    {
        return $this->next;
    }
    
    /**
     * Imap::getMailboxes()
     * 
     * @return
     */
    public function getMailboxes()
    {
        if (!$this->socket)
            $this->connect();
        $response = $this->call('LIST', $this->escape('', '*'));
        $mailboxes = [];
        foreach ($response as $line)
        {
            if (strpos($line, 'Noselect') !== false || strpos($line, 'LIST') == false)
                continue;
            $line = explode('"', $line);
            if (strpos(trim($line[0]), '*') !== 0)
                continue;
            $mailboxes[] = $line[count($line) - 2];
        }
        return $mailboxes;
    }

    /**
     * Imap::getUniqueEmails()
     * 
     * @param mixed $uid
     * @param bool $body
     * @return
     */
    public function getUniqueEmails($uid, $body = false)
    {
        if (!$this->socket)
            $this->connect();
        if ($this->total == 0)
            return [];
        //if uid is an array
        if (is_array($uid))
            $uid = implode(',', $uid);
        //lets call it
        $items = ['UID','FLAGS','BODY[HEADER]'];
        if ($body)
            $items = ['UID','FLAGS','BODY[]'];
        $first = is_numeric($uid) ? true : false;
        return $this->getEmailResponse('UID FETCH', array($uid, $this->getList($items)),$first);
    }
    
    /**
     * Imap::move()
     * 
     * @param mixed $uid
     * @param mixed $mailbox
     * @return
     */
    public function move($uid, $mailbox)
    {
        if (!$this->socket)
            $this->connect();
        $this->call('UID COPY ' . $uid . ' ' . $mailbox);
        return $this->remove($uid);
    }
    
    /**
     * Imap::remove()
     * 
     * @param mixed $uid
     * @return
     */
    public function remove($uid)
    {
        if (!$this->socket)
            $this->connect();
        $this->call('UID STORE ' . $uid . ' FLAGS.SILENT \Deleted');
        return $this->expunge();
    }
    
    /**
     * Imap::expunge()
     * 
     * @return
     */
    public function expunge()
    {
        $this->call('expunge');
        return $this;
    }
    
    /**
     * Imap::createFolder()
     * 
     * @param mixed $mailbox
     * @return
     */
    public function createFolder($mailbox)
    {
        $result = $this->call('CREATE', $this->escape($mailbox));
        if (!is_array($result) || strpos(implode(' ', $result), 'OK') === false)
        {
            $this->disconnect();
            throw new Exception(Exception::SERVER_ERROR);
        }
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
    public function search(array $filter, $start = 0, $range = 10, $or = false, $body = false)
    {
        if (!$this->socket)
            $this->connect();
        //build a search criteria
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
        //if this is an or search
        if ($or && count($search) > 1)
        {
            //item1
            //OR (item1) (item2)
            //OR (item1) (OR (item2) (item3))
            //OR (item1) (OR (item2) (OR (item3) (item4)))
            $query = null;
            while ($item = array_pop($search))
            {
                if (is_null($query))
                {
                    $query = $item;
                } else
                    if (strpos($query, 'OR') !== 0)
                    {
                        $query = 'OR (' . $query . ') (' . $item . ')';
                    } else
                    {
                        $query = 'OR (' . $item . ') (' . $query . ')';
                    }
            }
            $search = $query;
        } else
        {
            //this is an and search
            $search = implode(' ', $search);
        }
        //do the search
        $response = $this->call('UID SEARCH ' . $search);
        //get the result
        $result = array_pop($response);
        //if we got some results
        if (strpos($result, 'OK') !== false)
        {
            //parse out the uids
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
            //pagination
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
            //return the email details for this
            return $this->getUniqueEmails($uids, $body);
        }
        //it's not okay just return an empty set
        return [];
    }
   
    /**
     * Imap::searchTotal()
     * 
     * @param mixed $filter
     * @param bool $or
     * @return
     */
    public function searchTotal(array $filter, $or = false)
    {
        if (!$this->socket)
            $this->connect();
        //build a search criteria
        $search = array();
        foreach ($filter as $where)
        {
            $item = $where[0] . ' "' . $where[1] . '"';
            if (isset($where[2]))
            {
                $item .= ' "' . $where[2] . '"';
            }
            $search[] = $item;
        }
        //if this is an or search
        if ($or)
        {
            $search = 'OR (' . implode(') (', $search) . ')';
        } else
        {
            //this is an and search
            $search = implode(' ', $search);
        }
        $response = $this->call('UID SEARCH ' . $search);
        //get the result
        $result = array_pop($response);
        //if we got some results
        if (strpos($result, 'OK') !== false)
        {
            //parse out the uids
            $uids = explode(' ', $response[0]);
            array_shift($uids);
            array_shift($uids);
            return count($uids);
        }
        //it's not okay just return 0
        return 0;
    }

    /**
     * Imap::setActiveMailbox()
     * 
     * @param mixed $mailbox
     * @return
     */
    public function setActiveMailbox($mailbox)
    {
        if (!$this->socket)
            $this->connect();
        $response = $this->call('SELECT', $this->escape($mailbox));
        $result = array_pop($response);
        foreach ($response as $line)
        {
            if (strpos($line, 'EXISTS') !== false)
            {
                list($star, $this->total, $type) = explode(' ', $line, 3);
            } else
                if (strpos($line, 'UIDNEXT') !== false)
                {
                    list($star, $ok, $next, $this->next, $type) = explode(' ', $line, 5);
                    $this->next = substr($this->next, 0, -1);
                }
            if ($this->total && $this->next)
                break;
        }
        if (strpos($result, 'OK') !== false)
        {
            $this->mailbox = $mailbox;
            return $this;
        }
        return false;
    }

    /**
     * Imap::call()
     * 
     * @param mixed $command
     * @param mixed $parameters
     * @return
     */
    protected function call($command, $parameters = array())
    {
        if (!$this->send($command, $parameters))
            return false;
        return $this->receive($this->tag);
    }

    /**
     * Imap::getLine()
     * 
     * @return
     */
    protected function getLine()
    {
        $line = fgets($this->socket);
        if ($line === false)
            $this->disconnect();
        $this->debug('Receiving: ' . $line);
        return $line;
    }

    /**
     * Imap::receive()
     * 
     * @param mixed $sentTag
     * @return
     */
    protected function receive($sentTag)
    {
        $this->buffer = [];
        $start = time();
        while (time() < ($start + self::TIMEOUT))
        {
            list($receivedTag, $line) = explode(' ', $this->getLine(), 2);
            $this->buffer[] = trim($receivedTag . ' ' . $line);
            if ($receivedTag == 'TAG' . $sentTag)
                return $this->buffer;
        }
        return null;
    }

    /**
     * Imap::send()
     * 
     * @param mixed $command
     * @param mixed $parameters
     * @return
     */
    protected function send($command, $parameters = array())
    {
        $this->tag++;
        $line = 'TAG' . $this->tag . ' ' . $command;
        if (!is_array($parameters))
            $parameters = array($parameters);
        foreach ($parameters as $parameter)
        {
            if (is_array($parameter))
            {
                if (fputs($this->socket, $line . ' ' . $parameter[0] . "\r\n") === false)
                    return false;
                if (strpos($this->getLine(), '+ ') === false)
                    return false;
                $line = $parameter[1];
            }else
            {
                $line .= ' ' . $parameter;
            }
        }
        $this->debug('Sending: ' . $line);
        return fputs($this->socket, $line . "\r\n");
    }
 
    /**
     * Imap::debug()
     * 
     * @param mixed $string
     * @return
     */
    private function debug($string)
    {
        if ($this->debugging)
        {
            $string = htmlspecialchars($string);
            echo '<pre>' . $string . '</pre>' . "\n";
        }
        return $this;
    }
    
    /**
     * Imap::escape()
     * 
     * @param mixed $string
     * @return
     */
    private function escape($string)
    {
        if (func_num_args() < 2)
        {
            if (strpos($string, "\n") !== false)
            {
                return ['{' . strlen($string) . '}', $string];
            } else
            {
                return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $string) . '"';
            }
        }
        $result = [];
        foreach (func_get_args() as $string)
            $result[] = $this->escape($string);
        return $result;
    }

    /**
     * Imap::getEmailFormat()
     * 
     * @param mixed $email
     * @param mixed $uniqueId
     * @param mixed $flags
     * @return
     */
    private function getEmailFormat($email, $uniqueId = null, array $flags =[])
    {
        if (is_array($email))
            $email = implode("\n", $email);
        //split the head and the body
        $parts = preg_split("/\n\s*\n/", $email, 2);
        $head = $parts[0];
        $body = null;
        if (isset($parts[1]) && trim($parts[1]) != ')')
            $body = $parts[1];
        $lines = explode("\n", $head);
        $head = [];
        foreach ($lines as $line)
        {
            if (trim($line) && preg_match("/^\s+/", $line))
            {
                $head[count($head) - 1] .= ' ' . trim($line);
                continue;
            }
            $head[] = trim($line);
        }
        $head = implode("\n", $head);
        $recipientsTo = $recipientsCc = $recipientsBcc = $sender = [];
        //get the headers
        $headers1 = imap_rfc822_parse_headers($head);
        $headers2 = $this->getHeaders($head);
        //set the from
        $sender['name'] = null;
        if (isset($headers1->from[0]->personal))
        {
            $sender['name'] = $headers1->from[0]->personal;
            //if the name is iso or utf encoded
            if (preg_match("/^\=\?[a-zA-Z]+\-[0-9]+.*\?/", strtolower($sender['name'])))
                $sender['name'] = str_replace('_', ' ', mb_decode_mimeheader($sender['name']));
        }
        $sender['email'] = $headers1->from[0]->mailbox . '@' . $headers1->from[0]->host;
        //set the to
        if (isset($headers1->to))
        {
            foreach ($headers1->to as $to)
            {
                if (!isset($to->mailbox, $to->host))
                    continue;
                $recipient = ['name' => null];
                if (isset($to->personal))
                {
                    $recipient['name'] = $to->personal;
                    //if the name is iso or utf encoded
                    if (preg_match("/^\=\?[a-zA-Z]+\-[0-9]+.*\?/", strtolower($recipient['name'])))
                        $recipient['name'] = str_replace('_', ' ', mb_decode_mimeheader($recipient['name']));
                }
                $recipient['email'] = $to->mailbox . '@' . $to->host;
                $recipientsTo[] = $recipient;
            }
        }
        //set the cc
        if (isset($headers1->cc))
        {
            foreach ($headers1->cc as $cc)
            {
                $recipient = ['name' => null];
                if (isset($cc->personal))
                {
                    $recipient['name'] = $cc->personal;
                    if (preg_match("/^\=\?[a-zA-Z]+\-[0-9]+.*\?/", strtolower($recipient['name'])))
                        $recipient['name'] = str_replace('_', ' ', mb_decode_mimeheader($recipient['name']));
                }
                $recipient['email'] = $cc->mailbox . '@' . $cc->host;
                $recipientsCc[] = $recipient;
            }
        }
        //set the bcc
        if (isset($headers1->bcc))
        {
            foreach ($headers1->bcc as $bcc)
            {
                $recipient = ['name' => null];
                if (isset($bcc->personal))
                {
                    $recipient['name'] = $bcc->personal;
                    if (preg_match("/^\=\?[a-zA-Z]+\-[0-9]+.*\?/", strtolower($recipient['name'])))
                        $recipient['name'] = str_replace('_', ' ', mb_decode_mimeheader($recipient['name']));
                }
                $recipient['email'] = $bcc->mailbox . '@' . $bcc->host;
                $recipientsBcc[] = $recipient;
            }
        }
        //if subject is not set
        if (!isset($headers1->subject) || strlen(trim($headers1->subject)) === 0)
            $headers1->subject = self::NO_SUBJECT;
        //trim the subject
        $headers1->subject = str_replace(['<', '>'], '', trim($headers1->subject));
        //if the subject is iso or utf encoded
        if (preg_match("/^\=\?[a-zA-Z]+\-[0-9]+.*\?/", strtolower($headers1->subject)))
            $headers1->subject = str_replace('_', ' ', mb_decode_mimeheader($headers1->subject));
        //set thread details
        $topic = isset($headers2['thread-topic']) ? $headers2['thread-topic'] : $headers1->subject;
        $parent = isset($headers2['in-reply-to']) ? str_replace('"', '', $headers2['in-reply-to']) : null;
        //set date
        $date = isset($headers1->date) ? strtotime($headers1->date) : null;
        //set message id
        if (isset($headers2['message-id']))
            $messageId = str_replace('"', '', $headers2['message-id']);
        else
            $messageId = '<eden-no-id-' . md5(uniqid()) . '>';
        $attachment = isset($headers2['content-type']) && strpos($headers2['content-type'],'multipart/mixed') === 0;
        $format = [
            'id' => $messageId,
            'parent' => $parent,
            'topic' => $topic,
            'mailbox' => $this->mailbox,
            'uid' => $uniqueId,
            'date' => $date,
            'subject' => str_replace('Ã¢â‚¬â„¢', '\'', $headers1->subject),
            'from' => $sender,
            'flags' => $flags,
            'to' => $recipientsTo,
            'cc' => $recipientsCc,
            'bcc' => $recipientsBcc,
            'attachment' => $attachment
        ];
        $result = [];
        array_walk($headers2, function (&$value, $key)use (&$result)
        {
            $result[strtolower($key)] = $value; }
        );
        $format['headers'] = $result;
        if (trim($body) && $body != ')')
        {
            $parts = $this->getParts($email);
            if (empty($parts))
                $parts = ['text/plain' => $body];
            $body       = $parts;
            $attachment = [];
            if (isset($body['attachment']))
            {
                //take it out
                $attachment = $body['attachment'];
                unset($body['attachment']);
            }
            $format['body'] = $body;
            $format['raw'] = $email;
            $format['attachment'] = $attachment;
        }
        return $format;
    }

    /**
     * Imap::getEmailResponse()
     * 
     * @param mixed $command
     * @param mixed $parameters
     * @param bool $first
     * @return
     */
    private function getEmailResponse($command, $parameters = [], $first = false)
    {
        if (!$this->send($command, $parameters))
            return false;
        $messageId  = $uniqueId = $count = 0;
        $emails     = $email = [];
        $start      = time();
        //while there is no hang
        while (time() < ($start + self::TIMEOUT))
        {
            $line = str_replace("\n", '', $this->getLine());
            if (strpos($line, 'FETCH') !== false && strpos($line, 'TAG' . $this->tag) === false)
            {
                //if there is email data
                if (!empty($email))
                {
                    //create the email format and add it to emails
                    $emails[$uniqueId] = $this->getEmailFormat($email, $uniqueId, $flags);
                    //if all we want is the first one
                    if ($first)
                        return $emails[$uniqueId];
                    $email = [];
                }
                //if just okay
                if (strpos($line, 'OK') !== false)
                    continue;
                //if it's not just ok
                //it will contain the message id and the unique id and flags
                $flags = [];
                if (strpos($line, '\Answered') !== false)
                    $flags[] = 'answered';
                if (strpos($line, '\Flagged') !== false)
                    $flags[] = 'flagged';
                if (strpos($line, '\Deleted') !== false)
                    $flags[] = 'deleted';
                if (strpos($line, '\Seen') !== false)
                    $flags[] = 'seen';
                if (strpos($line, '\Draft') !== false)
                    $flags[] = 'draft';
                $findUid = explode(' ', $line);
                foreach ($findUid as $i => $uid)
                {
                    if (is_numeric($uid))
                        $uniqueId = $uid;
                    if (strpos(strtolower($uid), 'uid') !== false)
                    {
                        $uniqueId = $findUid[$i + 1];
                        break;
                    }
                }
                $uids[] = trim(str_replace(')', '', $uniqueId));
                //skip the rest
                continue;
            }
            //if there is a tag it means we are at the end
            if (strpos($line, 'TAG' . $this->tag) !== false)
            {
                //if email details are not empty and the last line is just a )
                if (!empty($email) && strpos(trim($email[count($email) - 1]), ')') === 0)
                    array_pop($email);
                //if there is email data
                if (!empty($email))
                {
                    $emails[$uniqueId] = $this->getEmailFormat($email, $uniqueId, $flags);
                    if ($first)
                        return $emails[$uniqueId];
                }
                //break out of this loop
                break;
            }
            $email[] = $line;
        }
        if (empty($emails))
            return $uids;
        return $emails;
    }
   
    /**
     * Imap::getHeaders()
     * 
     * @param mixed $rawData
     * @return
     */
    private function getHeaders($rawData)
    {
        if (is_string($rawData))
            $rawData = explode("\n", $rawData);
        $key = null;
        $headers = array();
        foreach ($rawData as $line)
        {
            $line = trim($line);
            if (preg_match("/^([a-zA-Z0-9-]+):/i", $line, $matches))
            {
                $key = strtolower($matches[1]);
                if (isset($headers[$key]))
                {
                    if (!is_array($headers[$key]))
                        $headers[$key] = array($headers[$key]);
                    $headers[$key][] = trim(str_replace($matches[0], '', $line));
                    continue;
                }
                $headers[$key] = trim(str_replace($matches[0], '', $line));
                continue;
            }
            if (!is_null($key) && isset($headers[$key]))
            {
                if (is_array($headers[$key]))
                {
                    $headers[$key][count($headers[$key]) - 1] .= ' ' . $line;
                    continue;
                }
                $headers[$key] .= ' ' . $line;
            }
        }
        return $headers;
    }
    
    /**
     * Imap::getList()
     * 
     * @param mixed $array
     * @return
     */
    private function getList($array)
    {
        $list = array();
        foreach ($array as $key => $value)
            $list[] = !is_array($value) ? $value : $this->getList($v);
        return '(' . implode(' ', $list) . ')';
    }
    
    /**
     * Imap::getParts()
     * 
     * @param mixed $content
     * @param mixed $parts
     * @return
     */
    private function getParts($content, array $parts = [])
    {
        list($head, $body) = preg_split("/\n\s*\n/", $content, 2);
        $head = $this->getHeaders($head);
        if (!isset($head['content-type']))
            return $parts;
        if (is_array($head['content-type']))
        {
            $type = [$head['content-type'][1]];
            if (strpos($type[0], ';') !== false)
                $type = explode(';', $type[0], 2);
        } else
        {
            $type = explode(';', $head['content-type'], 2);
        }
        //see if there are any extra stuff
        $extra = [];
        if (count($type) == 2)
            $extra = explode('; ', str_replace(['"', "'"], '', trim($type[1])));
        //the content type is the first part of this
        $type = trim($type[0]);
        //foreach extra
        foreach ($extra as $i => $attr)
        {
            //transform the extra array to a key value pair
            $attr = explode('=', $attr, 2);
            if (count($attr) > 1)
            {
                list($key, $value) = $attr;
                $extra[$key] = $value;
            }
            unset($extra[$i]);
        }
        //if a boundary is set
        if (isset($extra['boundary']))
        {
            //split the body into sections
            $sections = explode('--' . str_replace(['"', "'"], '', $extra['boundary']),$body);
            //we only want what's in the middle of these sections
            array_pop($sections);
            array_shift($sections);
            //foreach section
            foreach ($sections as $section)
                $parts = $this->getParts($section, $parts);
        } else
        {
            if (isset($head['content-transfer-encoding']))
            {
                if (is_array($head['content-transfer-encoding']))
                    $head['content-transfer-encoding'] = array_pop($head['content-transfer-encoding']);
                switch (strtolower($head['content-transfer-encoding']))
                {
                    case 'binary':
                        $body = imap_binary($body);
                        break;
                    case 'base64':
                        $body = base64_decode($body);
                        break;
                    case 'quoted-printable':
                        $body = quoted_printable_decode($body);
                        break;
                    case '7bit':
                        $body = mb_convert_encoding($body, 'UTF-8', 'ISO-2022-JP');
                        break;
                    default:
                        break;
                }
            }
            if (isset($extra['name']))
                $parts['attachment'][$extra['name']][$type] = $body;
            else
                $parts[$type] = $body;
        }
        return $parts;
    }
}
