<?php

// ***** ***** ***** ***** ***** ***** ***** 
// ***** ***** config defaults
// ***** ***** ***** ***** ***** ***** ***** 


if (!isset($upload_dir)) $upload_dir=str_replace('index.php','',$_SERVER["SCRIPT_FILENAME"]).'upl/'; // TRAILING SLASH OMG
if (!isset($file_expiry)) $file_expiry=strtotime('-1 day', time());
if (!isset($shortmode)) $shortmode=FALSE; // disables "long loading" portions of the logs - pretty much everything except the summaries
if (!isset($debug)) $debug=FALSE; // TRUE makes the program show debug info
if (!isset($php_debug)) $php_debug=FALSE; // TRUE enables the php engine debugger; be prepared for a lot of bullshit when you turn this one on


if (!isset($dimmdbenable)) $dimmdbenable=FALSE; // true enables the external DIMM DB lookup by manufacturer P/N
if (!isset($dimmdbinclude)) $dimmdbinclude='../../m/adodb/adodb.inc.php'; // path to the DIMM database include
if (!isset($dimmdbpath)) $dimmdbpath='../../m/dimm/dimmdb.sqlite.db'; // path to the DIMM database itself

// ***** ***** ***** ***** ***** ***** ***** 
// ***** ***** variables
// ***** ***** ***** ***** ***** ***** ***** 

$aSDC=array();

// $aMeta=array();
// $aChassis=array();
// $aMgmt=array();
// $aBlades=array();
// $aIO=array();
// $aMSIM=array();
// $aPower=array();
// $aCooling=array();
// $aStorage=array();
// $aMedia=array();
// $aScale=array();
// $aEvt=array();
// $aUnhandled=array();
// $aPowerMeta=array();
// $aLicenses=array();

$aNoVPD=array();
$timers=array();


// ***** ***** ***** ***** ***** ***** ***** 
// ***** ***** databases
// ***** ***** ***** ***** ***** ***** ***** 

	if ($dimmdbenable) {
		// the function that normalizes the data for field 'search'
		function dbnorm($e) { return strtoupper(str_replace(array(' ','-'),'',$e)); }
		
		require_once($dimmdbinclude);
		define('DIMMDB',$dimmdbpath);
		
		$dimmdb=&ADONewConnection('sqlite');
		$dimmdb->Connect(DIMMDB);
		$dimmdb->debug = FALSE;
	}
	


// ***** ***** ***** ***** ***** ***** ***** 
// ***** ***** functions
// ***** ***** ***** ***** ***** ***** ***** 


// file handling; used for handling uploads
function listdir($dir){
	$files=array();
	$directory=opendir($dir);
	while($item=readdir($directory)) {
		if(($item!=".")&&($item!="..")) {
			$files[]=$item;
		}
	}
	return $files;
}

// file handling; used for handling uploads
function deltree($dir) {
	$files=glob($dir.'*',GLOB_MARK);
	foreach ($files as $file){
		if (is_dir($file)) delTree($file);
		else unlink($file);
	}
	if (is_dir($dir)) rmdir($dir);
}
// file handling; used for handling uploads
function fCleanupFiles($d) {
	global $file_expiry;
	//$expires=strtotime('-1 day', time());
	foreach (listdir($d) as $entry) {
		// run the cleanup
		// if file is more than 24 hours old, delete it
		if (is_dir($d.$entry) || is_file($d.$entry)) $fileage=filemtime($d.$entry);
		if (($file_expiry-$fileage)>0) { // delete!
			// DEBUG echo '<!-- deleting '.$d.$entry.' -->';
			deltree($d.$entry);
		}
	}
}

// should not be used in the code anyplace;
// was attempted for correcting copy/pastes from lotus notes
// which uses god knows what kind of line break, because this didn't help
/*function normalize($s){
	// Normalize line endings
	// Convert all line-endings to UNIX format
	$s=str_replace("\r\n","\n",$s);
	$s=str_replace("\r","\n",$s);
	// Don't allow out-of-control blank lines
	//$s=preg_replace("/\n{2,}/","\n\n",$s);
	return $s;
}*/

function fBuildTree($b) {
	// arguments:
	// array $b, the full file read into a array
	
	// returns:
	// an array containing a map of the full array given
	
	$aTopopath=preg_grep('#Topopath#i', $b);
	if ($aTopopath) {

		// make room for "lead-in"
		$aReturn[]=array(
			'path'=>'HEADER',
			'id'=>'header',
			'datatype'=>'meta',
			'linestart'=>'0'
		);

		$i=1;
		foreach ($aTopopath as $key => $value) {
			$aReturn[$i]['path']=trim(str_replace(array('TopoPath is ','"','.'),'',$value));
			//echo "\n\n<!-- $value -->\n\n";
			
			// for whatever reason, new firmware starts the topopath with a slash, screwing up our depth counts
			// let's drop that slash
			if (substr($aReturn[$i]['path'],0,1)=='/') $aReturn[$i]['path']=substr($aReturn[$i]['path'],1);
			
			$aReturn[$i]['p']=explode('/',$aReturn[$i]['path']);
			$aReturn[$i]['id']=$aReturn[$i]['p'][count($aReturn[$i]['p'])-1];
			if (count($aReturn[$i]['p'])>1) $aReturn[$i]['parent']=$aReturn[$i]['p'][count($aReturn[$i]['p'])-2];
			$aReturn[$i]['depth']=count($aReturn[$i]['p']);
			$aReturn[$i]['linestart']=$key-1;
			$aReturn[$i]['slot']=fReturnId($aReturn[$i]['id']);
			if (count($aReturn[$i]['p'])>1) $aReturn[$i]['parentslot']=fReturnId($aReturn[$i]['parent']);
			foreach ($aReturn[$i]['p'] as $depth => $val) { $aReturn[$i]['slotpath'][$depth]=fReturnId($val); }
			//unset($aReturn[$i]['p']);
			$i++;
		}

		foreach ($aReturn as $key => $value) {
			if(isset($aReturn[$key+1])) { $aReturn[$key]['lineend']=$aReturn[$key+1]['linestart']-4; }
			else {
				// if it's the last entry in the topopath, gotta find the ending line number by pinpointing where the log starts
				// NEW THING (OCT 8, 2010)! the "Event Log" comes after "Blade Scalable Complex", if there are scalable blades installed
				// so if "Blade Scalable Complex" exists we have more data and need to adjust
				$aScaleComplexStart=array_keys(preg_grep('# Blade Scalable Complex #i', $b));
				$aEventLogStart=array_keys(preg_grep('#\* Event Log #i', $b));
				
				if ($aScaleComplexStart) {
					$aReturn[$key]['lineend']=$aScaleComplexStart[0]-3;
					
					$aEndDelimiter[$i]=preg_grep('# Event Log #i', $b);
					if (count($aEndDelimiter[$i])) {
						$aReturn[$i]['path']='scale';
						$aReturn[$i]['id']='scale';
						$aReturn[$i]['datatype']='meta';
						$aReturn[$i]['linestart']=$aScaleComplexStart[0]+2;
						$aEndDelimiter[$i]=array_keys($aEndDelimiter[$i]);
						$aReturn[$i]['lineend']=$aEndDelimiter[$i][0]-2;
						$i++;
					}
					
				} else {
					$aReturn[$key]['lineend']=$aEventLogStart[0]-3;
				}
			}
		}
		
		// from here, we can map out the footer info
		// the logic of this section is very likely HORRID
		// and will probably break spectacularly.
		// TODO: make this not suck.
		
		
		// eventlog
		$aEndDelimiter[$i]=preg_grep('#Fuel Gauge#i', $b);
		if (count($aEndDelimiter[$i])) {
			$aReturn[$i]['path']='evtlog';
			$aReturn[$i]['id']='evtlog';
			$aReturn[$i]['datatype']='meta';
			$aReturn[$i]['linestart']=$aEventLogStart[0]+2;
			$aEndDelimiter[$i]=array_keys($aEndDelimiter[$i]);
			$aReturn[$i]['lineend']=$aEndDelimiter[$i][0]-2;
			$i++;
		}
		
		// fuelgauge
		$aEndDelimiter[$i]=preg_grep('#LDAP Configuration information#i', $b);
		if (count($aEndDelimiter[$i])) {
			$aReturn[$i]['path']='fuelgauge';
			$aReturn[$i]['id']='fuelgauge';
			$aReturn[$i]['datatype']='meta';
			$aReturn[$i]['linestart']=$aEndDelimiter[$i-1][0]+1;
			$aEndDelimiter[$i]=array_keys($aEndDelimiter[$i]);
			$aReturn[$i]['lineend']=$aEndDelimiter[$i][0]-3;
			$i++;
		}
		
		// bofm config
		$aBeginDelimiter[$i]=preg_grep('#Blade Center Open Fabric Manager #i', $b);
		$aEndDelimiter[$i]=preg_grep('#SAS Zone Config Data:#i', $b);
		if (count($aBeginDelimiter[$i])) {
			$aReturn[$i]['path']='bofmconfig';
			$aReturn[$i]['id']='bofmconfig';
			$aReturn[$i]['datatype']='meta';
			$aBeginDelimiter[$i]=array_keys($aBeginDelimiter[$i]);
			$aReturn[$i]['linestart']=$aBeginDelimiter[$i][0]+2;
			$aEndDelimiter[$i]=array_keys($aEndDelimiter[$i]);
			$aReturn[$i]['lineend']=$aEndDelimiter[$i][0]-1;
			$i++;
		}
		
		// licensed features
		// end delimiter should be EOF
		$aBeginDelimiter[$i]=preg_grep('#Licensed Features#i', $b);
		$aEndDelimiter[$i]=count($b);
		if (count($aBeginDelimiter[$i])) {
			$aReturn[$i]['path']='licenses';
			$aReturn[$i]['id']='licenses';
			$aReturn[$i]['datatype']='meta';
			$aBeginDelimiter[$i]=preg_grep('#Licensed Features#i', $b);
			$aBeginDelimiter[$i]=array_keys($aBeginDelimiter[$i]);
			$aReturn[$i]['linestart']=$aBeginDelimiter[$i][0]+1;
			$aReturn[$i]['lineend']=$aEndDelimiter[$i];
			$i++;
		}
		
		return $aReturn;
	} else { return false; }
}

function fWalkTree($a,$b,$e=0) {
	// arguments:
	// array $a, the map to walk
	// array $b, the full file read into a array
	// optional integer $e, the map number to begin at
	
	global $timers;
	
	while ($e<count($a)) {
		$node=$a[$e];
		$node['mapkey']=$e;
		$pointer=$node['linestart']; while ($pointer<=$node['lineend']) { $aSection[]=$b[$pointer++]; }
		
		if ($node['datatype']!='meta') {
			// determine component type
			if (is_array($aSection)) {
				$aCompType=preg_grep('#Component Type#i', $aSection);
				if (count($aCompType)==1) { // TODO: and if this returns more or less than one result??
					$aCompType=array_values($aCompType);
					$aCompType=explode(":",$aCompType[0]); $aCompType=trim($aCompType[1]);				
					switch ($aCompType) {
						case "CHASSIS":		fDrawChassis($node,$aSection);		break;
						case "MGMT MOD":	fDrawMgmt($node,$aSection);			break;
						case 'MGT_MOD':		fDrawMgmt($node,$aSection);			break; // possible for 55J
						case "BLADE":		fDrawBlade($node,$aSection);		break;
						case "PROCESSOR":	fDrawCPU($node,$aSection);			break;
						case "STORAGE":		fSkipSection();						break; // ***** this may need more refinement!
						case "MEMORY":		fDrawMemory($node,$aSection);		break;
						case "PANEL":		fSkipSection();						break; // ***** front panel, only HS22s? is this useful, ever?
						case "INTERCONNECT":	fDrawScaleCard($node,$aSection);				break; // sidescale cards in HX5 blades
						case "CARD EXPN":	fDrawHBA($node,$aSection,0);		break; // adapter cards
						case "HS CARD EX":	fDrawHBA($node,$aSection,1);		break; // high-speed adapter cards
						case "HSCARD":		fDrawHBA($node,$aSection,1);		break; // high-speed adapter cards, changed in 54G
						case "CARD_EXP":	fDrawHBA($node,$aSection,0);		break; // possible for 55J; adapter cards
						case "ADDIN CARD":	fDrawCKVM($node,$aSection);			break; // ckvm cards
						case "ADDINCARD":	fDrawCKVM($node,$aSection);			break; // ckvm cards
						case "SYS CARD EX":	fDrawMgmtCard($node,$aSection);		break; // management cards on JS blades
						case "SYS CARD":	fDrawMgmtCard($node,$aSection);		break; // management cards on JS blades
						case "SYSCARD":		fDrawMgmtCard($node,$aSection);		break; // possible for 55J + POWER7 blades
						case "BEM":			fDrawExpansion($node,$aSection);	break; // expansion card for double-wide blades
						case "BATTERY":		fDrawBattery($node,$aSection);		break; // note that this applies to both batteries in blades as well as batteries for raid modules
						case "POWER":		fDrawPower($node,$aSection);		break;
						case "COOLING":		fDrawCooling($node,$aSection);		break; // both PM fans and blowers
						case "IO_MODULE":	fDrawIO($node,$aSection);			break;
						case "IO_MOD":		fDrawIO($node,$aSection);			break; // possible for 55J
						case "CARD RAID":	fDrawRSSM($node,$aSection);			break;					
						case "CARD_RAID":	fDrawRSSM($node,$aSection);			break; // changed in 54G
						case "ST MODULE":	fDrawSTModule($node,$aSection);		break;
						case "STG_MOD":		fDrawSTModule($node,$aSection);		break; // changed in 54G
						case "INTERPOSER":	fDrawMSIM($node,$aSection);			break; // MSIMs in H-chassis
						case "17":			fDrawMSIM($node,$aSection);			break; // this one is just completely absurd
						case "MUX":			fSkipSection();						break; // component of BC-HT chassis; unknown if this is useful
						case "MEDIA MOD":	fDrawMediaTray($node,$aSection);	break;
						case "MEDIA_MOD":	fDrawMediaTray($node,$aSection);	break; // possible for 55J
						default:			fDrawDefault($node,$aSection);		// if we don't recognize it, send it to the bucket!
					}
				} elseif (count($aCompType)===0) {
					fDrawDefault($node,$aSection); // no component type, probably no VPD; dump to unhandled sections
				}
			} else {
				//echo '<!-- '.print_r($node,TRUE).' -->';
			}
		} else { // is meta!
			switch ($node['id']) {
				case "header":		fDrawHeader($node,$aSection);		break;
				case "evtlog":		fDrawEvt($node,$aSection);			break;
				case "fuelgauge":	fDrawFuelGauge($node,$aSection);	break;
				case "licenses":	fDrawLicenses($node,$aSection);		break;
				case "scale":		fDrawScaleData($node,$aSection);	break;
				case "bofmconfig":	/*fDrawLicenses($node,$aSection);*/		break;
				default:			fDrawDefault($node,$aSection);
			}
		}
		$e++; unset($aSection);
	}
}



// ****
// **** Section parsing functions

	// arguments for each:
	// array $n, node array
	// array $s, section array 

// ****



function fSkipSection() { return FALSE; }

/*
function fDrawDefaultOrig($n,$s) {
	global $aSDC;

	(preg_grep('#Property failed to read status#', $s))?$errclass=' novpd':$errclass='';
	
	$aSDC['unhandled'][]='<a name="'.$n['path'].'"></a>'."\n".'<div class="nodehead"><span class="coloredbar'.$errclass.'">'.$n['path']." - section starts on line: ".$n['linestart'].'</span></div>';
	$aSDC['unhandled'][]='<div id="'.$n['path'].'" class="node"><pre>';

	$aSDC['unhandled'][]=implode("",$s);
	$aSDC['unhandled'][]="</pre></div>\n".'<div class="nodefoot"><span class="coloredfoot">';
	if ($n['parent']) $aSDC['unhandled'][]='child of '.$n['parent'].' - ';
	$aSDC['unhandled'][]='section ends on line: '.$n['lineend'].'</span></div>';
	$aSDC['unhandled'][]="\n\n";
}
*/

function fDrawDefault($n,$s) {
	global $aSDC,$aNoVPD;

	if (preg_grep('#Property failed to read status#', $s)) {
		if ($n['id']!='PANEL[1]' && $n['id']!='STORAGE[1]' && $n['id']!='STORAGE[2]') {
			// vpd read errors
			$aNoVPD[]='<a name="'.$n['path'].'"></a>'."\n".'<div class="nodehead"><span class="coloredbar novpd">'.$n['path']." - section starts on line: ".$n['linestart'].'</span></div>';
			$aNoVPD[]='<div id="'.$n['path'].'" class="node"><pre>';
			$aNoVPD[]=implode("",$s);
			$aNoVPD[]="</pre></div>\n".'<div class="nodefoot"><span class="coloredfoot">';
			if ($n[parent]) $aNoVPD[]='child of '.$n[parent].' - ';
			$aNoVPD[]='section ends on line: '.$n['lineend'].'</span></div>';
			$aNoVPD[]="\n\n";
		}
	} else {
		// unrecognized section
		$aSDC['unhandled'][]='<a name="'.$n['path'].'"></a>'."\n".'<div class="nodehead"><span class="coloredbar">'.$n['path']." - section starts on line: ".$n['linestart'].'</span></div>';
		$aSDC['unhandled'][]='<div id="'.$n['path'].'" class="node"><pre>';
		$aSDC['unhandled'][]=implode("",$s);
		$aSDC['unhandled'][]="</pre></div>\n".'<div class="nodefoot"><span class="coloredfoot">';
		if ($n[parent]) $aSDC['unhandled'][]='child of '.$n[parent].' - ';
		$aSDC['unhandled'][]='section ends on line: '.$n['lineend'].'</span></div>';
		$aSDC['unhandled'][]="\n\n";
	}
}

function fDrawHeader($n,$s) {
	global $aSDC;
	
	$aSDC['meta']['time']=fSplitByColon(preg_grep('#Time:#', $s));
	$aSDC['meta']['name']=fSplitByColon(preg_grep('#Name:#', $s));
	$aSDC['meta']['ammip']=fSplitByColon(preg_grep('#IP address:#', $s));
	$aSDC['meta']['gmt']=fSplitByColon(preg_grep('#GMT offset:#', $s));
	$aSDC['meta']['health']=fSplitByColon(preg_grep('#System Health#', $s));
	
	$temp=array_keys(preg_grep('#System Health#', $s));
	$healthstart=$temp[0]+1;
	$pointer=$healthstart; while ($pointer<=$n['lineend']) { $aHealthSection[]=$s[$pointer++]; }
	if ($aHealthSection) $aSDC['meta']['healthdetail']=implode("",$aHealthSection);
	
	
}

function fDrawChassis($n,$s) {
	global $aSDC,$aLogfileIndex;
	
	$aSDC['chassis'][$n['slot']]['id']=$n['id'];
	$aSDC['chassis'][$n['slot']]['mtm']=fSplitByColon(preg_grep('#Machine Type/Model#', $s));
	$aSDC['chassis'][$n['slot']]['sn']=fSplitByColon(preg_grep('#Machine Serial Number#', $s));
	$aSDC['chassis'][$n['slot']]['type']=fSplitByColon(preg_grep('#Sub Type#', $s));
	$aSDC['chassis'][$n['slot']]['power']=fSplitByColon(preg_grep('#Power Mode#', $s));
	$aSDC['chassis'][$n['slot']]['fru']=fSplitByColon(preg_grep('#FRU Number#', $s));
		//superfluous: $aLogfileIndex[$n['mapkey']]['fru']=$aSDC['chassis'][$n['slot']]['fru'];
	$aSDC['chassis'][$n['slot']]['kvmowner']=fSplitByColon(preg_grep('#KVM Owner#', $s));
	$aSDC['chassis'][$n['slot']]['mediaowner']=fSplitByColon(preg_grep('#MT Owner#', $s));
		$aLogfileIndex[$n['mapkey']]['parsed']=$aSDC['chassis'][$n['slot']];
}

function fDrawMgmt($n,$s) {
	global $aSDC,$aLogfileIndex;
	
	// telco interposer stuff
	($n['depth']==3)?$slotname=$n['parentslot']:$slotname=$n['slot'];
	
	$aSDC['mgmt'][$slotname]['id']=$n['id'];
	$aSDC['mgmt'][$slotname]['role']=fSplitByColon(preg_grep('#Component Role#', $s));
	$aSDC['mgmt'][$slotname]['fw']=fSplitByColon(preg_grep('#Build ID#', $s));
	$aSDC['mgmt'][$slotname]['fru']=fSplitByColon(preg_grep('#FRU Number#', $s));
	$aSDC['mgmt'][$slotname]['conf']=fSplitByColon(preg_grep('#Configuration behaviors#', $s));
	$aSDC['mgmt'][$slotname]['mac']=fSplitByColon(preg_grep('#Link Ifc Addr in use#', $s));
		$aLogfileIndex[$n['mapkey']]['parsed']=$aSDC['mgmt'][$slotname];
}

function fDrawBlade($n,$s) {
	global $aSDC,$aLogfileIndex;
	
	$aSDC['blade'][$n['slot']]['id']=$n['id'];
	$aSDC['blade'][$n['slot']]['name']=fSplitByColon(preg_grep('#Name #', $s));
	$aSDC['blade'][$n['slot']]['mtm']=fSplitByColon(preg_grep('#Machine Type/Model#', $s));
	$aSDC['blade'][$n['slot']]['sn']=fSplitByColon(preg_grep('#Machine Serial Number#', $s));
	$aSDC['blade'][$n['slot']]['fru']=fSplitByColon(preg_grep('#FRU Number#', $s));
		//superfluous: $aLogfileIndex[$n['mapkey']]['fru']=$aSDC['blade'][$n['slot']]['fru'];
	$aSDC['blade'][$n['slot']]['width']=fSplitByColon(preg_grep('#Width#', $s));
	
	// get the port info
	$ports=array_values(preg_grep('#Topology Path ID#', $s));
	$type=array_values(preg_grep('#Link Ifc Addr Type#', $s));
	$speed=array_values(preg_grep('#Maximum Link Speed#', $s));
	$protocol=array_values(preg_grep('#Link Ifc Transport Protocol#', $s)); 
	$addr=array_values(preg_grep('#Link Ifc Addr in use#', $s));
	
	foreach ($ports as $index => $each) {
		$aSDC['blade'][$n['slot']]['port'][fSplitByColon($each)]['port']=fSplitByColon($ports[$index]);
		$aSDC['blade'][$n['slot']]['port'][fSplitByColon($each)]['type']=fSplitByColon($type[$index]);
		$aSDC['blade'][$n['slot']]['port'][fSplitByColon($each)]['speed']=fSplitByColon($speed[$index]);
		$aSDC['blade'][$n['slot']]['port'][fSplitByColon($each)]['protocol']=fSplitByColon($protocol[$index]);
		$aSDC['blade'][$n['slot']]['port'][fSplitByColon($each)]['addr']=fSplitByColon($addr[$index]);
	}
	
	// getting the firmware versions
	foreach (array_keys(preg_grep('#Build ID#', $s)) as $value) $fw0[]=trim(fSplitByColon($s[$value-1]));
	$fw1=array_values(preg_grep('#Build ID#', $s));
	$fw2=array_values(preg_grep('#Release Level#', $s));
	
	if ($fw0) {
		foreach ($fw0 as $key => $value) {
			switch ($value) {
			case 'FW/BIOS':
				$aSDC['blade'][$n['slot']]['biosb']=fSplitByColon($fw1[$key]);
				$aSDC['blade'][$n['slot']]['biosv']=fSplitByColon($fw2[$key]);
				break;
			case 'Diagnostics':
				$aSDC['blade'][$n['slot']]['diagb']=fSplitByColon($fw1[$key]);
				$aSDC['blade'][$n['slot']]['diagv']=fSplitByColon($fw2[$key]);
				break;
			case 'Blade Sys Mgmt Processor':
				$aSDC['blade'][$n['slot']]['ismpb']=fSplitByColon($fw1[$key]);
				$aSDC['blade'][$n['slot']]['ismpv']=fSplitByColon($fw2[$key]);
				break;
			case 'FPGA':
				$aSDC['blade'][$n['slot']]['fpgab']=fSplitByColon($fw1[$key]);
				$aSDC['blade'][$n['slot']]['fpgav']=fSplitByColon($fw2[$key]);
				break;
			}
		}
	}
	// end firmware shennanigans
	
		$aLogfileIndex[$n['mapkey']]['parsed']=$aSDC['blade'][$n['slot']];
}

function fDrawCPU($n,$s) {
	global $aSDC;

	// clock speed
	$speed=fSplitByColon(preg_grep('#Speed#', $s));
	$aSDC['blade'][$n['parentslot']]['cpu'][$n['slot']]=fSplitByColon(preg_grep('#Speed#', $s));
	
	
	// cpu identifier
	// thx to bejean for this
	// not sure how reliable this is
	
	
	$a=fSplitByColon(preg_grep('#Identifier#', $s));
	if ($a) {
		$b=str_split($a,2);
		$c=ltrim($b[3].$b[2].$b[1].$b[0],'0');
		$extclock=(float)fSplitByColon(preg_grep('#External Clock#', $s));
		if ($extclock>1000) $extclock=$extclock/1000;
		$search_string='http://www.google.com/search?hl=en&lr=&q=site%3Aintel.com+'.$c.'+'.$extclock.'+'.$speed.'&btnI=I%27m+Feeling+Lucky';
		$aSDC['blade'][$n['parentslot']]['cpuid'][$n['slot']]=$search_string;
	}
	
	
	$aLogfileIndex[$n['mapkey']]['parsed']=$aSDC['blade'][$n['parentslot']]['cpu'][$n['slot']];
}

function fDrawMemory($n,$s) {
	global $aSDC,$aLogfileIndex,$dimmdb,$dimmdbenable;

	/*
	$aSDC['blade'][$n['parentslot']]['memory'][$n['slot']]=fSplitByColon(preg_grep('#Size#', $s));
	// what does this need to be?
	// //superfluous: $aLogfileIndex[$n['mapkey']]['fru']==fSplitByColon(preg_grep('#Size#', $s));
		$aLogfileIndex[$n['mapkey']]['parsed']=$aSDC['blade'][$n['parentslot']]['memory'][$n['slot']];
	*/

	// if the memory is in a MAX5 or expansion, the parentslot is always "1"
	if ($n['depth']==4) {
		// BEM/MAX5
		$aSDC['blade'][$n['slotpath'][1]]['expansion'][$n['parentslot']]['memory'][$n['slot']]=fSplitByColon(preg_grep('#Size#', $s));
		$aSDC['blade'][$n['slotpath'][1]]['expansion'][$n['parentslot']]['memorypn'][$n['slot']]=fSplitByColon(preg_grep('#Part Number:#', $s));
		if ($dimmdbenable) {
			$aSDC['blade'][$n['slotpath'][1]]['expansion'][$n['parentslot']]['memoryfru'][$n['slot']]=$dimmdb->GetOne('SELECT ibmfru FROM dimmdb WHERE search=', array(dbnorm(fSplitByColon(preg_grep('#Part Number:#', $s)))));
			$aLogfileIndex[$n['mapkey']]['parsed']['fru']=$dimmdb->GetOne('SELECT ibmfru FROM dimmdb WHERE search=', array(dbnorm(fSplitByColon(preg_grep('#Part Number:#', $s)))));
		} else {
			$aLogfileIndex[$n['mapkey']]['parsed']['fru']=str_replace('(decimal) ','',$aSDC['blade'][$n['slotpath'][1]]['expansion'][$n['parentslot']]['memory'][$n['slot']]);
		}
	} else {
		$aSDC['blade'][$n['parentslot']]['memory'][$n['slot']]=fSplitByColon(preg_grep('#Size#', $s));
		$aSDC['blade'][$n['parentslot']]['memorypn'][$n['slot']]=fSplitByColon(preg_grep('#Part Number:#', $s));
		if ($dimmdbenable) {
			$aSDC['blade'][$n['parentslot']]['memoryfru'][$n['slot']]=$dimmdb->GetOne('SELECT ibmfru FROM dimmdb WHERE search=', array(dbnorm(fSplitByColon(preg_grep('#Part Number:#', $s)))));
			$aLogfileIndex[$n['mapkey']]['parsed']['fru']=$dimmdb->GetOne('SELECT ibmfru FROM dimmdb WHERE search=', array(dbnorm(fSplitByColon(preg_grep('#Part Number:#', $s)))));
		} else {
			$aLogfileIndex[$n['mapkey']]['parsed']['fru']=str_replace('(decimal) ','',$aSDC['blade'][$n['parentslot']]['memory'][$n['slot']]);
		}
	}
}

function fDrawScaleCard($n,$s) {
	global $aSDC,$aLogfileIndex;
	
	$aSDC['blade'][$n['parentslot']]['interconnect']['desc']=fSplitByColon(preg_grep('#Description#', $s));
	$aSDC['blade'][$n['parentslot']]['interconnect']['fru']=fSplitByColon(preg_grep('#Part Number#', $s));
	
	switch($aSDC['blade'][$n['parentslot']]['interconnect']['fru']) {
		// HX5 Speed Burst Card, FRU 59Y5890 (SINGLE-WIDE)
		// HX5 2-Node Scalability Card, FRU 46M6976 (DOUBLE-WIDE)
		// MAX5 Node Scalability Card, FRU 59Y5878 (???)
		case '59Y5890': $aSDC['blade'][$n['parentslot']]['interconnect']['name']='HX5 Speed Burst Card'; break;
		case '46M6976': $aSDC['blade'][$n['parentslot']]['interconnect']['name']='HX5 2-Node Scalability Card'; break;
		case '59Y5878': $aSDC['blade'][$n['parentslot']]['interconnect']['name']='MAX5 Node Scalability Card'; break;
	}
	
		$aLogfileIndex[$n['mapkey']]['parsed']=$aSDC['blade'][$n['parentslot']]['interconnect'];
}

function fDrawHBA($n,$s,$hs=0) {
	global $aSDC,$aLogfileIndex;
	
	($hs)?$slotname='hshba':$slotname='hba';
	
	// if the hba is in an expansion, the parentslot is always "1"
	($n['depth']==4)?$slotdepth=$n['slotpath'][1]:$slotdepth=$n['parentslot'];
	
	$aSDC['blade'][$slotdepth][$slotname][$n['slot']]['desc']=fSplitByColon(preg_grep('#Description#', $s));
	$aSDC['blade'][$slotdepth][$slotname][$n['slot']]['name']=fSplitByColon(preg_grep('#Product Name#', $s));
	$aSDC['blade'][$slotdepth][$slotname][$n['slot']]['manuf']=fSplitByColon(preg_grep('#Manufacturer ID#', $s));
	$aSDC['blade'][$slotdepth][$slotname][$n['slot']]['fru']=fSplitByColon(preg_grep('#FRU Number#', $s));
		//superfluous: $aLogfileIndex[$n['mapkey']]['fru']=$aSDC['blade'][$slotdepth][$slotname][$n['slot']]['fru'];
	
	// get the port info
	$ports=array_values(preg_grep('#Topology Path ID#', $s));
	$type=array_values(preg_grep('#Link Ifc Addr Type#', $s));
	$speed=array_values(preg_grep('#Maximum Link Speed#', $s));
	$protocol=array_values(preg_grep('#Link Ifc Transport Protocol#', $s)); 
	$addr=array_values(preg_grep('#Link Ifc Addr in use#', $s));
	
	foreach ($ports as $index => $each) {
		$aSDC['blade'][$slotdepth][$slotname][$n['slot']]['port'][fSplitByColon($each)]['port']=fSplitByColon($ports[$index]);
		$aSDC['blade'][$slotdepth][$slotname][$n['slot']]['port'][fSplitByColon($each)]['type']=fSplitByColon($type[$index]);
		$aSDC['blade'][$slotdepth][$slotname][$n['slot']]['port'][fSplitByColon($each)]['speed']=fSplitByColon($speed[$index]);
		$aSDC['blade'][$slotdepth][$slotname][$n['slot']]['port'][fSplitByColon($each)]['protocol']=fSplitByColon($protocol[$index]);
		$aSDC['blade'][$slotdepth][$slotname][$n['slot']]['port'][fSplitByColon($each)]['addr']=fSplitByColon($addr[$index]);
	}
		$aLogfileIndex[$n['mapkey']]['parsed']=$aSDC['blade'][$slotdepth][$slotname][$n['slot']];
}

function fDrawCKVM($n,$s) {
	global $aSDC,$aLogfileIndex;
	
	$aSDC['blade'][$n['parentslot']]['ckvm'][$n['slot']]['name']=fSplitByColon(preg_grep('#Product Name#', $s));
	$aSDC['blade'][$n['parentslot']]['ckvm'][$n['slot']]['desc']=fSplitByColon(preg_grep('#Description#', $s));
	$aSDC['blade'][$n['parentslot']]['ckvm'][$n['slot']]['fru']=fSplitByColon(preg_grep('#FRU Number#', $s));
	$aSDC['blade'][$n['parentslot']]['ckvm'][$n['slot']]['pn']=fSplitByColon(preg_grep('#Part Number#', $s));
		//superfluous: $aLogfileIndex[$n['mapkey']]['fru']=$aSDC['blade'][$n['parentslot']]['ckvm'][$n['slot']]['fru'];
		$aLogfileIndex[$n['mapkey']]['parsed']=$aSDC['blade'][$n['parentslot']]['ckvm'][$n['slot']];
}

function fDrawMgmtCard($n,$s) {
	global $aSDC,$aLogfileIndex;
	
	$aSDC['blade'][$n['parentslot']]['mgmt'][$n['slot']]['desc']=fSplitByColon(preg_grep('#Product Name#', $s));
	$aSDC['blade'][$n['parentslot']]['mgmt'][$n['slot']]['fru']=fSplitByColon(preg_grep('#FRU Number#', $s));
		//superfluous: $aLogfileIndex[$n['mapkey']]['fru']=$aSDC['blade'][$n['parentslot']]['mgmt'][$n['slot']]['fru'];
		$aLogfileIndex[$n['mapkey']]['parsed']=$aSDC['blade'][$n['parentslot']]['mgmt'][$n['slot']];
}


function fDrawExpansion($n,$s) {
	global $aSDC,$aLogfileIndex;
	
	$aSDC['blade'][$n['parentslot']]['expansion'][$n['slot']]['name']=fSplitByColon(preg_grep('#Product Name#', $s));
	$aSDC['blade'][$n['parentslot']]['expansion'][$n['slot']]['desc']=fSplitByColon(preg_grep('#Description #', $s));
	$aSDC['blade'][$n['parentslot']]['expansion'][$n['slot']]['fru']=fSplitByColon(preg_grep('#FRU Number#', $s));
	
	// if the name is blank, let's name it ourselves
	if (!$aSDC['blade'][$n['parentslot']]['expansion'][$n['slot']]['name']) {
		switch($aSDC['blade'][$n['parentslot']]['expansion'][$n['slot']]['fru']) {
			case '46M6974': $aSDC['blade'][$n['parentslot']]['expansion'][$n['slot']]['name']='IBM MAX5 expansion blade'; break;
		}
	}
	$aLogfileIndex[$n['mapkey']]['parsed']=$aSDC['blade'][$n['parentslot']]['expansion'][$n['slot']];
}

function fDrawPower($n,$s) {
	global $aSDC,$aLogfileIndex;
	
	$aSDC['power'][$n['slot']]['desc']=fSplitByColon(preg_grep('#Description #', $s));
	$aSDC['power'][$n['slot']]['fru']=fSplitByColon(preg_grep('#FRU Number#', $s));
		//superfluous: $aLogfileIndex[$n['mapkey']]['fru']=$aSDC['power'][$n['slot']]['fru'];
	$aSDC['power'][$n['slot']]['watts']=hexdec(preg_replace('/[^0-9A-Fa-f]/','',fSplitByColon(preg_grep('#Reading#', $s),2)));
	//$aSDC['power'][$n['slot']]['watts']=fSplitByColon(preg_grep('#Reading#', $s),2);
		$aLogfileIndex[$n['mapkey']]['parsed']=$aSDC['power'][$n['slot']];
}

function fDrawCooling($n,$s) {
	global $aSDC,$aLogfileIndex;
	
	$description=fSplitByColon(preg_grep('#Description #', $s));
	
	if ($description=='PM Cooling Device') { // power module
		$aSDC['power'][$n['parentslot']]['cooling'.$n['slot']]['desc']=$description;
		
		// grabs the RPM in hex and converts
		$aSDC['power'][$n['parentslot']]['cooling'.$n['slot']]['temporary']=preg_grep('#Reading#', $s);
		$aSDC['power'][$n['parentslot']]['cooling'.$n['slot']]['temporary']=array_values($aSDC['power'][$n['parentslot']]['cooling'.$n['slot']]['temporary']);
		$aSDC['power'][$n['parentslot']]['cooling'.$n['slot']]['rpm']=hexdec(preg_replace('/[^0-9A-Fa-f]/','',fSplitByColon($aSDC['power'][$n['parentslot']]['cooling'.$n['slot']]['temporary'][1],2)));
		unset($aSDC['power'][$n['parentslot']]['cooling'.$n['slot']]['temporary']);
			$aLogfileIndex[$n['mapkey']]['parsed']=$aSDC['power'][$n['parentslot']]['cooling'.$n['slot']];

	} elseif ($description=='Chassis Cooling Dev') { // chassis blower
		// chassis blower stuff
		$aSDC['cooling'][$n['slot']]['desc']=fSplitByColon(preg_grep('#Description #', $s));
		$aSDC['cooling'][$n['slot']]['fru']=fSplitByColon(preg_grep('#FRU Number#', $s));
			//superfluous: $aLogfileIndex[$n['mapkey']]['fru']=$aSDC['cooling'][$n['slot']]['fru'];

		// grabs the RPM in hex and converts
		$aSDC['cooling'][$n['slot']]['temporary']=preg_grep('#Reading#', $s);
		$aSDC['cooling'][$n['slot']]['temporary']=array_values($aSDC['cooling'][$n['slot']]['temporary']);
		$aSDC['cooling'][$n['slot']]['rpm']=hexdec(preg_replace('/[^0-9A-Fa-f]/','',fSplitByColon($aSDC['cooling'][$n['slot']]['temporary'][1],2)));
		unset($aSDC['cooling'][$n['slot']]['temporary']);
			$aLogfileIndex[$n['mapkey']]['parsed']=$aSDC['cooling'][$n['slot']];
	}
}

function fDrawIO($n,$s) {
	global $aSDC,$aLogfileIndex;
	
	// interposer detection: is this logic always correct?
	// if (is iomodule) & (parent = 7|9) = is iomodule in interposer
	if (($n['parentslot']==7) || ($n['parentslot']==9)) {
		$iobay=$n['parentslot']+$n['slot']-1;
	} else {
		// this is for BCHT chassis, where every switch is in an interposer
		if ($n['depth']==3) {
			$iobay=$n['parentslot'];
		} else {
			$iobay=$n['slot'];
		}
	}
	
	$aSDC['io'][$iobay]['desc']=fSplitByColon(preg_grep('#Description#', $s));
	$aSDC['io'][$iobay]['type']=fSplitByColon(preg_grep('#Sub Type#', $s));
	$aSDC['io'][$iobay]['pn']=fSplitByColon(preg_grep('#Part Number#', $s));
	$aSDC['io'][$iobay]['fru']=fSplitByColon(preg_grep('#FRU Number#', $s));
		//superfluous: $aLogfileIndex[$n['mapkey']]['fru']=$aSDC['io'][$iobay]['fru'];
	$aSDC['io'][$iobay]['manuf']=fSplitByColon(preg_grep('#Manufacturer ID#', $s));
	$aSDC['io'][$iobay]['name']=fSplitByColon(preg_grep('#Product Name#', $s));
	
	// for ICPM port parsing
	if ($aSDC['io'][$iobay]['fru']=='44W4486') fDrawICPM($n,$s,$iobay);
	
	// OMG FIRMWARE
	
	$fwstart=preg_grep('#Firmware data:#', $s);
	if ($fwstart) {
		$fw['linestart']=array_keys($fwstart);
		$fw['linestart']=$fw['linestart'][0]+1;
		
		$fwend=preg_grep('#Port info:#', $s);
		// this next line is because some I/O modules don't show port data, i.e., OPMs
		if (!count($fwend)) $fwend=preg_grep('#Enviroment data:#', $s);
		$fw['lineend']=array_keys($fwend);
		$fw['lineend']=$fw['lineend'][0]-1;
		
		$fwpointer=$fw['linestart']; while ($fwpointer<=$fw['lineend']) { $fwsection[]=$s[$fwpointer++]; }
	}
	
	if (count($fwsection)) {
		$fw_type=array_values(preg_grep('#Type #', $fwsection));
		$fw_bid=array_values(preg_grep('#Build ID#', $fwsection));
		$fw_ftype=array_values(preg_grep('#File Name#', $fwsection));
		$fw_date=array_values(preg_grep('#Release Date#', $fwsection));
		$fw_level=array_values(preg_grep('#Release Level#', $fwsection));
		$fw_version=array_values(preg_grep('#Version#', $fwsection));
		
		foreach ($fw_bid as $index => $each) {
			//fSplitByColon($each)
			$aSDC['io'][$iobay]['fw'][$index]['type']=fSplitByColon($fw_type[$index]);
			$aSDC['io'][$iobay]['fw'][$index]['buildid']=fSplitByColon($fw_bid[$index]);
			$aSDC['io'][$iobay]['fw'][$index]['filename']=fSplitByColon($fw_ftype[$index]);
			$aSDC['io'][$iobay]['fw'][$index]['date']=fSplitByColon($fw_date[$index]);
			$aSDC['io'][$iobay]['fw'][$index]['level']=fSplitByColon($fw_level[$index]);
			$aSDC['io'][$iobay]['fw'][$index]['version']=fSplitByColon($fw_version[$index]);
		}
	}
	
	// ip info
	// first four here may need to be modified to be include "not 2nd"
	$aSDC['io'][$iobay]['mac1']=fSplitByColon(preg_grep('#MAC address#', $s));
	$aSDC['io'][$iobay]['ip1']=fSplitByColon(preg_grep('#IP Address#', $s));
	$aSDC['io'][$iobay]['subnet1']=fSplitByColon(preg_grep('#Subnet Mask#', $s));
	$aSDC['io'][$iobay]['gw1']=fSplitByColon(preg_grep('#Gateway#', $s));
	
	$aSDC['io'][$iobay]['mac2']=fSplitByColon(preg_grep('#2nd MAC address#', $s));
	$aSDC['io'][$iobay]['ip2']=fSplitByColon(preg_grep('#2nd IP Address#', $s));
	$aSDC['io'][$iobay]['subnet2']=fSplitByColon(preg_grep('#2nd Subnet Mask#', $s));
	$aSDC['io'][$iobay]['gw2']=fSplitByColon(preg_grep('#2nd Gateway#', $s));
	$aSDC['io'][$iobay]['vlan']=fSplitByColon(preg_grep('#VLAN ID#', $s));
	$aSDC['io'][$iobay]['configmgmt']=fSplitByColon(preg_grep('#Configuration Management Status#', $s));
	$aSDC['io'][$iobay]['power']=fSplitByColon(preg_grep('#Power State#', $s));
	$aSDC['io'][$iobay]['stacking']=fSplitByColon(preg_grep('#Stacking Mode#', $s));
	$aSDC['io'][$iobay]['protected']=fSplitByColon(preg_grep('#Protected Mode#', $s));
	$aSDC['io'][$iobay]['poststatus']=fSplitByColon(preg_grep('#POST results available#', $s));
	$aSDC['io'][$iobay]['ext-current']=fSplitByColon(preg_grep('#IOM External ports configuration (current)#', $s));
	$aSDC['io'][$iobay]['ext']=fSplitByColon(preg_grep('#IOM External ports configuration:#', $s));
		$aLogfileIndex[$n['mapkey']]['parsed']=$aSDC['io'][$iobay];
}

function fDrawICPM($n,$s,$iobay) {
	global $aSDC,$aLogfileIndex;
	
	$aPorts=preg_grep('#Topology Path ID#i', $s);
	if ($aPorts) {
		foreach ($aPorts as $key => $value) {
			$portnum=fSplitByColon($value);
			$aSDC['icpmtest'][$iobay][$portnum]['linestart']=$key;
		}
		foreach ($aSDC['icpmtest'][$iobay] as $key => $value) {
			if(isset($aSDC['icpmtest'][$iobay][$key+1])) {
				$aSDC['icpmtest'][$iobay][$key]['lineend']=$aSDC['icpmtest'][$iobay][$key+1]['linestart']-3;
			} else {
				// is the last key, lineend should be...? (hint: this is not correct, but is acceptable for now)
				$aSDC['icpmtest'][$iobay][$key]['lineend']=count($s);
			}
		}
		foreach ($aSDC['icpmtest'][$iobay] as $key => $value) {
			$pointer=$aSDC['icpmtest'][$iobay][$key]['linestart']; while ($pointer<=$aSDC['icpmtest'][$iobay][$key]['lineend']) { $aSection[]=$s[$pointer++]; }
			$aSDC['icpmtest'][$iobay][$key]['data']=$aSection;
			
			$aSDC['icpmtest'][$iobay][$key]['portnum']=fSplitByColon(preg_grep('#Port Number #', $aSection));
			$aSDC['icpmtest'][$iobay][$key]['phyport']=fSplitByColon(preg_grep('#Phy Orientation#', $aSection));
			$aSDC['icpmtest'][$iobay][$key]['type']=fSplitByColon(preg_grep('#Type #', $aSection));
			$aSDC['icpmtest'][$iobay][$key]['target']=fSplitByColon(preg_grep('#Connected to#', $aSection));
			$aSDC['icpmtest'][$iobay][$key]['label']=fSplitByColon(preg_grep('#Label #', $aSection));
			$aSDC['icpmtest'][$iobay][$key]['adminstate']=fSplitByColon(preg_grep('#Phy Link Admin State #', $aSection));
			$aSDC['icpmtest'][$iobay][$key]['linkstatus']=fSplitByColon(preg_grep('#Phy Link Status #', $aSection));
			$aSDC['icpmtest'][$iobay][$key]['linkspeed']=fSplitByColon(preg_grep('#Link Speed & Comm Mode  #', $aSection));
			$aSDC['icpmtest'][$iobay][$key]['linkspeedsetting']=fSplitByColon(preg_grep('#Settings#', $aSection));
			
			unset($aSection);
			unset($aSDC['icpmtest'][$iobay][$key]['data']);
		}
	}
}

function fDrawRSSM($n,$s) {
	global $aSDC,$aLogfileIndex;
	
	$aSDC['io'][$n['parentslot']]['rssm']['slot']=$n['slot'];
	$aSDC['io'][$n['parentslot']]['rssm']['desc']=fSplitByColon(preg_grep('#Description#', $s));
	$aSDC['io'][$n['parentslot']]['rssm']['mac']=fSplitByColon(preg_grep('#Link Ifc Addr in use#', $s));
		$aLogfileIndex[$n['mapkey']]['parsed']=$aSDC['io'][$n['parentslot']]['rssm'];
}

function fDrawSTModule($n,$s) {
	global $aSDC,$aLogfileIndex;
	
	$aSDC['storage'][$n['slot']]['desc']=fSplitByColon(preg_grep('#Description#', $s));
	$aSDC['storage'][$n['slot']]['name']=fSplitByColon(preg_grep('#Product Name#', $s));
	$aSDC['storage'][$n['slot']]['fru']=fSplitByColon(preg_grep('#FRU Number#', $s));
	$aSDC['storage'][$n['slot']]['pn']=fSplitByColon(preg_grep('#Part Number#', $s));
	$aSDC['storage'][$n['slot']]['buildid']=fSplitByColon(preg_grep('#Build ID#', $s));
	$aSDC['storage'][$n['slot']]['rlevel']=fSplitByColon(preg_grep('#Release Level#', $s));
		$aLogfileIndex[$n['mapkey']]['parsed']=$aSDC['storage'][$n['slot']];
}

function fDrawMSIM($n,$s) {
	global $aSDC,$aLogfileIndex;

	$aSDC['msim'][$n['slot']]['desc']=fSplitByColon(preg_grep('#Description#', $s));
	$aSDC['msim'][$n['slot']]['name']=fSplitByColon(preg_grep('#Product Name#', $s));
	$aSDC['msim'][$n['slot']]['fru']=fSplitByColon(preg_grep('#FRU Number#', $s));
	$aSDC['msim'][$n['slot']]['pn']=fSplitByColon(preg_grep('#Part Number#', $s));
		$aLogfileIndex[$n['mapkey']]['parsed']=$aSDC['msim'][$n['slot']];
}

function fDrawMediaTray($n,$s) {
	global $aSDC,$aLogfileIndex;

	$aSDC['mediatray'][$n['slot']]['desc']=fSplitByColon(preg_grep('#Description#', $s));
	$aSDC['mediatray'][$n['slot']]['name']=fSplitByColon(preg_grep('#Product Name#', $s));
	$aSDC['mediatray'][$n['slot']]['fru']=fSplitByColon(preg_grep('#FRU Number#', $s));
	$aSDC['mediatray'][$n['slot']]['pn']=fSplitByColon(preg_grep('#Part Number#', $s));
		$aLogfileIndex[$n['mapkey']]['parsed']=$aSDC['mediatray'][$n['slot']];
}

function fDrawBattery($n,$s) {
	global $aSDC,$aLogfileIndex;

	if (substr_count($n[parent],"BLADE")) { // MR10ie batteries
		$aSDC['blade'][$n['parentslot']]['battery']=$n['slot'];
			$aLogfileIndex[$n['mapkey']]['parsed']=$aSDC['blade'][$n['parentslot']]['battery'];
	} elseif (substr_count($n[parent],"MEDIA_MOD")) { //  BC-S media tray batteries
		$aSDC['mediatray'][$n['parentslot']]['battery'.$n['slot']]['desc']=fSplitByColon(preg_grep('#Description#', $s));
		$aSDC['mediatray'][$n['parentslot']]['battery'.$n['slot']]['manuf']=fSplitByColon(preg_grep('#Manufacturer Sub ID#', $s));
		$aSDC['mediatray'][$n['parentslot']]['battery'.$n['slot']]['fru']=fSplitByColon(preg_grep('#FRU Number#', $s));
			$aLogfileIndex[$n['mapkey']]['parsed']=$aSDC['mediatray'][$n['parentslot']]['battery'.$n['slot']];
	} else {
		fDrawDefault($n,$s);
	}
}

function fDrawEvt($n,$s) {
	foreach ($s as $key => $line) { $exploded[]=explode("\t",str_replace("\t\t","\t",$line)); }
	foreach ($exploded as $linenum => $line) {
		if ($linenum<2) continue;
		foreach ($line as $index => $split) {
			if ($index==3) {
				$thisline=$split;
			} else {
				if ($index==4) { $split=strtotime($thisline.' '.$split); unset($thisline);}
				$returnvalue[$linenum][]=$split;
			}
		}
	}
	
	global $aSDC;
	$aSDC['evt']=$returnvalue;
}

function fDrawScaleData($n,$s) {
	global $aSDC;

	foreach ($s as $key => $value) {
		$tempdata[]=$value;
	}
	$aSDC['scale']['parsed']['count']=fSplitByColon(preg_grep('#Number of Scalable Complexes:#', $s));
	$startlines=preg_grep('#Complex Descriptor #', $tempdata);
	//$startlines[count($tempdata)]='endline';
	$startlines=array_keys($startlines);
	
	// $aSDC['scale']['parsed']['count']=count($startlines);
	// $aSDC['scale']['parsed']['complexes']=$startlines;
	
	foreach ($startlines as $key => $value) {
		$complex['linestart']=$value;
		if ($key==(count($startlines)-1)) {
			$complex['lineend']=count($tempdata);
		} else {
			$complex['lineend']=$startlines[$key+1];
		}
		
		$pointer=$complex['linestart']; while ($pointer<=($complex['lineend']-3)) { $complex['data'][]=$tempdata[$pointer++]; }
		
		$complex['state']=fSplitByColon(preg_grep('#  State:#',$complex['data']));
		$complex['primaryblade']=fSplitByColon(preg_grep('#Primary Complex Slot:#',$complex['data']));
		$complex['numslots']=fSplitByColon(preg_grep('#Number of Slots:#',$complex['data']));
		$complex['numnodes']=fSplitByColon(preg_grep('#Number of Nodes:#',$complex['data']));
		$complex['lastupdate']=date('m/d/Y H:i:s',substr(fSplitByColon(preg_grep('#Last Update:#',$complex['data'])),0,-4));
		
		$complex['partitions']=fSplitByColon(preg_grep('#Number of Partitions Configured:#',$complex['data']));
		
		$nodestartlines=preg_grep('#Node Information#',$complex['data']);
		$nodestartlines=array_keys($nodestartlines);
		
		foreach ($nodestartlines as $nodekey => $nodevalue) {
			$complex['nodedetails'][$nodekey]['linestart']=$nodevalue;
			if ($nodekey==(count($nodestartlines)-1)) {
				$complex['nodedetails'][$nodekey]['lineend']=count($complex['data']);
			} else {
				$complex['nodedetails'][$nodekey]['lineend']=$nodestartlines[$nodekey+1];
			}
			
			$pointer=$complex['nodedetails'][$nodekey]['linestart']; while ($pointer<=($complex['nodedetails'][$nodekey]['lineend']-1)) { $complex['nodedetails'][$nodekey]['data'][]=$complex['data'][$pointer++]; }
			
			$complex['nodedetails'][$nodekey]['bladeslot']=(int)fSplitByColon(preg_grep('#Blade Slot Number:#',$complex['nodedetails'][$nodekey]['data']));
			$complex['nodedetails'][$nodekey]['serial']=fSplitByColon(preg_grep('#Serial Number:#',$complex['nodedetails'][$nodekey]['data']));
			$complex['nodedetails'][$nodekey]['pwrstate']=fSplitByColon(preg_grep('#Power State:#',$complex['nodedetails'][$nodekey]['data']));
			$complex['nodedetails'][$nodekey]['flags']=fSplitByColon(preg_grep('#Partition Flags:#',$complex['nodedetails'][$nodekey]['data']));
			
			// assign the blades array with complex identification info
			$aSDC['blade'][$complex['nodedetails'][$nodekey]['bladeslot']]['complex']=(count($aSDC['scale']['parsed']['complex'])+1);
			
			unset($complex['nodedetails'][$nodekey]['linestart']);
			unset($complex['nodedetails'][$nodekey]['lineend']);
			unset($complex['nodedetails'][$nodekey]['data']);
		}
		
		unset($complex['data']);
		$aSDC['scale']['parsed']['complex'][]=$complex;
		unset($complex);
	}
	
	// returns data by default
	$aSDC['scale']['scale']=$tempdata;
}


function fDrawFuelGauge($n,$s) {
	global $aSDC;

	foreach ($s as $key => $value) {
		$aSDC['powermeta']['rawgauge'][]=$value;
	}
}

function fDrawLicenses($n,$s) {
	global $aSDC;
	
	$aSDC['licenses']['features']['BOFM']='IBM BladeCenter Open Fabric Manager';
	$aSDC['licenses']['features']['BOFM-advanced']='IBM BladeCenter Advanced Open Fabric Manager  ';
	$aSDC['licenses']['features']['BOFM-plugin']='IBM BladeCenter Advanced Open Fabric Manager Plug-in';
	
	foreach ($aSDC['licenses']['features'] as $key => $value) {
		$aSDC['licenses']['temp']=preg_grep('#'.$value.'#', $s);
		$aSDC['licenses']['temp']=array_values($aSDC['licenses']['temp']);
		$aSDC['licenses']['temp']=array_flip(array_flip(explode('      ',trim(str_replace($value,'',$aSDC['licenses']['temp'][0])))));

		if (count($aSDC['licenses']['temp'])<2) {
			$aSDC['licenses'][$key]['status']=$aSDC['licenses']['temp'][0];
		} else {
			$aSDC['licenses'][$key]['key']=$aSDC['licenses']['temp'][0];
			$aSDC['licenses'][$key]['status']=trim($aSDC['licenses']['temp'][1]);
		}
		
		unset($aSDC['licenses']['temp']);
	}
	
	foreach ($s as $key => $value) {
		$aSDC['licenses']['details'][]=trim($value);
	}
}




// ****
// **** utility functions
// ****





function fReturnId($name) {
	// arguments:
	// string $name, a string with an id in brackets
	// returns the id number only
	
	$temp=explode('[',$name);
	if (count($temp)>0) return str_replace(']','',$temp[1]);
}

function fSplitByColon($input,$column=1) {
	// arguments:
	// array $input, an array with a value containing a key:value pair split by a colon as returned by preg_grep
	// or!
	// string $input, a string containing a key:value pair
	// returns the value after the colon
	
	// int $column, which column to return data from
	// because honestly, a little data structure never hurt anyone
	// but the guys that wrote this log never heard of it
	
	if (is_array($input)) {
		$i=array_values($input);
		$split=explode(':',$i[0]);
		if (count($split)>2) {
			return trim(substr($i[0],strpos($i[0],':')+1));
		}
		if (count($split)>0) return trim($split[$column]);
	} else {
		$split=explode(':',$input);
		if (count($split)>2) {
			return trim(substr($input,strpos($input,':')+1));
		}
		if (count($split)>0) return trim($split[$column]);
	}
}

function fGetFileList($dir,$recurse=false,$depth=1) {
	$return=array();
	if(substr($dir, -1) != "/") $dir .= "/";
	$d = @dir($dir) or die("fGetFileList: Failed opening directory  $dir for reading");
	while(false !== ($entry = $d->read())) {
		if($entry[0] == ".") continue;
		if(is_dir("$dir$entry")) {
			$return[] = array(
				"name" => "$entry",
				"fname" => "$dir$entry/",
				"depth" => $depth,
				"type" => filetype("$dir$entry"),
				"size" => 0,
				"lastmod" => filemtime("$dir$entry")); 
			if($recurse && is_readable("$dir$entry/")) {
				$return=array_merge($return,fGetFileList("$dir$entry/",true,++$depth));
			}  
		} elseif(is_readable("$dir$entry")) {
			$return[]=array(
				"name" => "$entry",
				"fname" => "$dir$entry",
				"depth" => $depth,
				"type" => "FILE", //mime_content_type("$dir$entry"),
				"size" => filesize("$dir$entry"),
				"lastmod" => filemtime("$dir$entry"));
		} 
	} 
	$d->close();
	return $return; 
}


function fTimer($which,$start=FALSE) {
	global $timers;
	if (!$start) { // init timer
		$temp=explode(' ',microtime());
		$temp=$temp[1]+$temp[0];
		$timers[$which]['start']=$temp;
		return false;
	} else { // report timer value
		$temp=explode(" ", microtime());
		$temp=$temp[1] + $temp[0];
		$timers[$which]['end']=$temp;
		$timers[$which]['total']=($timers[$which]['end'] - $timers[$which]['start']);
		$timers[$which]['round']=round($timers[$which]['total'],4);
		//return $timers[$which]['total'];
	}
}


// ****
// **** output functions
// ****



function fSummarize($e=0) {
	global $aSDC;
	
	// header
	$output[]='<div class="summaryline">Summary Data from '.date("l, j F Y g:ia (H:i:s)",strtotime($aSDC['meta']['time'])).'</div>';
	$output[]='<div class="summaryline"> - Service Data from chassis '.$aSDC['meta']['name'].' @ '.$aSDC['meta']['ammip'].'</div>';
	
	// chassis
	if (count($aSDC['chassis'])) {
		foreach ($aSDC['chassis'] as $key => $chassis) {
			$output[]='<div class="summaryline">'.$chassis['mtm'].'/'.$chassis['sn'].' '.$chassis['type'].'; power mode '.$chassis['power'].', chassis FRU '.$chassis['fru'].'</div>';
		}
		// line break
		$output[]='<div class="summarybreak">&nbsp;</div>';
	}
	
	// amms
	if (count($aSDC['mgmt'])) {
		foreach ($aSDC['mgmt'] as $key => $amm) {
			$output[]='<div class="summaryline">AMM in slot '.$key.' is "'.$amm['role'].'" running '.$amm['fw'].'; FRU '.$amm['fru'].', MAC '.$amm['mac'].' ('.$amm['conf'].')</div>';
		}
		// line break
		$output[]='<div class="summarybreak">&nbsp;</div>';
	}
	
	// blades
	if (count($aSDC['blade'])) {
	foreach ($aSDC['blade'] as $key => $blade) {
		($blade['width'])?$width=$blade['width'].' wide ':$width='';
		($blade['complex'])?$scaletemp=' scale '.$blade['complex']:$scaletemp='';
		if ($e) {
			($blade['diagb'] && $blade['diagv'])?$diagtemp=' DG: '.$blade['diagv'].' ('.$blade['diagv'].') ':$diagtemp='';
			($blade['fpgab'] && $blade['fpgav'])?$fpgatemp=' FPGA: '.$blade['fpgav'].' ('.$blade['fpgab'].') ':$fpgatemp='';
			$temp='<div class="summaryline">Blade '.sprintf("%02s",$key).': '.$blade['mtm'].'/'.$blade['sn'].' FW: '.$blade['biosv'].' ('.$blade['biosb'].') SP: '.$blade['ismpv'].' ('.$blade['ismpb'].') '.$diagtemp.$fpgatemp.$width.'('.$blade['name'].') '.$scaletemp.'</div>';
		} else {
			($blade['diagb'] && $blade['diagv'])?$diagtemp=' DG: '.$blade['diagv'].', ':$diagtemp='';
			($blade['fpgab'] && $blade['fpgav'])?$fpgatemp=' FPGA: '.$blade['fpgav'].', ':$fpgatemp='';
			$temp='<div class="summaryline">Blade '.sprintf("%02s",$key).': '.$blade['mtm'].'/'.$blade['sn'].' FW: '.$blade['biosv'].', SP: '.$blade['ismpv'].', '.$diagtemp.$fpgatemp.$width.'('.$blade['name'].') '.$scaletemp.'</div>';
		}
		
		// extra stuff
		if ($e) {
			unset($extra);
			if (count($blade['memory']) || count($blade['cpu'])) $extra[]="<div class=\"summaryextra\">\t".count($blade['memory']).' DIMMs installed; '.count($blade['cpu']).' processors installed</div>';
			if (count($blade['expansion'])) {
				foreach ($blade['expansion'] as $slot => $bem) {
					if (count($bem['memory'])) $expansiondimms=' '.count($bem['memory']).' DIMMs installed';
					$extra[]="<div class=\"summaryextra\">\t Expansion ".$slot.' '.$bem['fru'].': '.$bem['name'].' ('.$bem['desc'].')'.$expansiondimms.'</div>';
				}
			}
			if (count($blade['interconnect'])) { $extra[]="<div class=\"summaryextra\">\t Interconnect ".$blade['interconnect']['fru'].': '.$blade['interconnect']['name'].' ('.$blade['interconnect']['desc'].')</div>'; }
			if (count($blade['hshba'])) { foreach ($blade['hshba'] as $slot => $adapter) { $extra[]="<div class=\"summaryextra\">\t HSHBA ".$slot.' '.$adapter['fru'].': '.$adapter['name'].' ('.$adapter['desc'].')</div>'; }}
			if (count($blade['hba'])) { foreach ($blade['hba'] as $slot => $adapter) { $extra[]="<div class=\"summaryextra\">\t HBA ".$slot.' '.$adapter['fru'].': '.$adapter['name'].' ('.$adapter['desc'].')</div>'; }}
			if (count($blade['ckvm'])) { foreach ($blade['ckvm'] as $slot => $adapter) { $extra[]="<div class=\"summaryextra\">\t CKVM ".$slot.' '.$adapter['fru'].': '.$adapter['name'].' ('.$adapter['desc'].')</div>'; }}
			if (count($blade['mgmt'])) { foreach ($blade['mgmt'] as $slot => $adapter) { $extra[]="<div class=\"summaryextra\">\t MGMT ".$slot.' '.$adapter['fru'].': '.$adapter['name'].' ('.$adapter['desc'].')</div>'; }}
		}
		
		if (count($extra)) $output[]=$temp.implode('',$extra).'<div class="summaryextraspace">&nbsp;</div>'; else $output[]=$temp;
	}
		// line break
		$output[]='<div class="summarybreak">&nbsp;</div>';
	}
	
	// power and power cooling
	if (count($aSDC['power'])) {
	foreach ($aSDC['power'] as $key => $power) {
		$temp='<div class="summaryline">PSU slot '.$key.': '.$power[watts].'W ('.$power[desc].') FRU '.$power[fru];
		if ($power[cooling1][rpm]) $temp.='; fan pack @ '.$power[cooling1][rpm].' RPM';
		$output[]=$temp.'</div>';
	}
		// line break
		$output[]='<div class="summarybreak">&nbsp;</div>';
	}
	
	// blowers
	if (count($aSDC['cooling'])) {
		foreach ($aSDC['cooling'] as $key => $blower) {
			$temp='<div class="summaryline">Blower '.$key.' ('.$blower[desc].')';
			if ($blower[fru]) $temp.=': FRU '.$blower[fru]; else $temp.='(no FRU in log)';
			if ($blower[rpm]) $temp.=' @ '.$blower[rpm].' RPM';
			$output[]=$temp.'</div>';
		}
		// line break
		$output[]='<div class="summarybreak">&nbsp;</div>';
	}
	
	// io modules
	if (count($aSDC['io'])) {
		foreach ($aSDC['io'] as $key => $io) {
			if (!$io['name']) $io['name']=$io['desc'];
			$output[]='<div class="summaryline">I/O '.sprintf("%02s",$key).': FRU '.$io[fru].', P/N '.$io[pn].'; '.$io[manuf].' '.$io[type].': '.$io[name].'</div>';
			if ($io['rssm']) $output[]='<div class="summaryline">&nbsp; &nbsp; &nbsp; &nbsp; RAID Card: '.$io['rssm']['desc'].', MAC '.$io['rssm']['mac'].'</div>';
			
			if ($e) {
				if (count($io['fw'])) {
					$output[]='<div class="summaryextra"><table>';
					foreach ($io['fw'] as $index => $fw) {
						$output[]='<tr><td>'.$fw['type'].'</td><td>'.$fw['buildid'].'</td><td>'.$fw['filename'].'</td><td>'.$fw['date'].'</td><td>'.$fw['level'].'</td><td>'.$fw['version'].'</td></tr>';
					}
					$output[]='</table></div>';
				}
				
				if ($io['mac1'] || $io['mac2']) {
					$output[]='<div class="summaryextra"><table>';
					if ($io['mac1']) $output[]='<tr><td width="25%">MAC: '.$io['mac1'].'</td><td width="25%">IP: '.$io['ip1'].'</td><td width="25%">subnet: '.$io['subnet1'].'</td><td width="25%">gateway: '.$io['gw1'].'</td></tr>';
					if ($io['mac2']) $output[]='<tr><td width="25%">MAC: '.$io['mac2'].'</td><td width="25%">IP: '.$io['ip2'].'</td><td width="25%">subnet: '.$io['subnet2'].'</td><td width="25%">gateway: '.$io['gw2'].'</td></tr>';
					$output[]='</table></div>';
				}
				
				$output[]='<div class="summaryextra">Config over external ports: '.$io['configmgmt'].'; Stacking: '.$io['stacking'].'; Protected Mode: '.$io['protected'].'</div>';
				$output[]='<div class="summaryextra">Module Power: '.$io['power'].'; <strong>'.$io['poststatus'].'</strong></div>';
				$output[]='<div class="summaryextraspace">&nbsp;</div>';
			}
		}
		// line break
		$output[]='<div class="summarybreak">&nbsp;</div>';
	}
	
	// interposers
	if (count($aSDC['msim'])) {
		foreach ($aSDC['msim'] as $key => $msim) {
			$output[]='<div class="summaryline">MSIM '.sprintf("%02s",$key).': FRU '.$msim['fru'].', P/N '.$msim['pn'].'; '.$msim['desc'].': '.$msim['name'].'</div>';
		}
		// line break
		$output[]='<div class="summarybreak">&nbsp;</div>';
	}
	
	if (count($aSDC['storage'])) {
		foreach ($aSDC['storage'] as $key => $storage) {
			$output[]='<div class="summaryline">Storage Module '.sprintf("%02s",$key).': FRU '.$storage['fru'].', P/N '.$storage['pn'].'; '.$storage['desc'].': '.$storage['name'].'</div>';
			if ($e) { $output[]='<div class="summaryextra">Firmware Build ID: '.$storage['buildid'].'; Firmware Release Level: '.$storage['rlevel'].'</div>'; }
		}
		// line break
		$output[]='<div class="summarybreak">&nbsp;</div>';
	}
	
	// media tray(s)
	if (count($aSDC['mediatray'])) {
		foreach ($aSDC['mediatray'] as $key => $media) {
			$temp='<div class="summaryline">Media Tray '.sprintf("%02s",$key).': FRU '.$media['fru'].', P/N '.$media['pn'].'; '.$media['desc'];
			if ($media['name']) $temp.=': '.$media['name'];
			$output[]=$temp.'</div>';
		}
		// line break
		$output[]='<div class="summarybreak">&nbsp;</div>';
	}
	
	// bofm
	if (count($aSDC['licenses'])) {
		foreach ($aSDC['licenses'] as $key => $license) {
			if ($key=='details'||$key=='features') continue;
			if ($license['status']=='No License') continue;
			$output[]='<div class="summaryline">'.$aSDC['licenses']['features'][$key].' Status: '.$license['status'].'</div>';
		}
		// line break
		$output[]='<div class="summarybreak">&nbsp;</div>';
	}
	
	return implode("\n",$output);
}

function fShowHealthSummary() {
	global $aSDC;
	
	$output[]='<strong>'.$aSDC['meta']['health']."</strong>\n\n";
	$output[]=$aSDC['meta']['healthdetail'];
	return implode("",$output);
}

function fShowICPMData() {
	global $aSDC;
	// $aSDC['icpmtest']
	// 1) cycle through the number of ICPMs
	// 2) cycle through the data for each icpm
	
	foreach ($aSDC['icpmtest'] as $switchbay => $data) {
		$output[]='<h2>ICPM in I/O bay '.$switchbay.'</h2>';
		$output[]='<table id="" class="icpmtable tablesorter">';
		$output[]='<thead><tr><th>port</th><th>phy</th><th>type</th><th>label</th><th>admin state</th><th>status</th><th>speed</th><th>speed setting</th></tr></thead>';
		foreach ($data as $portnum => $portdata) {
			$output[]='<tr><td>'.$portdata['portnum'].'</td>';
			$output[]='<td>'.$portdata['phyport'].'</td>';
			$output[]='<td>'.$portdata['type'].'</td>';
			$output[]='<td>'.$portdata['label'].'</td>';
			$output[]='<td>'.$portdata['adminstate'].'</td>';
			$output[]='<td>'.$portdata['linkstatus'].'</td>';
			$output[]='<td>'.$portdata['linkspeed'].'</td>';
			$output[]='<td>'.$portdata['linkspeedsetting'].'</td>';
			$output[]='</tr>';
		}
		$output[]='</table>';
	}
	
	
	return implode("\n",$output);
}

function fShowScaleNotice() {
	global $aSDC;
	
	($aSDC['scale']['parsed']['count']==1)?$complex_plural='':$complex_plural='es';
	
	$output[]='This chassis contains '.$aSDC['scale']['parsed']['count']." scalable complex".$complex_plural.". See the details tab for further info.\n";
	
	foreach ($aSDC['scale']['parsed']['complex'] as $key => $value) {
		if ($value['numnodes']>0) { foreach ($value['nodedetails'] as $nodekey => $nodeval) { $templine[]=$nodeval['bladeslot']; } }
		
		($value['numnodes']==1)?$node_plural='':$node_plural='s';
		$output[]="\tComplex ".($key+1).': '.$value['state'].', '.$value['numnodes'].' node'.$node_plural;
		if ($value['numnodes']>0) { $output[]=' - slot'.$node_plural.' '.implode(', ',$templine); }
		$output[]="\n";
		unset($templine);
	}
	
	return implode($output);
}

function fBuildScaleChart() {
	global $aSDC;
	
	$output[]='<table id="scaledetailstable" class="tablesorter"><thead><tr><th>complex</th><th>state</th><th>slots</th><th>nodes</th><th>partitions</th><th>primary</th><th>last update</th><th>slot</th><th>serial</th><th>state</th><th>flags</th></thead>';
	
	foreach ($aSDC['scale']['parsed']['complex'] as $key => $val) {
		$output[]='<tr><td>Complex '.($key+1).'</td><td>'.$val['state'].'</td><td>'.$val['numslots'].'</td><td>'.$val['numnodes'].'</td><td>'.$val['partitions'].'</td><td>'.$val['primaryblade'].'</td><td>'.$val['lastupdate'].'</td>';
	
		if ($val['numnodes']>0) {
			foreach ($val['nodedetails'] as $nodekey => $nodeval) {
				$slotdetails['bladeslot'][$nodekey]=$nodeval['bladeslot'];
				$slotdetails['serial'][$nodekey]=$nodeval['serial'];
				$slotdetails['pwrstate'][$nodekey]=$nodeval['pwrstate'];
				$slotdetails['flags'][$nodekey]=$nodeval['flags'];
			}

			foreach ($slotdetails as $slotval => $slotkey) {
				$output[]='<td>'.implode('<br />',$slotkey).'</td>';
			}
		} else {
			$output[]='<td></td><td></td><td></td><td></td>';
		}	
		
		$output[]='</tr>';
		unset($slotdetails);
	}
	
	$output[]='</table>';
	
	return implode($output);
}

function fBuildSOLChart() {
	global $aSDC;
	
	//$output[]="##: &nbsp; R &nbsp; C &nbsp; E &nbsp; P &nbsp; ServProc IP\n";
	//$output[]="---------------------------------\n";

	$output[]='<table id="solstatustable" class="tablesorter"><thead><tr><th>#</th><th>ready</th><th>capable</th><th>enabled</th><th>power</th><th>ServProc IP</th><th>Status</th></thead>';
	
	foreach ($aSDC['blade'] as $key => $value) {
		($value['power'])?$pwr='':$pwr='NO';
		($value['state']=='Ready')?$state='':$state='NO';
		($value['solenabled'])?$ena='':$ena='NO';
		($value['solcapable'])?$capable='':$capable='NO';
		($value['soladdr'])?$addr=$value['soladdr']:$addr='';
		
		
		//$output[]=sprintf("%02s",$key).': &nbsp; '.$state.'  &nbsp;'.$capable.' &nbsp; '.$ena.' &nbsp; '.$pwr.' &nbsp; '.$addr;
		//if ($value['soltext'] && ($value['state']!='Ready')) $output[]=' ('.$value['soltext'].')';
		//$output[]="\n";
		if ($value['soltext'] && ($value['state']!='Ready')) $status=$value['soltext']; else $status='';
		$output[]='<tr><td class="sol_slot">'.sprintf("%02s",$key).'</td><td class="sol_state">'.$state.'</td><td class="sol_cap">'.$capable.'</td><td class="sol_ena">'.$ena.'</td><td class="sol_pwr">'.$pwr.'</td><td class="sol_addr">'.$addr.'</td><td class="sol_text">'.$status.'</td></tr>';
	}
	
	$output[]='</table>';
	
	return implode("",$output);
}


function fBuildPowerDetails() {
	global $aSDC;
	
	//$aSDC['powermeta']['rawgauge'];
	
	/*
	Maximum Power Consumption:  8640
	Average Power Consumption:  1513
	Total Thermal Output:  5162 BTU/hour

	Power Domain 1:
		Status:  Power domain 1 status is good
		Modules:  
		  Bay 1:   2940
		  Bay 2:   2940
		Power Management Policy: Non-redundant
		Power in Use:            883
		Total Power:            3520
		Allocated Power (Max):  1545
		Remaining Power:        1975

	Power Domain 2:
		Status:  Power domain 2 status is good
		Modules:  
		  Bay 3:   2940
		  Bay 4:   2940
		Power Management Policy: Non-redundant
		Power in Use:            279
		Total Power:            3520
		Allocated Power (Max):   281
		Remaining Power:        3239

	Acoustic Mode: off
	Data Sampling Interval: 10
	*/
	
	/*
	
	what this needs to do:
	this will generate the summary for the power section.
	this will essentially report the fuel gauge section above with some added clarity.
	some notes:
		max power consumption (???) possibly a historical maximum? or is this a theoretical limit?
		total thermal output = average power consumption * 3.412 (1W = 3.412 BTU/hr)
		power management policy determines total power along with PSU wattage available (more forthcoming)
		power in use is exactly that - current draw of all the blades + components on the two power supplies in that domain
		allocated power is the power reserved by the components currently installed in that domain
		remaining power is total power minus allocated power
	
		acoustic mode defaults to off.
			when off, blowers & fans will ramp up to account for thermal events
			when on, the chassis will attempt to throttle blades first; if it fails, it will them ramp up fans
		
		data sampling interval is listed in minutes (10 minutes = 600 seconds, the default value)
		
	power management policies:
		"Redundant without performance impact" or "Power Module Redundancy"
		"Redundant with potential performance impact" or "Power Module Redundancy with Blade Throttling Allowed"
		"Non-redundant" or "Basic Power Management"
	*/
}



function fReportChildrenOf($which,$map) {
	foreach ($map as $key => $value) {

		// for BC-HT chassis; AMMs are listed as children of other things called AMMs, that are not.
		// this is a bad workaround, but just prevents an endless loop.
		if ($value['parent']==$value['id']) continue;
		
		if ($value['parent']==$which) $return[$key]=$map[$key];
	}
	
	return $return;
}

function fDrawMap($a,$start="") {
	global $aLogfile;
	$children=fReportChildrenOf($start,$a);
	if (count($children)) {
		//DEBUG: echo '<!--'.print_r($children,TRUE).'-->';
		foreach ($children as $k => $n) {
			if ($n['id']!='evtlog') {
				($n['parsed']['fru'])?$nodefru=' &nbsp; <em>'.$n['parsed']['fru'].'</em>':$nodefru='';
				$next=fReportChildrenOf($n['id'],$a);
				if (count($next)) {
					echo '<a class="maplink collapsor mapfolder" href="#data_'.$k.'">'.$n['id'].$nodefru.'</a>'."\n";
					echo '<div class="collapsee depth'.$n['depth'].'" id="collapse_'.$k.'">'."\n";
					
					$class=' mappagegear';
					$label='<em>(node data)</em>';
				} else {
					$class=' mappageonly';
					$label=$n['id'].$nodefru;//.' <em>(data only)</em>';
				}
				
				echo '<a class="maplink collapsor'.$class.'" href="#data_'.$k.'">'.$label.'</a>'."\n";
				echo '<div class="collapsee nodedata" id="data_'.$k.'"><pre>';
				
				$pointer=$n['linestart']; while ($pointer<=$n['lineend']) { $aSection[]=$aLogfile[$pointer++]; }
				echo implode("",$aSection);
				unset($aSection);
				
				echo '</pre></div>'."\n";
				
				fDrawMap($a,$n[id]);
				
				if (count($next)) { echo '</div>'."\n"; }
			}
		}
	}
}

function fEvtlog($a) {
	$return[]='<div id="sortalert">please wait</div>';
	$return[]='<div id="sort_sev"></div>';
	$return[]='<div id="sort_source"></div>';
	$return[]='<table id="eventlogtable" class="tablesorter"><thead><tr><th>#</th><th>Sev</th><th>Source</th><th>Date/Time</th><th>EventID</th><th>Text</th></tr></thead>';
	foreach ($a as $line) {
			//indices:
			//	0 Index
			//	1 Sev
			//	2 Source
			//	3 Date Time
			//	4 Call Home
			//	5 Event ID
			//	6 Text

		// searches out and finds AIX error messages in the logs
		// this gives a 10% overhead to the event log parsing
		
		if (preg_match('/(([A-Fa-f0-9]{8}\s{0,1}){10})/',$line[6],$matches)) {
			// OK here's the way this is going to work
			// since we're here, we know that the error in question is an AIX/RISC system request code.
			// there are eight categories of SRCs, each with their own links/format within the online documentation.
			// so this will break out the various codes and give a proper link for each type.
			
			$errs=explode(' ',$matches[0]);
			$errs=$errs[1];
			if (preg_match('/1\w{7}/',$errs)) {
				// 1xxxyyyy
				$link='<a class="infoctr aixerror" href="http://publib.boulder.ibm.com/infocenter/bladectr/documentation/topic/com.ibm.bladecenter.js22.doc/dw1fx_r_spcn.html">'.$errs.'</a>';
			} elseif (preg_match('/6\w{7}/',$errs)) {
				// 6xxxyyyy
				$link='<a class="infoctr aixerror" href="http://publib.boulder.ibm.com/infocenter/bladectr/documentation/topic/com.ibm.bladecenter.js22.doc/dw1fx_r_6xxx.html">'.$errs.'</a>';
			} elseif (preg_match('/A1\w{6}/',$errs)) {
				// A1xxyyyy
				$link='<a class="infoctr aixerror" href="http://publib.boulder.ibm.com/infocenter/bladectr/documentation/topic/com.ibm.bladecenter.js22.doc/dw1fx_r_fsp_attentioncodes.html">'.$errs.'</a>';
			} elseif (preg_match('/AA\w{6}/',$errs)) {
				// AA00E1A8 to AA260005
				$link='<a class="infoctr aixerror" href="http://publib.boulder.ibm.com/infocenter/bladectr/documentation/topic/com.ibm.bladecenter.js22.doc/dw1fx_r_attentioncodes.html">'.$errs.'</a>';
			} elseif (preg_match('/B200\w{4}/',$errs)) {
				// B200xxxx
				$link='<a class="infoctr aixerror" href="http://publib.boulder.ibm.com/infocenter/bladectr/documentation/topic/com.ibm.bladecenter.js22.doc/dw1fx_r_errorcodes_a2b2.html">'.$errs.'</a>';
			} elseif (preg_match('/B700\w{4}/',$errs)) {
				// B700xxxx 
				$link='<a class="infoctr aixerror" href="http://publib.boulder.ibm.com/infocenter/bladectr/documentation/topic/com.ibm.bladecenter.js22.doc/dw1fx_r_errorcodes_a7b7.html">'.$errs.'</a>';
			} elseif (preg_match('/BA\w{6}/',$errs)) {
				// BA000010 to BA400002
				$link='<a class="infoctr aixerror" href="http://publib.boulder.ibm.com/infocenter/bladectr/documentation/topic/com.ibm.bladecenter.js22.doc/dw1fx_r_errorcodes_ba.html">'.$errs.'</a>';
			} elseif (preg_match('/B\w{7}/',$errs)) {
				// Bxxxxxxx
				// note the order of these errors... this one needs to go last
				
				// this one is special - it gets specific pages for each error
				$link='<a class="infoctr aixerror" href="http://publib.boulder.ibm.com/infocenter/powersys/v3r1m5/index.jsp?topic=/arebh/'.$errs.'.htm">'.$errs.'</a>';
			}
	
			//$errs=explode(' ',$matches[0]);
			//$link='<a class="infoctr aixerror" href="http://publib.boulder.ibm.com/infocenter/powersys/v3r1m5/index.jsp?topic=/arebh/'.$errs[1].'.htm">'.$errs[1].'</a>';
			
			// this really doesn't need to change, just deemphasize it
			$err_text='<em>'.$line[6].'</em>';
		} else {
			$err_text=$line[6];
			$link='<a class="infoctr" href="http://publib.boulder.ibm.com/infocenter/bladectr/documentation/index.jsp?topic=/com.ibm.bladecenter.advmgtmod.doc/xhtml_messageoutput/msg_'.$line[5].'.html">'.$line[5].'</a>';
		}
		
		if ($line[1]=='ERR') $linestyle='error '; else
		if ($line[1]=='WARN') $linestyle='warn '; else
		$linestyle='';

		$return[]='	<tr class="datarow">
	<td class="'.$linestyle.'evt_key">'.$line[0].'</td>
	<td class="'.$linestyle.'evt_sev">'.$line[1].'</td>
	<td class="'.$linestyle.'evt_source">'.$line[2].'</td>
	<td class="'.$linestyle.'evt_date">'.date("M j Y H:i:s",$line[3]).'</td>
	<td class="'.$linestyle.'evt_err">'.$link.'</td>
	<td class="'.$linestyle.'evt_text">'.$err_text.'</td>';
	//<!--<td>'.$line[4].'</td>-->
	}
	
	/*$return[]='
<tfoot><tr>
	<th></th>
	<th><input type="text" name="search_sev" value="Search severity" class="search_init" /></th>
	<th><input type="text" name="search_source" value="Search source" class="search_init" /></th>
	<td></td>
	<td></td>
	<td></td>
</tr></tfoot>';*/
	
	$return[]='</table>';
	return implode("",$return);
}

function fEvtFilterBoxes($a) {
	$return='';	
	foreach ($a as $line) { $sources[$line[2]]=TRUE; }
	$return.='<div class="filters">
		<input type="text" id="search">
	<!--<select class="filterbox" name="filter_severity[]" id="filter_severity" multiple="multiple" size="3">
		<option value="ERR" class="sev0">Error</option>
		<option value="WARN" class="sev1">Warning</option>
		<option value="INFO" class="sev2">Info</option>
	</select>-->
	<select class="filterbox arc90_multiselect" name="filter_source[]" id="filter_source" multiple="multiple" size="3">';
	// testing
	$return.='		<option value="ERR" class="sev0">Error</option>
		<option value="WARN" class="sev1">Warning</option>
		<option value="INFO" class="sev2">Info</option>';
	ksort($sources);
	foreach ($sources as $source => $x) { $return.='		<option value="'.$source.'">'.$source.'</option>'; }
	$return.='	</select>
</div>';
	
	$return.='<div class="filters">
	<div class="buttons">
		<a href="#" id="filterreset" class="negative">
			<img src="assets/cross.png" alt=""/>
			Reset
		</a>
	</div>
</div>';
	return $return;
}

function fBuildHeader() { ?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
	"http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html>
<head>
	<title>Service Data Log Annihilator</title>
	<script type="text/javascript" src="assets/jquery-1.4.2.min.js"></script>
	<script type="text/javascript" src="assets/jquery.dataTables.min.js"></script>
	<script type="text/javascript" src="assets/sdla.js"></script>
	<!--<link rel="stylesheet" href="assets/reset.css" type="text/css">-->
	<link rel="stylesheet" href="assets/sdla.css" type="text/css">
	<link rel="shortcut icon" href="//rtsweb1.raleigh.ibm.com/isc/i/images/favicon.ico" />
	<script type="text/javascript">
		var _gaq = _gaq || []; _gaq.push(['_setAccount', 'UA-1812666-5']); _gaq.push(['_trackPageview']);

		(function() {
			var ga = document.createElement('script'); ga.type = 'text/javascript'; ga.async = true;
			ga.src = ('https:' == document.location.protocol ? 'https://ssl' : 'http://www') + '.google-analytics.com/ga.js';
			var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(ga, s);
		})();
	</script>
</head>
<body>
	<div id="page_header">
		<div id="title"><a href="./"><img src="assets/<?php $lgl[]='logo-en.gif'; $lgl[]='logo-es.gif'; echo $lgl[mt_rand(0,1)]; unset($lgl); ?>" alt="Service Data Log Annihilator" /></a></div>
		<div id="lifespan">
		<?php 
	global $upload_dir, $file_expiry;
	if (is_dir($upload_dir.$_SERVER['QUERY_STRING']) && is_file($upload_dir.$_SERVER['QUERY_STRING'].'/primary_ffdc/service.txt')) {
		$expiry=round((((filemtime($upload_dir.$_SERVER['QUERY_STRING'])-$file_expiry)/60)/60),2);
		$upload_type='full support archive';
	} elseif(is_file($upload_dir.$_SERVER['QUERY_STRING'].'.txt')) {
		$expiry=round((((filemtime($upload_dir.$_SERVER['QUERY_STRING'].'.txt')-$file_expiry)/60)/60),2);
		$upload_type='service.txt only';
	}
	
	if ($expiry) {
?>
			<ul>
				<li><?php echo $expiry; ?> hrs remaining before upload purged</li>
				<li>file type: <?php echo $upload_type; ?></li>
			</ul><?php } ?>
			</div>
	</div>
<?php
}

function fBuildFooter() { 
	global $timers;
?><!--<div id="footer"><div><span><?php
	
	/*
	fTimer('total',1);
	foreach ($timers as $key => $value) {
		if ($key=='total') $echostring[]=$key.': '.$value[round].' s';
		else $echostring[]=$key.': '.round(($value[total]/$timers[total][total])*100,1).'%';
	}
	echo implode(", ",$echostring);
	*/
?></span></div></div>--></body></html><?php
}

function fBuildAcquireNONCOMPLIANT() {
?>		
	<a name="bmlt"></a>
	<div id="bmlt" class="inputbox">
		<h2>Bookmarklet</h2>
		<p>How to use this link:</p>
		<p>
		<ul>
			<li>Don't click the link.</li>
			<li>Drag the link to your bookmarks or bookmarks toolbar.</li>
			<li>View the Archive Parser from a Service Data log (*.tgz) that has been uploaded to the <a href="http://multitool.raleigh.ibm.com/customer_logs/">Multitool</a>.</li>
			<li>While the Archive Parser is visible in your browser, click the bookmark you saved in the second step.</li>
		</ul>
		</p>
		<p><a href="javascript:location.href='http://rtsweb1.raleigh.ibm.com/isc/parser/sdla/?url='+encodeURIComponent(location.href)">Annihilate.</a></p>
	</div>
	
	<div id="inputfile" class="inputbox">
		<h2>Upload file</h2>
		<form enctype="multipart/form-data" action="./" method="POST">
			<input type="hidden" name="action" value="upld">
			<!-- MAX_FILE_SIZE must precede the file input field 500000 ~= 500kb -->
			<input type="hidden" name="MAX_FILE_SIZE" value="768000" />
			<input name="upld" type="file" />
			<input type="submit" value="Send File" />
		</form>
	</div>
	<div id="inputtext" class="inputbox">
		<form method="POST" action="./" id="inputform" name="inputform">
			<input type="hidden" name="action" value="txtonly">
			<div class="buttons">
				<button class="positive" type="submit">
					<img src="assets/cog.png" alt=""/>
					Parse
				</button>
				<button class="negative" type="reset">
					<img src="assets/cross.png" alt=""/>
					Reset
				</button>
			</div>
			<h2>Input Service.txt to parse</h2>
			<textarea name="txtarea"></textarea>
		</form>
	</div>
<?php
}

function fBuildAcquire($errortext=FALSE) {
	global $options;
	$fi='_FIND-PATH.php';$ix='';$yx='';$px='';
	while($ix<6){if(file_exists($yx.$fi)){break;}$yx.='../';$ix++;}
	define('PDEPTH',$ix);while($ix>0){$px.='../';$ix=$ix-1;}require($px.$fi);
	
	$options['page_title']='Service Data Log Annihilator';
	$options['sidebarstyle']='none';
	$options['update_date']='oct 29 2010';
	$options['content_owner']='Josh Sanders';
	$options['content_review']=$options['update_date'];
	$options['breadcrumbs'][]='<a href="./">ISC Server Support</a>';
	$options['breadcrumbs'][]='<a href="parser/">Log Parsers</a>';
	//$options['breadcrumbs'][]='<a href="m/curtain.php?'.$qstring.'">Control</a>';
	$options['breadcrumbs'][]='<a href="'.$_SERVER[REQUEST_URI].'">Service Data Log Annihilator</a>';
	
	process(); ?>

<!-- actual content begins -->

<?php //echo '<!-- '.print_r($px.$fi,TRUE).' -->'; ?>

<img src="parser/sdla/assets/<?php $lgl[]='logo-en.gif'; $lgl[]='logo-es.gif'; echo $lgl[mt_rand(0,1)]; unset($lgl); ?>" alt="Service Data Log Annihilator" />
<blockquote>
	<?php if($errortext) { echo '<p>'.$errortext.'</p>'; } ?>
	<p>The Service Data Log Annihilator takes logs generated by a BladeCenter Advanced Management Module running firmware 2.46C or above and summarizes the output for ease of reading.</p>
	<p>There are three ways to submit data to the Annihilator: using the bookmarklet, by uploading a file, or by pasting the contents of a service.txt file into the input window. The bookmarklet is the preferred method.</p>
	<blockquote>
		<h2>Bookmarklet</h2>
		<p><a style="background-color:#a00;color:#fff;padding:5px;margin-left:20px;text-decoration:none;font-weight:bold;" href="javascript:location.href='http://rtsweb1.raleigh.ibm.com/isc/parser/sdla/?url='+encodeURIComponent(location.href)" onclick="return false;">Annihilate.</a></p>
		<p>Instructions for use:</p>
		<ol>
			<li><strong>Don't click the red link on this page.</strong></li>
			<li>Drag the red link above to your bookmarks or bookmarks toolbar.</li>
			<li>View the Archive Parser from a Service Data log (*.tgz) that has been uploaded to the Multitool.</li>
			<li>While the Multitool Archive Parser is visible in your browser, click the bookmark you saved in the second step.</li>
		</ol>
		<p>If the Multitool Archive Parser opens in a new Firefox window that doesn't have your bookmarks menus, you can adjust your Firefox configuration to prevent this behavior:</p>
		<ol>
			<li>In Firefox, navigate to <strong>about:config</strong></li>
			<li>Search for <strong>browser.link.open_newwindow.restriction</strong> and change the value to <strong>0</strong></li>
			<li>Search for <strong>browser.link.open_newwindow</strong> and change the value to <strong>3</strong></li>
		</ol><br />
		
		<br /><div class="hrule-dots">&nbsp;</div><br />
		
		<h2>Upload File</h2>
		<p>
			<form enctype="multipart/form-data" action="parser/sdla/" method="POST">
				<input type="hidden" name="action" value="upld">
				<!-- MAX_FILE_SIZE must precede the file input field 500000 ~= 500kb -->
				<input type="hidden" name="MAX_FILE_SIZE" value="768000" />
				<input name="upld" type="file" />

				<input type="submit" value="Send File" />
			</form>
		</p><br />
		
		<br /><div class="hrule-dots">&nbsp;</div><br />
		
		<h2>Input Service.txt to parse</h2>
		<p>
			<form method="POST" action="parser/sdla/" id="inputform" name="inputform">
				<input type="hidden" name="action" value="txtonly">
				<div class="buttons">
					<button class="positive" type="submit">
						<!--<img src="assets/cog.png" alt=""/>-->
						Parse
					</button>
					<button class="negative" type="reset">
						<!--<img src="assets/cross.png" alt=""/>-->
						Reset
					</button>
				</div>
				<textarea style="height:400px;width:100%;" name="txtarea"></textarea>
			</form>
		</p>
	</div>

		
		
	</blockquote>
</blockquote>


<!-- actual content ends -->

<?php /* DO NOT REMOVE THIS LINE! */ process_footer();
}

function fBuildError() {
?>
		<a name="error"></a>
		<div id="error" class="inputbox">
			<h2>Error</h2>
			<p>Data received does not contain the necessary constructs to allow parsing!</p>
			<p>Did you input the <strong>full text of a service.txt</strong> file, a <strong>*.tgz</strong> file, or a <strong>*.tar</strong> file exported from an <strong>Advanced Management Module</strong> running <strong>firmware 2.46C or above</strong>?</p>
		</div>

<?php
}

function fBuildFileList($q) {
	global $upload_dir;
	$return[]='<h2>File List</h2><pre>';
	$aDirList=fGetFileList($upload_dir.$q,TRUE);
	
	foreach ($aDirList as $item) {
		$temp='  ';
		$count=$item['depth']; while ($count-- > 1) { $temp.="  "; }
		if ($item['type']=='dir') {
			$temp.='<span class="filelist mapfolder">'.$item['name']."</span>\n";
		} else {
			$temp.='<a class="filelist mapfile" onclick="'."$('#filelist').load('".'?ajax=loadfile&file='.$q.'&filename='.$item['fname']."');".'" href="#">'.$item['name']."</a>\n";
		}
		$return[]=$temp;
	}
	
	$return[]='</pre>';
	$return[]='<script>$("a.filelist").click(function(event) { event.preventDefault(); });</script>';
	return implode('',$return);
}




// ****
// **** vdbg.txt functions
// ****



function fParseVDBG($b) {
	/*
	how this needs to work:
	// first column is always the first character, and should be parsed into something else
	// second column starts at the second character and ends at the first space
	// third column starts at the first space and ends at the second space
	// fourth column is everything else
	*/
	
	$aReturn[]='<table id="vdbgtable" class="tablesorter">'."\n";
	$aReturn[]='<thead><tr><th>#</th><th>err</th><th>source</th><th>time</th><th>text</th></tr></thead>';

	$i=0;
	foreach ($b as $key => $val) {
		if ($key < 8) continue;
		if (substr($val,0,1)=='!') {
			$ftext='ERROR';
			$linestyle=' error';
		} else {
			$ftext= '';
			$linestyle='';
		}
		$aReturn[]='<tr>';
		$aReturn[]='<td class="vdbg_index'.$linestyle.'">'.$i++.'</td>';
		$aReturn[]='<td class="vdbg_iserr'.$linestyle.'">'.$ftext.'</td>';
		$aReturn[]='<td class="vdbg_source'.$linestyle.'">'.trim(substr($val,1,strpos(substr($val,1),' ')+1)).'</td>';
		$aReturn[]='<td class="vdbg_date'.$linestyle.'">'.trim(substr($val,strpos($val,'(')+1,8)).'</td>';
		$aReturn[]='<td class="vdbg_text'.$linestyle.'">'.trim(substr($val,strpos($val,')')+1)).'</td>';
		$aReturn[]='</tr>'."\n";
	}

	$aReturn[]='</table>';
	return implode('',$aReturn);
}




// ****
// **** developer.txt functions
// ****


function fReturnSection($b,$delim_top,$delim_bot,$offset_top,$offset_bot) {
	// currently only used by fBuildDevMap
	// arguments
	// array $b, the full file read into an array
	// string $delim_top, $delim_bot, strings used to delimit the returned section
	// $offset_top, $offset_bot, offsets to move from the delimiters (negative values remove lines or go up)
	
	$aStart=array_keys(preg_grep('#'.$delim_top.'#i', $b));
	$aEnd=array_keys(preg_grep('#'.$delim_bot.'#i', $b));
	$aDelims['linestart']=$aStart[0]+$offset_top;
	$aDelims['lineend']=$aEnd[0]+$offset_bot;

	$i=$aDelims['linestart']; while ($i<=$aDelims['lineend']) { $aReturn[]=rtrim($b[$i++]); }
	return $aReturn;
}


function fBuildDevMap($b) {
	// arguments
	// array $b, the full file read into an array
	
	global $aSDC;
	
	if ($b) {
	
		// parsing power details chart

		$aPWRSection=fReturnSection($b,'chassis aggregate total computed','I2C Reset History ',2,-7);
		
		foreach ($aPWRSection as $key => $value) {
			$line=preg_split('/ +/',str_replace(array('name','switch','chassis cooling device','fan pak',' mm ','midplane','med try','blade',' --- '),'',trim($value)));
			if ($line[0]<1) continue;
			
			switch ($line[0]) {
				case 1:	$type='blade    ';	break;
				case 2:	$type='switch   ';	break;
				case 3:	$type='amm      ';	break;
				case 4:	$type='blower   ';	break;
				case 5:	$type='mediatray';	break;
				case 6:	$type='midplane ';	break;
				case 7:	$type='fanpack  ';	break;
			}
			
			if ($line[0]==1) $aSDC['powermeta']['inuse'][$line[1]]=$line[3];
			
			if ($line[4]<1) $line[4]='';
			if ($line[5]<1) $line[5]='';
			if ($line[6]<1) $line[6]='';
			if ($line[7]<1) $line[7]='';
			
			$totals[3]=$totals[3]+$line[3];
			$totals[4]=$totals[4]+$line[4];
			$totals[5]=$totals[5]+$line[5];
			$totals[6]=$totals[6]+$line[6];
			$totals[7]=$totals[7]+$line[7];
			
			
			// line[2] is a hex entry that is a bitwise binary representation of which pm's are in use
			// thanks, paul budden!
			
			// to convert from hex to decimal: hexdec($line[2])
			// to convert from hex to binary: pack("H*",$line[2])
			$out=$type.' '.sprintf("% 2s",$line[1]).' '.sprintf("% 4s",$line[3]).'  | '.strrev(sprintf("%04s",base_convert($line[2],16,2))).' | '.sprintf("% 4s",$line[4]).' '.sprintf("% 4s",$line[5]).' '.sprintf("% 4s",$line[6]).' '.sprintf("% 4s",$line[7]);
			//$out='<tr><td class="pwrtxt">'.$type.' '.$line[1].'</td><td class="pwrval">'.hexdec($line[2]).'</td><td class="pwrval">'.$line[3].'</td><td class="pwrval">'.$line[4].'</td><td class="pwrval">'.$line[5].'</td><td class="pwrval">'.$line[6].'</td><td class="pwrval">'.$line[7].'</td></tr>';
			
			$aPWReturn[]=$out;
		}
		
		if ($aPWReturn) {
			sort($aPWReturn);
			$aPWReturn[]='-----------------------------------------------';
			$aPWReturn[]='totals:      '.sprintf("% 4s",$totals[3]).'           '.sprintf("% 4s",$totals[4]).' '.sprintf("% 4s",$totals[5]).' '.sprintf("% 4s",$totals[6]).' '.sprintf("% 4s",$totals[7]);
			array_unshift($aPWReturn,'-----------------------------------------------');
			array_unshift($aPWReturn,'type    slot  tot  |    d |  pm1  pm2  pm3  pm4');
			
			//array_unshift($aPWReturn,'<table class="tablesorter" id="pwrdetails"><thead><tr><th>slot</th><th>domain</th><th>total</th><th>pm1</th><th>pm2</th><th>pm3</th><th>pm4</th></tr></thead>');
			//$aPWReturn[]='</table>';
				
			$aSDC['powermeta']['details']=$aPWReturn;
		}
		
		/*
			[0] => type
			[1] => slot
			[2] => domain
			[3] => tot_power
			[4] => pow(pm1)
			[5] => pow(pm2)
			[6] => pow(pm3)
			[7] => pow(pm4)
			[8] => valid_reading

			types:
			1 blade
			2 switch
			3 mm
			4 blower
			5 mediatray
			6 midplane
			7 psufan
		*/
		
		
		// TURBO DATA ACQUISITION!
		foreach (fReturnSection($b,'BLADE POWER STATISTICS','BLADE POWER EXEC STATISTICS',1,-3) as $key => $value) {
		//foreach (fReturnSection($b,'BLADE POWER STATISTICS','BLADE POWER STATISTICS',1,15) as $key => $value) {
			$line=preg_split('/ +/', trim($value));
			$aSDC['powermeta']['powerstats'][]=$line;
		}
		
		// a possibly interesting value from this one: "wCPU," possibly indicating the wattage per CPU 
		foreach (fReturnSection($b,'EXTRA BLADE POWER INFORMATION','EXTRA BLADE POWER INFORMATION',1,count($aSDC['powermeta']['powerstats'])) as $key => $value) {
			$line=preg_split('/ +/', trim($value));
			$aSDC['powermeta']['extrapowerstats'][]=$line;
		}

		
	
		// parsing the SOL
		
		$aSOLSection=fReturnSection($b,'SOL settings','bmclist',2,-2);
		
		$SOLbladelist=preg_grep('/Blade \[[0-9]+]/', $b);
		foreach ($SOLbladelist as $value) {
			$SOLlist[]=explode(']',substr(trim($value),7));
		}
		
		foreach ($SOLlist as $value) { $aSOL[$value[0]][]=trim($value[1]); }
		foreach ($aSOL as $key => $value) {
			if (!$aSDC['blade'][$key]) continue;
			(fSplitByColon(preg_grep('/Present/',$value))=='Yes')?$aSDC['blade'][$key]['present']=TRUE:FALSE;
			$aSDC['blade'][$key]['power']=fSplitByColon(preg_grep('/Power State/',$value));
			(fSplitByColon(preg_grep('/SOL:/',$value))=='Enabled')?$aSDC['blade'][$key]['solenabled']=TRUE:FALSE;
			//$aSDC['blade'][$key]['width']=fSplitByColon(preg_grep('/width/',$value)); // we already know this
			(fSplitByColon(preg_grep('/SOL Capability/',$value))=='Yes')?$aSDC['blade'][$key]['solcapable']=TRUE:FALSE;
			$aSDC['blade'][$key]['soladdr']=fSplitByColon(preg_grep('/Addr/',$value));
			$aSDC['blade'][$key]['state']=fSplitByColon(preg_grep('/^State/',$value));
			$aSDC['blade'][$key]['soltext']=fSplitByColon(preg_grep('/Analyzer/',$value));
		}
		
		return $aReturn;
	}
}