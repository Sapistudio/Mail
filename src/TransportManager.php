<?php
namespace SapiStudio\SapiMail;

use Exception;
use Swift_SmtpTransport as SmtpTransport;
use Swift_MailTransport as MailTransport;
use Swift_SendmailTransport as SendmailTransport;
use SapiStudio\SapiMail\Transport\PmtaTransport;

class TransportManager
{
    /**
     * TransportManager::createTransport()
     * 
     * @param mixed $driver
     * @return
     */
    public static function createTransport($driver=null)
    {
        switch ($driver) {
            case 'mail':
                return $this->createMailDriver();
                break;
            case 'smtp':
                return self::createSmtpDriver();
                break;
            case 'sendmail':
                return self::createSendmailDriver();
                break;
            case 'pmta':
                return self::createPickupDriver();
                break;
            default:
                throw new Exception('Unrecognized Mail Driver '.$driver);
            break;
        }
        return false;
    }
    
    /**
     * TransportManager::createSmtpDriver()
     * 
     * @return
     */
    private static function createSmtpDriver()
    {
        $config     = $this->app['config']['mail'];
        $transport  = SmtpTransport::newInstance(
            $config['host'], $config['port']
        );
        if (isset($config['encryption'])) {
            $transport->setEncryption($config['encryption']);
        }
        if (isset($config['username'])) {
            $transport->setUsername($config['username']);
            $transport->setPassword($config['password']);
        }
        if (isset($config['stream'])) {
            $transport->setStreamOptions($config['stream']);
        }
        return $transport;
    }

    /**
     * TransportManager::createSendmailDriver()
     * 
     * @return
     */
    private function createSendmailDriver()
    {
        return SendmailTransport::newInstance('/usr/sbin/sendmail -bs');
    }

    /**
     * TransportManager::createMailDriver()
     * 
     * @return
     */
    private function createMailDriver()
    {
        return MailTransport::newInstance();
    }
    
    /**
     * TransportManager::createPickupDriver()
     * 
     * @return
     */
    private static function createPickupDriver()
    {
        return new PmtaTransport();
    }
}