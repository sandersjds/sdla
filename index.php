<?php	

// SERVICE DATA LOG ANNIHILATOR
// PARSES AND SHORTENS IN LESS TIME!

// ***** ***** ***** ***** ***** ***** ***** 
// ***** ***** ***** ***** ***** ***** ***** 

// created and maintained by JOSH SANDERS of
//   IBM System x & BladeCenter Support in Atlanta, Georgia, USA

// business: jds@us.ibm.com
// personal: josh@jsanders.us

// ***** ***** ***** ***** ***** ***** ***** 
// ***** ***** ***** ***** ***** ***** ***** 

// INSTALL
//  step 1: copy files to host
//  step 2: create upload directory, chmod 6777 dir; default is './upl/'


// ***** ***** ***** ***** ***** ***** ***** 
// ***** ***** procedural
// ***** ***** ***** ***** ***** ***** ***** 


if (is_file('config.php')) include_once('config.php');
require_once('sdla.php');

// this is here to make sure you don't screw things up
if (substr($upload_dir, -1)!='/') {
	echo "<!-- you forgot to include the TRAILING SLASH on the upload directory! -->"; //exit;
	$upload_dir=$upload_dir.'/';
}

if ($php_debug) {ini_set("display_errors","2"); ERROR_REPORTING(E_ALL); }

// script timer!
fTimer('total');

// cleans up the working directory
fTimer('cleanup');
fCleanupFiles($upload_dir);
fTimer('cleanup',1);

/*
 ok here's the plan
 if GET/AJAX: do stuff
 if QUERY_STRING: check to see if the file exists, show it
 if QUERY_STRING==LIST: show the list. this should NOT BE LINKED, for debugging only!
 if post(TXTONLY): take the pasted data, make sure it's a logfile, save it as a file
 if post(UPLD): upload the file, handle it as a txt or tgz
 if no GET and no POST: just show the input page
*/

if (($_GET['ajax']) && ($_GET['file'])) {	
	// ok here's the way this should work. ready?
	// ajax requests can only return the requested portion
	// no html, head, or body tags as things can get strange in the DOM otherwise
	
	// so if we have an ajax request and a filename specified, load it up and walk the tree
	
	if (is_dir($upload_dir.$_GET['file']) && is_file($upload_dir.$_GET['file'].'/primary_ffdc/service.txt')) {
		$aAjaxLogfile=file($upload_dir.$_GET['file'].'/primary_ffdc/service.txt');
	} elseif(is_file($upload_dir.$_SERVER['QUERY_STRING'].'.txt')) {
		$aAjaxLogfile=file($upload_dir.$_SERVER['QUERY_STRING'].'.txt');
	}
	if ($_GET['ajax']=='evtlog') {
		if ($aAjaxLogfile) {
			$aLogfileIndex=fBuildTree($aAjaxLogfile);
			if (count($aLogfileIndex)>1) {
				fWalkTree($aLogfileIndex,$aAjaxLogfile);
				
				// now we go through different outputs of ajax requests
				
				
				echo '<a name="evtlog"></a>
					<div id="evtlog" class="outputbox">';
				echo fEvtFilterBoxes($aSDC['evt']);
				echo '<h2>Event Log</h2>';
				fTimer('evtlog');
				echo fEvtlog($aSDC['evt']);
				fTimer('evtlog',1);
				echo '</div>';

			}
		}
	} elseif ($_GET['ajax']=='loadfile') {
		if(is_file($_GET['filename'])) {
			$output=file($_GET['filename']);
			$nameexplode=explode("/",$_GET['filename']);
			echo '<h2>'.$nameexplode[count($nameexplode)-1].'</h2>';
			echo '<p><a id="filelistreturn" onclick="'."$('#filelist').load('".'?ajax=filelist&file='.$_GET['file']."');\"".'" href="#">return to file list</a></p>';
			echo '<table class="plaintexttable"><tr><td class="linenums"><pre>';
			$i=0; while ($i<count($output)) echo '<a class="fileline" name="'.(($i)+1).'" href="#'.(($i)+1).'">'.(($i++)+1)."</a>";
			echo '</pre></td><td class="plaintext"><pre>';
			//echo implode("",$output);
			foreach ($output as $line) { echo '<div class="fileline">'.$line.'</div>'; }
			echo '</pre></td></tr></table>';
			echo '<script>
					$("a.fileline:even").addClass("evenline");$("div.fileline:even").addClass("evenline");
					$("#filelistreturn").click(function(event) { event.preventDefault(); });
				</script>';
		}
	} elseif($_GET['ajax']=='filelist') {
		if (is_dir($upload_dir.$_GET['file'])) echo fBuildFileList($_GET['file']);
	}
	
} elseif ($_GET['url']) {
	$url='http://multitool.raleigh.ibm.com/common/downloads/GetClFile.cgi';
	$querystring=explode('?',$_GET['url']);
	if (strpos($querystring[1],'logId')!==FALSE) {
		$url=$url.'?'.$querystring[1];

		if (!isset($_SERVER['PHP_AUTH_USER'])) {
			header('WWW-Authenticate: Basic realm="w3"');
			header('HTTP/1.0 401 Unauthorized');
			echo 'Text to send if user hits Cancel button';
			exit;
		} else {
			$context=stream_context_create(array('http'=>array('header'=>"Authorization: Basic ".base64_encode($_SERVER[PHP_AUTH_USER].':'.$_SERVER[PHP_AUTH_PW]))));
			$content=file_get_contents($url,false,$context);
			$headers=get_headers($url);
			foreach ($headers as $val) {
				if (strpos($val,'filename=')!==FALSE) {
					$temp=explode('"',$val);
					$filename=$temp[1];
					$extension=explode('.',$filename);
					if (count($extension)>2) $extension2=strtolower($extension[count($extension)-2]);
					$extension=strtolower($extension[count($extension)-1]);
				}
			}
		}

		if ($content && $filename && $extension) {
			//$extension='tgz';
			if ($extension=='tgz' || $extension=='tar' || ($extension=='gz' && $extension2=='tar')) {
				if ($extension=='gz' && $extension2=='tar') $extension='tar.gz';
				($extension=='tar')?$decompression_options=' -xf ':$decompression_options=' -zxf ';
				$upload_rand=base_convert(mt_rand(10,99).time(),10,36);
				$uploadfile=$upload_dir.$upload_rand.'.'.$extension;
				
				$fp=fopen($uploadfile,'w'); fwrite($fp,$content); fclose($fp);
				
				mkdir($upload_dir.$upload_rand);
				exec(escapeshellcmd('tar -C '.$upload_dir.$upload_rand.$decompression_options.$uploadfile));
				exec(escapeshellcmd('chmod -R 777 '.$upload_dir.$upload_rand.'/*'));
				deltree($upload_dir.$upload_rand.'.'.$extension);
				header('Location:./?'.$upload_rand);
			}
		}
	} else {
		//fBuildHeader();
		//echo '<!-- BKMRLT failed! url provided does not contain a "logId" parameter -->';
		fBuildAcquire('<!-- BKMRLT failed! url provided does not contain a "logId" parameter -->');
		//fBuildFooter();
	}
} elseif ($_SERVER['QUERY_STRING']=='list') {
	fBuildHeader();
	echo '<a name="uploads"></a>
			<div id="uploads" class="inputbox">
			<h2>Upload List</h2><table>';
	$dirlist=listdir($upload_dir);
	
	//foreach ($dirlist as $key => $val) { $query=explode('.',$val); $query=$query[0]; $newlist[$key]=$query; }
	//echo '<!-- '.print_r(array_flip(array_flip($newlist)),TRUE).' -->';
	
	asort($dirlist);
	
	foreach ($dirlist as $keydata => $entry) {
		//$fileage=filemtime($upload_dir.$entry);
		$query=explode('.',$entry); $query=$query[0];
		//if ($dirlist[$keydata-1]
		echo '<tr><td><!-- '.$keydata.' --><a href="./?'.$query.'">'.$entry.'</a> </td><td>'.round((((filemtime($upload_dir.$entry)-$file_expiry)/60)/60),2)." hrs remaining</td></td>";
		///echo $entry.": ".date('r',$fileage).' ('.$fileage.') '.$cleanup."\n";
	}
	echo '</table></div>';
	fBuildFooter();
} else {
	if ($_SERVER['QUERY_STRING']) {
		// check to see if the file described by the query string exists and use it
			fTimer('retrieve');
		if (is_dir($upload_dir.$_SERVER['QUERY_STRING']) && is_file($upload_dir.$_SERVER['QUERY_STRING'].'/primary_ffdc/service.txt')) {
			$aLogfile=file($upload_dir.$_SERVER['QUERY_STRING'].'/primary_ffdc/service.txt');
			if (is_file($upload_dir.$_SERVER['QUERY_STRING'].'/primary_ffdc/developer.txt')) {
				$aDevfile=file($upload_dir.$_SERVER['QUERY_STRING'].'/primary_ffdc/developer.txt');
			}
		} elseif(is_file($upload_dir.$_SERVER['QUERY_STRING'].'.txt')) {
			$aLogfile=file($upload_dir.$_SERVER['QUERY_STRING'].'.txt');
		} else {
			//fBuildHeader();
			//echo '<div id="error" class="inputbox"><h2>Error</h2>no file described by '.$_SERVER['QUERY_STRING'].'</div>';
			fBuildAcquire('<div id="error" class="inputbox"><h2>Error</h2>no file described by '.$_SERVER['QUERY_STRING'].'</div>');
			//fBuildFooter();
		}
			fTimer('retrieve',1);
	} elseif ($_POST[action]=='txtonly') {
		// copy/pasted data
			fTimer('split');
		$aLogfile=explode("\n",stripslashes($_POST[txtarea]));
		$validate=fBuildTree($aLogfile);
		
		if (count($validate)>1) {
			// this validates that the file has a topopath before we expose it to our delicate underbelly
			// i mean, filesystem		
			$upload_rand=base_convert(mt_rand(10,99).time(),10,36);
			$writeoperation = fopen($upload_dir.$upload_rand.".txt", "w");
			fwrite($writeoperation, implode("\n",$aLogfile));
			fclose($writeoperation);
			header('Location:./?'.$upload_rand);
		}
			fTimer('split',1);
	} elseif ($_POST[action]=='upld') {
		// uploaded file.
		$extension=explode('.',basename($_FILES['upld']['name']));
		$extension=$extension[count($extension)-1];
		$upload_rand=base_convert(mt_rand(10,99).time(),10,36);
		$uploadfile=$upload_dir.$upload_rand.'.'.$extension;

		if (copy($_FILES['upld']['tmp_name'], $uploadfile)) {
			// file valid and copied to destination folder
			if ($extension=='txt') {
				// just a text file; needs no directory/extraction
				header('Location:./?'.$upload_rand);
			} elseif ($extension=='tgz' || $extension=='tar') {
				// archive file; must be extracted and have permissions corrected
				mkdir($upload_dir.$upload_rand);
				($extension=='tar')?$decompression_options=' -xf ':$decompression_options=' -zxf ';
				exec(escapeshellcmd('tar -C '.$upload_dir.$upload_rand.$decompression_options.$uploadfile));
				exec(escapeshellcmd('chmod -R 777 '.$upload_dir.$upload_rand.'/*'));
				header('Location:./?'.$upload_rand);
			}
		} else {
			// file not valid and/or not copied to destination folder
			fBuildHeader();
			//debug echo '<!-- '.print_r($_FILES,TRUE).' -->';
			fBuildError();
			fBuildFooter();
		}
	} else {
		//fBuildHeader();
		fBuildAcquire();
		//fBuildFooter();
	}

	if ($aLogfile) {
		fBuildHeader();
		
		fTimer('pass1');
		$aLogfileIndex=fBuildTree($aLogfile);
		fTimer('pass1',1);
		
		if (count($aLogfileIndex)>1) {
			// walk the map, build the individual arrays
			fTimer('pass2');
			fWalkTree($aLogfileIndex,$aLogfile);
			fTimer('pass2',1);
			
			// parse developer.txt for SOL data
			fTimer('SOL');
			fBuildDevMap($aDevfile);
			fTimer('SOL',1);
			
			if ((substr($aSDC['chassis'][1]['mtm'],0,4)==8720) || (substr($aSDC['chassis'][1]['mtm'],0,4)==8730) || (substr($aSDC['chassis'][1]['mtm'],0,4)==8740) || (substr($aSDC['chassis'][1]['mtm'],0,4)==8750)) {
				$telco_chassis=TRUE;
				//$shortmode=TRUE;
			}
			
		
		?>
		<div class="container">
			<ul class="tabs">
				<li><a href="#summariestab">Summary</a></li>
				<li><a href="#extendedsummariestab">Details</a></li>
				<?php
				if (!$shortmode) { 
				if (is_dir($upload_dir.$_SERVER['QUERY_STRING']) && is_file($upload_dir.$_SERVER['QUERY_STRING'].'/primary_ffdc/developer.txt')) { ?>
				<li><a href="#powertab">Power</a></li><?php } ?>
				<li><a href="#maptab">Map</a></li>
				<li><a id="evtlogtab" href="#eventlogtab">Eventlog</a></li>
				<?php
					if ($aSDC['icpmtest']) { ?><li><a href="#icpmtab">icpm</a></li><?php }
					if (is_dir($upload_dir.$_SERVER['QUERY_STRING']) && is_file($upload_dir.$_SERVER['QUERY_STRING'].'/primary_ffdc/vdbg.txt')) { ?><li><a href="#vdbgtab">vdbg</a></li><?php }
					if (is_dir($upload_dir.$_SERVER['QUERY_STRING'])) { ?><li><a href="#filelisttab">File Browser</a></li><?php }
					/*if (is_dir($upload_dir.$_SERVER['QUERY_STRING']) && is_file($upload_dir.$_SERVER['QUERY_STRING'].'/primary_ffdc/developer.txt')) { ?><li><a href="#soltab">SOL</a></li><?php }*/ 
					} //shortmode ?>
				<li><a href="#abouttab">About</a></li>
				<?php if ($debug) { ?><li><a href="#debugtab" style="color:#f00;">debug</a></li><?php } ?>
			</ul>

			<div class="tab_container">
				<div id="summariestab" class="tab_content">
		<?php
		
			if ($telco_chassis){
				echo '<a name="bctwarning"></a>
						<div id="bctwarning" class="outputbox">
						<h2>Telco Chassis Warning</h2>This log is for a <strong>BLADECENTER TELCO CHASSIS</strong> (MT '.substr($aSDC['chassis'][1]['mtm'],0,4).'). The Annihilator will attempt to parse this log, but be aware that due to some of the data topology structures and widely varying types of output from different firmware versions, the output may look strange, and may produce VERY large logs. If it takes <strong>longer than 10 seconds</strong> or so to complete loading of the page, <strong>press ESCAPE to cancel loading</strong> or your browser may hang.<br /><br />Your mileage may vary; you have been warned.</div>';
			}
						
			// health summary section
			if ($aSDC['meta']['health']!='Good') {
				echo '<a name="healthsummary"></a>
						<div id="healthsummary" class="outputbox">
						<h2>Health Summary</h2><pre>';
				fTimer('hsummary');
				echo fShowHealthSummary();
				fTimer('hsummary',1);
				echo '</pre></div>';
			}
			
			// scale notice
			if ($aSDC['scale']['parsed']['count']>0) {
				echo '<a name="scalenotice"></a>
						<div id="scalenotice" class="outputbox">
						<h2>Scalable Complexes</h2><pre>';
				fTimer('scalenotice');
				echo fShowScaleNotice();
				fTimer('scalenotice',1);
				echo '</pre></div>';
			}
			
			
			// summary section
			echo '<a name="summary"></a>
					<div id="summary" class="outputbox">
					<h2>Summary</h2><p>';
			fTimer('summary');
			
			echo '<div class="normaldata">';
			echo fSummarize();
			echo '</div>';
			
			fTimer('summary',1);
			echo '</p></div>';

			
			// unhandled section
			if (count($aSDC['unhandled'])) {
				echo '<a name="unhandled"></a>
						<div id="unhandled" class="outputbox">
						<h3>Sections with a component type currently unrecognized by the parser.<br />This is a bug; you should let <a href="mailto:jds@us.ibm.com">Josh</a> know when you see these.</h3>
						<h2>Unhandled Sections</h2>';
				fTimer('unh');
				echo implode($aSDC['unhandled']);
				fTimer('unh',1);
				echo '</div>';
			}
			
			// vpd read errors
			if (count($aNoVPD)) {
				echo '<a name="vpderr"></a>
						<div id="vpderr" class="outputbox">
						<h3>Sections reporting VPD read errors which are therefore unrecognized by the parser.<br />This is NOT a bug with the parser, but a lack of information reported by the AMM.</h3>
						<h2>Sections with VPD read errors</h2>';
				fTimer('vpd');
				echo implode($aNoVPD);
				fTimer('vpd',1);
				echo '</div>';
			}
			
			?>
				</div>	
				<div id="extendedsummariestab" class="tab_content">
			<?php
		
			// detailed summary tab
			echo '<a name="extsummary"></a>
					<div id="extsummary" class="outputbox">
					<h2>Detailed Summary</h2><p>';
			fTimer('extsummary');
			
			echo '<div class="normaldata">';
			echo fSummarize(1);
			echo '</div>';
			
			fTimer('extsummary',1);
			echo '</p></div>';
			
			// SOL section
			if (is_dir($upload_dir.$_SERVER['QUERY_STRING']) && is_file($upload_dir.$_SERVER['QUERY_STRING'].'/primary_ffdc/developer.txt')) {
				echo '<a name="solsummary"></a>
						<div id="solsummary" class="outputbox">
						<h2>SOL Summary</h2>';
				echo fBuildSOLChart();
				echo '</div>';
			}
			
			// licenses
			if ($aSDC['licenses']['details']) {
				echo '<a name="licenses"></a>
						<div id="licenses" class="outputbox">
						<h2>Licensed Features</h2><pre>';
				
				echo implode('<br />',$aSDC['licenses']['details']);
				echo '</pre></div>';
			}
			
			// scalable partitions section
			if ($aSDC['scale']['scale']) {
				fTimer('scale');
				echo '<a name="scale"></a>
						<div id="scale" class="outputbox">
						<h2>Scalable Partition Summary</h2><pre>';
				
				echo fBuildScaleChart();
				
				//echo '<hr />DEBUG INFO FOLLOWS: '.print_r($aSDC['scale']['parsed'],TRUE).'<hr />';
				
				//echo implode($aSDC['scale']['scale']);
				
				fTimer('scale',1);
				echo '</pre></div>';
			}
	
			?>
				</div>
			<?php if (!$shortmode) { ?>
				<div id="powertab" class="tab_content">
			<?php
				echo '<a name="fuelgauge"></a>
						<div id="fuelgauge" class="outputbox">
						<h3></h3>
						<h2>Fuel Gauge</h2><pre>';
					echo implode('',$aSDC['powermeta']['rawgauge']);
				echo '</pre></div>';

				if (is_dir($upload_dir.$_SERVER['QUERY_STRING']) && is_file($upload_dir.$_SERVER['QUERY_STRING'].'/primary_ffdc/developer.txt')) {
					if ($aSDC['powermeta']['details']) {
						echo '<a name="powerdetails"></a>
								<div id="powerdetails" class="outputbox">
								<h3></h3>
								<h2>Power Details</h2><pre>';
						echo implode("\n",$aSDC['powermeta']['details']);
						echo '</pre></div>';
					}
					
					echo '<a name="powerdetails"></a>
							<div id="powerdetails" class="outputbox">
							<h3></h3>
							<h2>Power Stats</h2>';
					
					$sChartData='';
					$sChartData2='';
					foreach ($aSDC['powermeta']['powerstats'] as $key => $line) {
						if ($key<1) continue;
						$sChartData.='data.setValue('.$key.',0,"B'.$key.'");';
						$sChartData.='data.setValue('.$key.',1,'.$line[10].');';
						$sChartData.='data.setValue('.$key.',2,'.$line[11].');';
						$sChartData.='data.setValue('.$key.',3,'.$line[12].');';
						//$sChartData.='data.setValue('.$key.',3,'.$aSDC['powermeta']['inuse'][$key].');';
						$sChartData.='data.setValue('.$key.',4,'.$line[13].');';
						
						
						$sChartData2.='data2.setValue('.$key.',0,"B'.$key.'");';
						$sChartData2.='data2.setValue('.$key.',1,'.$line[28].');';
						$sChartData2.='data2.setValue('.$key.',2,'.$line[29].');';
						$sChartData2.='data2.setValue('.$key.',3,'.$line[30].');';
						$sChartData2.='data2.setValue('.$key.',4,'.$line[31].');';
					}
					?>
					
<script type="text/javascript" src="http://www.google.com/jsapi"></script>
<script type="text/javascript">
	google.load("visualization", "1", {packages:["corechart"]});
	google.setOnLoadCallback(drawChart);
	function drawChart() {
		var data = new google.visualization.DataTable();
		data.addColumn('string', 'Blade');
		data.addColumn('number', 'WATTS_REP');
		data.addColumn('number', 'ACT_W');
		//data.addColumn('number', 'usage');
		data.addColumn('number', 'PRESET');
		data.addColumn('number', 'MAXth');
		data.addRows(<?php echo count($aSDC['powermeta']['powerstats']); ?>);
		
		<?php echo $sChartData; ?>
		
		var chart = new google.visualization.ColumnChart(document.getElementById('chart_div'));
		chart.draw(data, {width: 900, height: 300, title: 'Power Usage Details',
			hAxis: {title: 'Bay', titleColor:'black'},
			backgroundColor: '#eee',
			vAxis: {minValue: 0}
		});
		
		
		var data2 = new google.visualization.DataTable();
		data2.addColumn('string', 'Blade');
		data2.addColumn('number', 'PM1');
		data2.addColumn('number', 'PM 2');
		data2.addColumn('number', 'PM 3');
		data2.addColumn('number', 'PM 4');
		data2.addRows(<?php echo count($aSDC['powermeta']['powerstats']); ?>);
		
		<?php echo $sChartData2; ?>
		
		var chart2 = new google.visualization.ColumnChart(document.getElementById('chart_div2'));
		chart2.draw(data2, {width: 900, height: 200, title: 'Power Module Draw',
			hAxis: {title: 'Bay', titleColor:'black'},
			backgroundColor: '#eee',
			vAxis: {minValue: 0}
		});
	}
    </script>
    <div id="chart_div"></div>
    <div id="chart_div2"></div>

<?php
				/*
					echo '<table id="powerstatstable" class="tablesorter">';
					foreach ($aSDC['powermeta']['powerstats'] as $key => $line) {
						if ($key<1) echo '<thead>';
						echo '<tr>';
						($key<1)?$cell='th':$cell='td';
						foreach ($line as $col => $value) {
							if ((($col>=14) && ($col <=27)) || ($col>=33)) continue;
							echo '<'.$cell.'>'.$value.'</'.$cell.'>';
						}
						echo '</tr>';
						if ($key<1) echo '</thead>';
					}
					echo '</table></div>';
					
					echo '<a name="extrapowerdetails"></a>
							<div id="extrapowerdetails" class="outputbox">
							<h3></h3>
							<h2>Extra Power Stats</h2>';
					echo '<table id="extrapowerstatstable" class="tablesorter">';
					foreach ($aSDC['powermeta']['extrapowerstats'] as $key => $line) {
						if ($key<1) echo '<thead>';
						echo '<tr>';
						($key<1)?$cell='th':$cell='td';
						foreach ($line as $value) { echo '<'.$cell.'>'.$value.'</'.$cell.'>'; }
						echo '</tr>';
						if ($key<1) echo '</thead>';
					}
					echo '</table></div>';
					*/
					echo '</div>';
				}
			?>
				</div>
				<div id="maptab" class="tab_content">
			<?php
			
			// map section
			echo '<a name="map"></a>
					<div id="map" class="outputbox">
					<h2>Map</h2>';
			fTimer('map');
			fDrawMap($aLogfileIndex);
			fTimer('map',1);
			echo '</div>';

			?>
				</div>
				<div id="eventlogtab" class="tab_content">
			<?php
			
			// eventlog section
			echo '<a name="evtlog"></a>
					<div id="evtlog" class="outputbox">';
			//echo fEvtFilterBoxes($aSDC['evt']);
			echo '<h2>Event Log</h2>';
			fTimer('evtlog');
			echo fEvtlog($aSDC['evt']);
			fTimer('evtlog',1);
			echo '</div>';
			?>
				</div>
				
			<?php
			
			if ($aSDC['icpmtest']) { 
				echo '<div id="icpmtab" class="tab_content">';
				// ICPMTEST
				echo '<a name="icpmtest"></a>
						<div id="icpmtest" class="outputbox">';
				echo fShowICPMData();

				// these are the debug output lines
				// echo '<pre>';
				// print_r($aSDC['icpmtest']);
				// end debug output lines
				
				echo '</div>';
				echo '</div>';
			}
			
			?>
				
			<?php		
			
				if (is_dir($upload_dir.$_SERVER['QUERY_STRING']) && is_file($upload_dir.$_SERVER['QUERY_STRING'].'/primary_ffdc/vdbg.txt')) {
				?>
				<div id="vdbgtab" class="tab_content">
			<?php
			
			// vdbg
			echo '<a name="vdbg"></a>
					<div id="vdbg" class="outputbox">';
			echo '<h2>vdbg</h2>';
			
			$vdbgfile=file($upload_dir.$_SERVER['QUERY_STRING'].'/primary_ffdc/vdbg.txt');
			echo fParseVDBG($vdbgfile);
			
			echo '</div>';
			?>
				</div>
			
			<?php } 
			
				if (is_dir($upload_dir.$_SERVER['QUERY_STRING'])) {
				?>
				<div id="filelisttab" class="tab_content">
			<?php
			
			// file explorer
			echo '<a name="filelist"></a>
					<div id="filelist" class="outputbox">';
			echo fBuildFileList($_SERVER['QUERY_STRING']);
			echo '</div>';
			?>
				</div>
			
			<?php } ?>
			<?php } // shortmode ?>
			
				<div id="abouttab" class="tab_content">
					<a name="aboutsection"></a>
					<div id="aboutsection" class="outputbox">
						<h2>About the Annihilator</h2>
						<pre><?php include('./README');?></pre>
					</div>
				</div>
				
			<?php if ($debug){ ?>
				<div id="debugtab" class="tab_content">
			<?php
			
			// debug section
			echo '<a name="ignorethis"></a>
					<div id="ignorethis2" class="outputbox">';
			echo '<h2>Debug</h2><pre>';
			
				// report the timers
				fTimer('total',1);
				foreach ($timers as $key => $value) {
					if ($key=='total') $echostring[]=$key.': '.$value[round].' s';
					else $echostring[]=$key.': '.round(($value[total]/$timers[total][total])*100,1).'%';
				}
				echo implode(", ",$echostring);
			
				echo '<hr />';
			
				// get rid of a couple massive arrays and then dump everything else; very verbose
				unset($aLogfile); // service.txt read into an array
				unset($aDevfile); // developer.txt read into an array
				unset($vdbgfile); // vdbg.txt read into an array
				unset($aSDC['evt']); // event log parsed and enumerated; this isn't actually raw data and may be worthwhile for troubleshooting
				echo print_r(get_defined_vars(),TRUE);
			
			echo '</pre></div>';
			?>
				</div>
				<?php } ?>
			</div>
		</div>
		<?php
		} else {
			// map didn't work; wrong file, empty file?
			echo '<!-- FINAL ERROR -->';
			fBuildError();
		}
		fBuildFooter();
	}
}
?>