<?php
namespace SapiStudio\SapiMail;

class FetchType
{
	const ALL = 'ALL';
	const FAST = 'FAST';
	const FULL = 'FULL';
	const BODY = 'BODY';
	const BODY_PEEK = 'BODY.PEEK';
	const BODY_HEADER = 'BODY[HEADER]';
	const BODY_HEADER_PEEK = 'BODY.PEEK[HEADER]';
	const BODYSTRUCTURE = 'BODYSTRUCTURE';
	const ENVELOPE = 'ENVELOPE';
	const FLAGS = 'FLAGS';
	const INTERNALDATE = 'INTERNALDATE';
	const RFC822 = 'RFC822';
	const RFC822_HEADER = 'RFC822.HEADER';
	const RFC822_SIZE = 'RFC822.SIZE';
	const RFC822_TEXT = 'RFC822.TEXT';
	const UID = 'UID';
	const INDEX = 'INDEX';

	const GMAIL_MSGID = 'X-GM-MSGID';
	const GMAIL_THRID = 'X-GM-THRID';
	const GMAIL_LABELS = 'X-GM-LABELS';
}
