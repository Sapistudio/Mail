Its a fork/mix after eden/mail and iluminate/mail library
# Imap
```php
use SapiStudio\SapiMail\ImapClient;

$IMAP = ImapClient::make()->initConnection($host)->loginToMailbox($user,$password);
```
