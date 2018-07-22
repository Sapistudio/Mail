<?php 
namespace SapiStudio\SapiMail;
/**
 * This file is part of a fork of  the Eden PHP Library.
 * (c) 2014-2016 Openovate Labs
 *
 * Copyright and license information can be found at LICENSE.txt
 * distributed with this package.
 */

class Smtp 
{
    /**
     * @const int TIMEOUT Connection timeout
     */
    const TIMEOUT = 30;

    /**
     * @var string $host The SMTP Host
     */
    protected $host = null;
       
    /**
     * @var string|null $port The SMTP port
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
     * @var [RESOURCE] $socket The socket connection
     */
    protected $socket = null;
       
    /**
     * @var array $boundary The list of boundaries
     */
    protected $boundary = [];
       
    /**
     * @var array $subject The mail subject
     */
    protected $subject  = null;
       
    /**
     * @var array $body Body content types
     */
    protected $body = [];
       
    /**
     * @var array $to The list of main recipients
     */
    protected $to = [];
       
    /**
     * @var array $cc The list of carbon copies
     */
    protected $cc = [];
       
    /**
     * @var array $bcc The list of BCCs sorry i forgot what this stood for :(
     */
    protected $bcc = [];
       
    /**
     * @var array $attachments The list of attachments
     */
    protected $attachments = [];

    /**
     * @var bool $debugging If true outputs the logs
     */
    private $debugging = true;
    
    /**
     * Smtp::make()
     * 
     * @param mixed $host
     * @param mixed $user
     * @param mixed $pass
     * @param mixed $port
     * @param bool $ssl
     * @param bool $tls
     * @return
     */
    public static function make($host = null, $user = null, $pass = null, $port = null, $ssl = true, $tls = false){
        return new static($host,$user,$pass,$port,$ssl,$tls);
    }
    
    /**
     * Smtp::__construct()
     * 
     * @param mixed $host
     * @param mixed $user
     * @param mixed $pass
     * @param mixed $port
     * @param bool $ssl
     * @param bool $tls
     * @return
     */
    public function __construct($host = null, $user = null, $pass = null, $port = null, $ssl = true, $tls = false) {
        if (is_null($port))
            $port = $ssl ? 465 : 25;
        $this->host         = $host;
        $this->username     = $user;
        $this->password     = $pass;
        $this->port         = $port;
        $this->ssl          = $ssl;
        $this->tls          = $tls;
        $this->boundary[]   = md5(time().'1');
        $this->boundary[]   = md5(time().'2');
    }

    /**
     * Smtp::addAttachment()
     * 
     * @param mixed $filename
     * @param mixed $data
     * @param mixed $mime
     * @return
     */
    public function addAttachment($filename, $data, $mime = null)
    {
        $this->attachments[] = array($filename, $data, $mime);
        return $this;
    }

    /**
     * Smtp::addBCC()
     * 
     * @param mixed $email
     * @param mixed $name
     * @return
     */
    public function addBCC($email, $name = null)
    {
        $this->bcc[$email] = $name;
        return $this;
    }

    /**
     * Smtp::addCC()
     * 
     * @param mixed $email
     * @param mixed $name
     * @return
     */
    public function addCC($email, $name = null)
    {
        $this->cc[$email] = $name;
        return $this;
    }

    /**
     * Smtp::addTo()
     * 
     * @param mixed $email
     * @param mixed $name
     * @return
     */
    public function addTo($email, $name = null)
    {
        $this->to[$email] = $name;
        return $this;
    }

    /**
     * Smtp::connect()
     * 
     * @param mixed $timeout
     * @param bool $test
     * @return
     */
    public function connect($timeout = self::TIMEOUT, $test = false)
    {
        $host   = $this->host;
        $host   = ($this->ssl) ? 'ssl://' . $host : 'tcp://' . $host;
        $errno  =  0;
        $errstr = '';
        $this->socket = @stream_socket_client($host.':'.$this->port, $errno, $errstr, $timeout);
        if (!$this->socket || strlen($errstr) > 0 || $errno > 0)
            throw new Exception(Exception::SERVER_ERROR);
        $this->receive();
        if (!$this->call('EHLO '.$_SERVER['HTTP_HOST'], 250) && !$this->call('HELO '.$_SERVER['HTTP_HOST'], 250)) {
            $this->disconnect();
            throw new Exception(Exception::SERVER_ERROR);
        }

        if ($this->tls && !$this->call('STARTTLS', 220, 250)) {
            if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                $this->disconnect();
                throw new Exception(Exception::TLS_ERROR);
            }
            if (!$this->call('EHLO '.$_SERVER['HTTP_HOST'], 250) && !$this->call('HELO '.$_SERVER['HTTP_HOST'], 250)) {
                $this->disconnect();
                throw new Exception(Exception::SERVER_ERROR);
            }
        }
        if ($test) {
            $this->disconnect();
            return $this;
        }

        if (!$this->call('AUTH LOGIN', 250, 334)) {
            $this->disconnect();
            throw new Exception(Exception::LOGIN_ERROR);
        }
        if (!$this->call(base64_encode($this->username), 334)) {
            $this->disconnect();
            throw new Exception(Exception::LOGIN_ERROR);
        }

        if (!$this->call(base64_encode($this->password), 235, 334)) {
            $this->disconnect();
            throw new Exception(Exception::LOGIN_ERROR);
        }

        return $this;
    }

    /**
     * Smtp::disconnect()
     * 
     * @return
     */
    public function disconnect()
    {
        if ($this->socket) {
            $this->push('QUIT');
            fclose($this->socket);
            $this->socket = null;
        }
        return $this;
    }

    /**
     * Smtp::reply()
     * 
     * @param mixed $messageId
     * @param mixed $topic
     * @param mixed $headers
     * @return
     */
    public function reply($messageId, $topic = null, array $headers = array())
    {
        if (!$this->socket)
            $this->connect();
        //add from
        if (!$this->call('MAIL FROM:<' . $this->username . '>', 250, 251)) {
            $this->disconnect();
            throw new Exception(Exception::SMTP_ADD_EMAIL);
        }

        //add to
        foreach ($this->to as $email => $name) {
            if (!$this->call('RCPT TO:<' . $email . '>', 250, 251)) {
                $this->disconnect();
                throw new Exception(Exception::SMTP_ADD_EMAIL);
            }
        }

        //add cc
        foreach ($this->cc as $email => $name) {
            if (!$this->call('RCPT TO:<' . $email . '>', 250, 251)) {
                $this->disconnect();
                throw new Exception(Exception::SMTP_ADD_EMAIL);
            }
        }

        //add bcc
        foreach ($this->bcc as $email => $name) {
            if (!$this->call('RCPT TO:<' . $email . '>', 250, 251)) {
                $this->disconnect();
                throw new Exception(Exception::SMTP_ADD_EMAIL);
            }
        }

        //start compose
        if (!$this->call('DATA', 354)) {
            $this->disconnect();
            throw new Exception(Exception::SMTP_DATA);
        }

        $headers    = $this->getHeaders($headers);
        $body       = $this->getBody();

        $headers['In-Reply-To'] = $messageId;

        if ($topic) {
            $headers['Thread-Topic'] = $topic;
        }

        //send header data
        foreach ($headers as $name => $value) {
            var_dump($name.': '.$value);
            $this->push($name.': '.$value);
        }

        //send body data
        foreach ($body as $line) {
            if (strpos($line, '.') === 0) {
                // Escape lines prefixed with a '.'
                $line = '.' . $line;
            }

            $this->push($line);
        }

        //tell server this is the end
        if (!$this->call("\r\n.\r\n", 250)) {
            $this->disconnect();
            throw new Exception(Exception::SMTP_DATA);
        }

        //reset (some reason without this, this class spazzes out)
        $this->push('RSET');

        return $headers;
    }

    /**
     * Smtp::reset()
     * 
     * @return
     */
    public function reset()
    {
        $this->subject      = null;
        $this->body         = [];
        $this->to           = [];
        $this->cc           = [];
        $this->bcc          = [];
        $this->attachments  = [];
        $this->disconnect();
        return $this;
    }

    /**
     * Smtp::send()
     * 
     * @param mixed $headers
     * @return
     */
    public function send(array $headers = array())
    {
        //if no socket
        if (!$this->socket)
            $this->connect();
        $headers    = $this->getHeaders($headers);
        $body       = $this->getBody();

        //add from
        if (!$this->call('MAIL FROM:<' . $this->username . '>', 250, 251)) {
            $this->disconnect();
            throw new Exception(Exception::SMTP_ADD_EMAIL);
        }

        //add to
        foreach ($this->to as $email => $name) {
            if (!$this->call('RCPT TO:<' . $email . '>', 250, 251)) {
                $this->disconnect();
                throw new Exception(Exception::SMTP_ADD_EMAIL);
            }
        }

        //add cc
        foreach ($this->cc as $email => $name) {
            if (!$this->call('RCPT TO:<' . $email . '>', 250, 251)) {
                $this->disconnect();
                throw new Exception(Exception::SMTP_ADD_EMAIL);
            }
        }

        //add bcc
        foreach ($this->bcc as $email => $name) {
            if (!$this->call('RCPT TO:<' . $email . '>', 250, 251)) {
                $this->disconnect();
                throw new Exception(Exception::SMTP_ADD_EMAIL);
            }
        }

        //start compose
        if (!$this->call('DATA', 354)) {
            $this->disconnect();
            throw new Exception(Exception::SMTP_DATA);
        }

        //send header data
        foreach ($headers as $name => $value) {
            $this->push($name.': '.$value);
        }

        //send body data
        foreach ($body as $line) {
            if (strpos($line, '.') === 0)
                $line = '.' . $line;
            $this->push($line);
        }

        //tell server this is the end
        if (!$this->call(".", 250)) {
            $this->disconnect();
            throw new Exception(Exception::SMTP_DATA);
        }
        $this->push('RSET');
        return $headers;
    }

    /**
     * Smtp::setBody()
     * 
     * @param mixed $body
     * @param bool $html
     * @return
     */
    public function setBody($body, $html = false)
    {
        if ($html) {
            $this->body['text/html'] = $body;
            $body = strip_tags($body);
        }
        $this->body['text/plain'] = $body;
        return $this;
    }

    /**
     * Smtp::setSubject()
     * 
     * @param mixed $subject
     * @return
     */
    public function setSubject($subject)
    {
        $this->subject = $subject;
        return $this;
    }

    /**
     * Smtp::addAttachmentBody()
     * 
     * @param mixed $body
     * @return
     */
    protected function addAttachmentBody(array $body)
    {
        foreach ($this->attachments as $attachment) {
            list($name, $data, $mime) = $attachment;
            $mime   = $mime ? $mime : File::i($name)->getMime();
            $data   = base64_encode($data);
            $count  = ceil(strlen($data) / 998);

            $body[] = '--'.$this->boundary[1];
            $body[] = 'Content-type: '.$mime.'; name="'.$name.'"';
            $body[] = 'Content-disposition: attachment; filename="'.$name.'"';
            $body[] = 'Content-transfer-encoding: base64';
            $body[] = null;
            for ($i = 0; $i < $count; $i++)
                $body[] = substr($data, ($i * 998), 998);
            $body[] = null;
            $body[] = null;
        }

        $body[] = '--'.$this->boundary[1].'--';
        return $body;
    }

    /**
     * Smtp::call()
     * 
     * @param mixed $command
     * @param mixed $code
     * @return
     */
    protected function call($command, $code = null)
    {
        if (!$this->push($command))
            return false;
        $receive = $this->receive();
        $args = func_get_args();
        if (count($args) > 1) {
            for ($i = 1; $i < count($args); $i++) {
                if (strpos($receive, (string)$args[$i]) === 0)
                    return true;
            }
            return false;
        }
        return $receive;
    }

    /**
     * Smtp::getAlternativeAttachmentBody()
     * 
     * @return
     */
    protected function getAlternativeAttachmentBody()
    {
        $alternative    = $this->getAlternativeBody();
        $body           = [];
        $body[]         = 'Content-Type: multipart/mixed; boundary="'.$this->boundary[1].'"';
        $body[]         = null;
        $body[]         = '--'.$this->boundary[1];
        foreach ($alternative as $line)
            $body[] = $line;
        return $this->addAttachmentBody($body);
    }

    /**
     * Smtp::getAlternativeBody()
     * 
     * @return
     */
    protected function getAlternativeBody()
    {
        $plain  = $this->getPlainBody();
        $html   = $this->getHtmlBody();

        $body   = [];
        $body[] = 'Content-Type: multipart/alternative; boundary="'.$this->boundary[0].'"';
        $body[] = null;
        $body[] = '--'.$this->boundary[0];
        foreach ($plain as $line)
            $body[] = $line;
        $body[] = '--'.$this->boundary[0];
        foreach ($html as $line)
            $body[] = $line;
        $body[] = '--'.$this->boundary[0].'--';
        $body[] = null;
        $body[] = null;
        return $body;
    }

    /**
     * Smtp::getBody()
     * 
     * @return
     */
    protected function getBody()
    {
        $type = 'Plain';
        if (count($this->body) > 1) {
            $type = 'Alternative';
        } else if (isset($this->body['text/html'])) {
            $type = 'Html';
        }

        $method = 'get%sBody';
        if (!empty($this->attachments)) {
            $method = 'get%sAttachmentBody';
        }
        $method = sprintf($method, $type);
        return $this->$method();
    }

    /**
     * Smtp::getHeaders()
     * 
     * @param mixed $customHeaders
     * @return
     */
    protected function getHeaders(array $customHeaders = [])
    {
        $timestamp  = $this->getTimestamp();
        $subject    = trim($this->subject);
        $subject    = str_replace(["\n", "\r"], '', $subject);
        $to = $cc = $bcc = [];
        foreach ($this->to as $email => $name)
            $to[] = trim($name.' <'.$email.'>');
        foreach ($this->cc as $email => $name)
            $cc[] = trim($name.' <'.$email.'>');
        foreach ($this->bcc as $email => $name)
            $bcc[] = trim($name.' <'.$email.'>');
        list($account, $suffix) = explode('@', $this->username);
        $headers = ['Date' => $timestamp,'Subject' => $subject,'From' => '<'.$this->username.'>','To' => implode(', ', $to)];
        if (!empty($cc))
            $headers['Cc'] = implode(', ', $cc);
        if (!empty($bcc))
            $headers['Bcc'] = implode(', ', $bcc);
        $headers['Message-ID']  = '<'.md5(uniqid(time())).'.eden@'.$suffix.'>';
        $headers['Thread-Topic'] = $this->subject;
        $headers['Reply-To'] = '<'.$this->username.'>';
        foreach ($customHeaders as $key => $value)
            $headers[$key] = $value;
        return $headers;
    }

    /**
     * Smtp::getHtmlAttachmentBody()
     * 
     * @return
     */
    protected function getHtmlAttachmentBody()
    {
        $html   = $this->getHtmlBody();
        $body   = [];
        $body[] = 'Content-Type: multipart/mixed; boundary="'.$this->boundary[1].'"';
        $body[] = null;
        $body[] = '--'.$this->boundary[1];
        foreach ($html as $line)
            $body[] = $line;
        return $this->addAttachmentBody($body);
    }

    /**
     * Smtp::getHtmlBody()
     * 
     * @return
     */
    protected function getHtmlBody()
    {
        $charset    = $this->isUtf8($this->body['text/html']) ? 'utf-8' : 'US-ASCII';
        $html       = str_replace("\r", '', trim($this->body['text/html']));
        $encoded    = explode("\n", $this->quotedPrintableEncode($html));
        $body       = [];
        $body[]     = 'Content-Type: text/html; charset='.$charset;
        $body[]     = 'Content-Transfer-Encoding: quoted-printable'."\n";
        foreach ($encoded as $line)
            $body[] = $line;
        $body[] = null;
        $body[] = null;
        return $body;
    }

    /**
     * Smtp::getPlainAttachmentBody()
     * 
     * @return
     */
    protected function getPlainAttachmentBody()
    {
        $plain  = $this->getPlainBody();
        $body   = [];
        $body[] = 'Content-Type: multipart/mixed; boundary="'.$this->boundary[1].'"';
        $body[] = null;
        $body[] = '--'.$this->boundary[1];
        foreach ($plain as $line)
            $body[] = $line;
        return $this->addAttachmentBody($body);
    }

    /**
     * Smtp::getPlainBody()
     * 
     * @return
     */
    protected function getPlainBody()
    {
        $charset    = $this->isUtf8($this->body['text/plain']) ? 'utf-8' : 'US-ASCII';
        $plane      = str_replace("\r", '', trim($this->body['text/plain']));
        $count      = ceil(strlen($plane) / 998);
        $body       = [];
        $body[]     = 'Content-Type: text/plain; charset='.$charset;
        $body[]     = 'Content-Transfer-Encoding: 7bit';
        $body[]     = null;
        for ($i = 0; $i < $count; $i++)
            $body[] = substr($plane, ($i * 998), 998);
        $body[] = null;
        $body[] = null;
        return $body;
    }

    /**
     * Smtp::receive()
     * 
     * @return
     */
    protected function receive()
    {
        $data = '';
        $now = time();
        while ($str = fgets($this->socket, 1024)) {
            $data .= $str;
            if (substr($str, 3, 1) == ' ' || time() > ($now + self::TIMEOUT)) {
                break;
            }
        }
        $this->debug('Receiving: '. $data);
        return $data;
    }

    /**
     * Smtp::push()
     * 
     * @param mixed $command
     * @return
     */
    protected function push($command)
    {
        $this->debug('Sending: '.$command);
        return fwrite($this->socket, $command . "\r\n");
    }

    /**
     * Smtp::debug()
     * 
     * @param mixed $string
     * @return
     */
    private function debug($string)
    {
        if ($this->debugging) {
            $string = htmlspecialchars($string);
            echo '<pre>'.$string.'</pre>'."\n";
        }
        return $this;
    }

    /**
     * Smtp::getTimestamp()
     * 
     * @return
     */
    private function getTimestamp()
    {
        $zone = date('Z');
        $sign = ($zone < 0) ? '-' : '+';
        $zone = abs($zone);
        $zone = (int)($zone / 3600) * 100 + ($zone % 3600) / 60;
        return sprintf("%s %s%04d", date('D, j M Y H:i:s'), $sign, $zone);
    }

    /**
     * Smtp::isUtf8()
     * 
     * @param mixed $string
     * @return
     */
    private function isUtf8($string)
    {
        $regex = ['[\xC2-\xDF][\x80-\xBF]','\xE0[\xA0-\xBF][\x80-\xBF]','[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}','\xED[\x80-\x9F][\x80-\xBF]','\xF0[\x90-\xBF][\x80-\xBF]{2}','[\xF1-\xF3][\x80-\xBF]{3}','\xF4[\x80-\x8F][\x80-\xBF]{2}'];
        $count = ceil(strlen($string) / 5000);
        for ($i = 0; $i < $count; $i++) {
            if (preg_match('%(?:'. implode('|', $regex).')+%xs', substr($string, ($i * 5000), 5000)))
                return false;
        }
        return true;
    }

    /**
     * Smtp::quotedPrintableEncode()
     * 
     * @param mixed $input
     * @param integer $line_max
     * @return
     */
    private function quotedPrintableEncode($input, $line_max = 250)
    {
        $hex        = ['0','1','2','3','4','5','6','7','8','9','A','B','C','D','E','F'];
        $lines      = preg_split("/(?:\r\n|\r|\n)/", $input);
        $linebreak  = "=0D=0A=\r\n";
        /* the linebreak also counts as characters in the mime_qp_long_line
        * rule of spam-assassin */
        $line_max = $line_max - strlen($linebreak);
        $escape = "=";
        $output = "";
        $cur_conv_line = "";
        $length = 0;
        $whitespace_pos = 0;
        $addtl_chars = 0;

        // iterate lines
        for ($j = 0; $j < count($lines); $j++) {
            $line = $lines[$j];
            $linlen = strlen($line);

            // iterate chars
            for ($i = 0; $i < $linlen; $i++) {
                $c = substr($line, $i, 1);
                $dec = ord($c);

                $length++;

                if ($dec == 32) {
                    // space occurring at end of line, need to encode
                    if (($i == ($linlen - 1))) {
                        $c = "=20";
                        $length += 2;
                    }

                    $addtl_chars = 0;
                    $whitespace_pos = $i;
                } else if (($dec == 61) || ($dec < 32 ) || ($dec > 126)) {
                      $h2 = floor($dec/16);
                      $h1 = floor($dec%16);
                      $c = $escape . $hex["$h2"] . $hex["$h1"];
                      $length += 2;
                      $addtl_chars += 2;
                }

                // length for wordwrap exceeded, get a newline into the text
                if ($length >= $line_max) {
                    $cur_conv_line .= $c;

                    // read only up to the whitespace for the current line
                    $whitesp_diff = $i - $whitespace_pos + $addtl_chars;

                    //the text after the whitespace will have to be read
                    // again ( + any additional characters that came into
                    // existence as a result of the encoding process after the whitespace)
                    //
                    // Also, do not start at 0, if there was *no* whitespace in
                    // the whole line
                    if (($i + $addtl_chars) > $whitesp_diff) {
                        $output .= substr($cur_conv_line, 0, (strlen($cur_conv_line) -
                                $whitesp_diff)) . $linebreak;
                        $i =  $i - $whitesp_diff + $addtl_chars;
                    } else {
                        $output .= $cur_conv_line . $linebreak;
                    }

                    $cur_conv_line = "";
                    $length = 0;
                    $whitespace_pos = 0;
                } else {
                    // length for wordwrap not reached, continue reading
                    $cur_conv_line .= $c;
                }
            } // end of for

            $length = 0;
            $whitespace_pos = 0;
            $output .= $cur_conv_line;
            $cur_conv_line = "";

            if ($j<=count($lines)-1) {
                $output .= $linebreak;
            }
        } // end for

        return trim($output);
    }
}
