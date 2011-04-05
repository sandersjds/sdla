<?php	

// ***** ***** ***** ***** ***** ***** ***** 
// ***** ***** config options
// ***** ***** ***** ***** ***** ***** ***** 

$upload_dir='/opt/ihs/htdocs/en_US/isc/parser/sdla/upl/'; // TRAILING SLASH OMG
$file_expiry=strtotime('-1 day', time());
$shortmode=FALSE; // disables "long loading" portions of the logs - pretty much everything except the summaries
$debug=FALSE; // TRUE makes the program show the debug tab
$php_debug=FALSE; //TRUE enables the php engine debugger; be prepared for a lot of bullshit when you turn this one on