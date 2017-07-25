<?php
namespace SapiStudio\SapiMail\Enumerations;

class FetchTypes
{
    const FETCH  = 'FETCH';
    const UID_FETCH = 'UID FETCH';
    const LOGIN = 'LOGIN';
    const F_LIST = 'LIST';
    const SELECT  = 'SELECT';
    const STATUS = 'STATUS';
    const UID_COPY = 'UID COPY';
    const UID_STORE = 'UID STORE';
    const STORE = 'STORE';
    const EXPUNGE = 'EXPUNGE';
    const CREATE = 'CREATE';
    const DELETE = 'DELETE';
    const UID_MOVE = 'UID MOVE';
    const UID_SEARCH = 'UID SEARCH';
    const STARTTLS = 'STARTTLS';
    const LOGOUT = 'LOGOUT';
    
    const SET_FLAGS = 'FLAGS';
	const ADD_FLAGS = '+FLAGS';
	const ADD_FLAGS_SILENT = '+FLAGS.SILENT';
	const REMOVE_FLAGS = '-FLAGS';
	const REMOVE_FLAGS_SILENT = '-FLAGS.SILENT';
	
	const SET_GMAIL_LABELS = 'X-GM-LABELS';
	const SET_GMAIL_LABELS_SILENT = 'X-GM-LABELS.SILENT';
	const ADD_GMAIL_LABELS = '+X-GM-LABELS';
	const ADD_GMAIL_LABELS_SILENT = '+X-GM-LABELS.SILENT';
	const REMOVE_GMAIL_LABELS = '-X-GM-LABELS';
	const REMOVE_GMAIL_LABELS_SILENT = '-X-GM-LABELS.SILENT';
    
	const ALL  = 'ALL';
	const FAST = 'FAST';
	const FULL = 'FULL';
	const BODY = 'BODY[]';
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
