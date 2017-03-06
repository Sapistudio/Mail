<?php
namespace SapiStudio\SapiMail;


class Imap
{
    public $BindIp = null;
    const TIMEOUT = 30;
    protected $host = null;
    protected $port = null;
    protected $ssl = false;
    protected $tls = false;
    protected $username = null;
    protected $password = null;
    protected $tag = 0;
    protected $total = 0;
    protected $next = 0;
    protected $buffer = null;
    protected $socket = null;
    protected $mailbox = null;
    protected $mailboxes = [];
    private $debugging = false;

    public function __construct($host, $user, $pass, $port = null, $ssl = false, $tls = false)
    {
        if (is_null($port))
            $port = $ssl ? 993 : 143;
        $this->host = $host;
        $this->username = $user;
        $this->password = $pass;
        $this->port = $port;
        $this->ssl = $ssl;
        $this->tls = $tls;
    }

    public function connect($timeout = self::TIMEOUT, $test = false)
    {
        if ($this->socket)
            return $this;
        $host = $this->host;
        if ($this->ssl)
            $host = 'ssl://' . $host;
        $context = stream_context_create(['ssl' => ['verify_peer' => false,'verify_peer_name' => false, 'allow_self_signed' => true]]);
        if ($this->BindIp != '')
            $context = ['socket' => ['bindto' => $this->BindIp . ':0']];
        $this->socket = stream_socket_client($host . ':' . $this->port . '', $errno, $errstr,
            $timeout, STREAM_CLIENT_CONNECT, $context);
        if (!$this->socket)
            throw new Exception(exception::SERVER_ERROR);
        if (strpos($this->getLine(), '* OK') === false)
        {
            $this->disconnect();
            throw new Exception(exception::SERVER_ERROR);
        }
        if ($this->tls)
        {
            $this->send('STARTTLS');
            if (!stream_socket_enable_crypto($this->socket, true,STREAM_CRYPTO_METHOD_TLS_CLIENT))
            {
                $this->disconnect();
                throw new Exception(exception::TLS_ERROR);
            }
        }
        if ($test)
        {
            fclose($this->socket);
            $this->socket = null;
            return $this;
        }
        $result = $this->call('LOGIN', $this->escape($this->username, $this->password));
        if (!is_array($result) || strpos(implode(' ', $result), 'OK') === false)
        {
            $this->disconnect();
            throw new Exception(exception::LOGIN_ERROR);
        }
        return $this;
    }

    public function SetBind($ip)
    {
        $this->BindIp = $ip;
    }

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

    public function getActiveMailbox()
    {
        return $this->mailbox;
    }

    public function getEmails($start = 0, $range = 20)
    {
        if (!$this->socket)
            $this->connect();
        if ($this->total == 0)
            return [];
        if (is_array($start))
        {
            $set = implode(',', $start);
        } else
        {
            $range = $range > 0 ? $range : 1;
            $start = $start >= 0 ? $start : 0;
            $max = $this->total - $start;
            if ($max < 1)
                $max = $this->total;
            $min = $max - $range + 1;
            if ($min < 1)
                $min = 1;
            $set = $min . ':' . $max;
            if ($min == $max)
                $set = $min;
        }
        $emails = $this->getEmailResponse('FETCH', [$set, $this->getList(['UID', 'FLAGS','BODY[HEADER]', 'RFC822.SIZE'])]);
        $emails = array_reverse($emails);
        return $emails;
    }

    public function getEmailsDetails($uid)
    {
        if (!$this->socket)
            $this->connect();
        if ($this->total == 0)
            return [];
        if (is_array($uid))
            $uid = implode(',', $uid);
        return $this->getEmailResponse('UID FETCH', [$uid, $this->getList(['UID','FLAGS', 'BODY[]', 'RFC822.SIZE'])]);
    }

    public function getUids()
    {
        return $this->getEmailResponse('FETCH', [1 . ':' . $this->total, $this->getList(['UID'])]);
    }

    public function getEmailTotal()
    {
        return $this->total;
    }

    public function getNextUid()
    {
        return $this->next;
    }

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

    public function move($uid, $mailbox)
    {
        if (!$this->socket)
            $this->connect();
        $this->call('UID COPY ' . $uid . ' ' . $mailbox);
        return $this->remove($uid);
    }

    public function remove($uid)
    {
        if (!$this->socket)
            $this->connect();
        $this->call('UID STORE ' . $uid . ' FLAGS.SILENT \Deleted');
        return $this->expunge();
    }

    public function expunge()
    {
        $this->call('expunge');
        return $this;
    }

    public function createFolder($mailbox)
    {
        $result = $this->call('CREATE', $this->escape($mailbox));
        if (!is_array($result) || strpos(implode(' ', $result), 'OK') === false)
        {
            $this->disconnect();
            throw new Exception(exception::SERVER_ERROR);
        }
    }

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

    protected function call($command, $parameters = [])
    {
        if (!$this->send($command, $parameters))
            return false;
        return $this->receive();
    }

    protected function getLine()
    {
        $line = fgets($this->socket);
        if ($line === false)
            $this->disconnect();
        $this->debug('Receiving: ' . $line);
        return $line;
    }

    protected function receive()
    {
        $this->buffer = [];
        $start = time();
        while (time() < ($start + self::TIMEOUT))
        {
            list($receivedTag, $line) = explode(' ', $this->getLine(), 2);
            $this->buffer[] = trim($receivedTag . ' ' . $line);
            if ($receivedTag == 'TAG' . $this->tag)
                return $this->buffer;
        }
        return null;
    }

    protected function send($command, $parameters = [])
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
            } else
                $line .= ' ' . $parameter;
        }
        $this->debug('Sending: ' . $line);
        return fputs($this->socket, $line . "\r\n");
    }

    private function debug($string)
    {
        if ($this->debugging)
        {
            $string = htmlspecialchars($string);
            echo $string;
        }
        return $this;
    }

    private function escape($string)
    {
        if (func_num_args() < 2)
            return (strpos($string, "\n") !== false) ? ['{' . strlen($string) . '}', $string] : '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $string) . '"';
        foreach (func_get_args() as $string)
            $result[] = $this->escape($string);
        return $result;
    }

    private function getEmailResponse($command, $parameters = [])
    {
        $uids = [];
        if (!$this->send($command, $parameters))
            return false;
        $start = time();
        $currentEmail = $index = 0;
        $index = 0;
        while (time() < ($start + self::TIMEOUT))
        {
            $line = str_replace("\n", '', $this->getLine());
            $response[$index] = $line;
            if (strpos($line, 'FETCH') !== false && strpos($line, 'TAG' . $this->tag) === false)
            {
                $mailsData[$currentEmail]['line'] = $line;
                if (isset($mailsData[$currentEmail - 1]))
                    $mailsData[$currentEmail - 1]['end'] = $index - 1;
                $mailsData[$currentEmail]['start'] = $index + 1;
                $currentEmail++;
            }
            if (strpos($line, 'TAG' . $this->tag) !== false)
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
                    array_pop($emailRaw);
                preg_match("/\(UID (.*?)\)/", $details['line'], $searchUids);
                preg_match("/FLAGS \((.*?)\)/", $details['line'], $searchFlags);
                preg_match("/RFC822.SIZE (.*?) /", $details['line'], $searchSize);
                $iUids = filter_var($searchUids[1], FILTER_SANITIZE_NUMBER_INT);
                $uids[] = $iUids;
                if($emailRaw)
                    $emails[$iUids] = Message::make($emailRaw)->setFlags($searchFlags[1])->setOptions(['messageFolder' => $this->mailbox, 'messageUid' => $iUids, 'messageSize' => filter_var($searchSize[1],FILTER_SANITIZE_NUMBER_INT)])->load();
            }
        }
        print_R($uids);
        die('rsfd');
        if (!$emails)
            return $uids;
        return $emails;
    }


    private function getList($array)
    {
        $list = [];
        foreach ($array as $key => $value)
            $list[] = !is_array($value) ? $value : $this->getList($v);
        return '(' . implode(' ', $list) . ')';
    }

    public function search(array $filter, $start = 0, $range = 10, $or = false, $body = false)
    {
        if (!$this->socket)
            $this->connect();
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
        $response = $this->call('UID SEARCH ' . $search);
        $result = array_pop($response);
        if (strpos($result, 'OK') !== false)
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

    public function searchTotal(array $filter, $or = false)
    {
        if (!$this->socket)
            $this->connect();
        $search = array();
        foreach ($filter as $where)
        {
            $item = $where[0] . ' "' . $where[1] . '"';
            if (isset($where[2]))
                $item .= ' "' . $where[2] . '"';
            $search[] = $item;
        }
        $search   = ($or) ? 'OR (' . implode(') (', $search) . ')' : implode(' ', $search);
        $response = $this->call('UID SEARCH ' . $search);
        $result   = array_pop($response);
        if (strpos($result, 'OK') !== false)
        {
            $uids = explode(' ', $response[0]);
            array_shift($uids);
            array_shift($uids);
            return count($uids);
        }
        return 0;
    }
}