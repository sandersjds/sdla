$(function() {
	// makes the collapses work (map tab, unhandled sections)
	$(".nodehead").click(function(event) {
		event.preventDefault();
		$('+ .node', this).slideToggle();
	});

	$(".collapsor").click(function(event) {
		event.preventDefault();
		$('+ .collapsee', this).slideToggle();
	});
	
	
	// makes the summary "extended data" switcher work
	$("#extender").click(function(event) {
		event.preventDefault();
		$('.normaldata').toggle();
		$('.extendeddata').toggle();
	});

	// both of these are loaded back in by ajax and have to be applied when they're reloaded; they're just extra here
	//$("a.filelist").click(function(event) { event.preventDefault(); });
	//$("#filelistreturn").click(function(event) { event.preventDefault(); });
	
	
	
	
	// filter reset button
	/*
	$("#filterreset").click(function(event) {
		event.preventDefault();
		$(".filterbox option:selected").removeAttr("selected");
		$(".tablesorter tr.datarow").show();
		$("tr:visible").filter(':even').removeClass("odd").addClass("even").end().filter(':odd').removeClass("even").addClass("odd");
		filterlist='';
	});
	*/
});

$(document).ready(function() {
	$("#collapse_1").show();
	
	// tabs
	$(".tab_content").hide(); //Hide all content
	$("ul.tabs li:first").addClass("active").show(); //Activate first tab
	$(".tab_content:first").show(); //Show first tab content

	$("ul.tabs li").click(function() {

		$("ul.tabs li").removeClass("active"); //Remove any "active" class
		$(this).addClass("active"); //Add "active" class to selected tab
		$(".tab_content").hide(); //Hide all tab content

		var activeTab = $(this).find("a").attr("href"); //Find the href attribute value to identify the active tab + content
		//$(activeTab).fadeIn(); //Fade in the active ID content
		$(activeTab).show();
		return false;
	});
	
	// ajax junk for eventlogs
	/*$('#evtlogtab').click(function(event){
		if(!$(this).data('cache')) {
			$('#eventlogtab').html('<img src="assets/ajax_preloader.gif" width="64" height="64" class="preloader" />');

 			$.get('./?ajax=evtlog&file='+window.location.search.substring(1),function(msg){
				$('#eventlogtab').html(msg);

				$(this).data('cache',msg);
			});
		}
		else $('#eventlogtab').html($(this).data('cache'));
 		event.preventDefault();
	});
	*/
});














/* TABLESORTING */


(function($) {
/*
 * Function: fnGetColumnData
 * Purpose:  Return an array of table values from a particular column.
 * Returns:  array string: 1d data array 
 * Inputs:   object:oSettings - dataTable settings object. This is always the last argument past to the function
 *           int:iColumn - the id of the column to extract the data from
 *           bool:bUnique - optional - if set to false duplicated values are not filtered out
 *           bool:bFiltered - optional - if set to false all the table data is used (not only the filtered)
 *           bool:bIgnoreEmpty - optional - if set to false empty values are not filtered from the result array
 * Author:   Benedikt Forchhammer <b.forchhammer /AT\ mind2.de>
 */
$.fn.dataTableExt.oApi.fnGetColumnData = function ( oSettings, iColumn, bUnique, bFiltered, bIgnoreEmpty ) {
	// check that we have a column id
	if ( typeof iColumn == "undefined" ) return new Array();
	
	// by default we only wany unique data
	if ( typeof bUnique == "undefined" ) bUnique = true;
	
	// by default we do want to only look at filtered data
	if ( typeof bFiltered == "undefined" ) bFiltered = true;
	
	// by default we do not wany to include empty values
	if ( typeof bIgnoreEmpty == "undefined" ) bIgnoreEmpty = true;
	
	// list of rows which we're going to loop through
	var aiRows;
	
	// use only filtered rows
	if (bFiltered == true) aiRows = oSettings.aiDisplay; 
	// use all rows
	else aiRows = oSettings.aiDisplayMaster; // all row numbers

	// set up data array	
	var asResultData = new Array();
	
	for (var i=0,c=aiRows.length; i<c; i++) {
		iRow = aiRows[i];
		var aData = this.fnGetData(iRow);
		var sValue = aData[iColumn];
		
		// ignore empty values?
		if (bIgnoreEmpty == true && sValue.length == 0) continue;

		// ignore unique values?
		else if (bUnique == true && jQuery.inArray(sValue, asResultData) > -1) continue;
		
		// else push the value onto the result data array
		else asResultData.push(sValue);
	}
	
	return asResultData;
}}(jQuery));


function fnCreateSelect(aData,sVal) {
	var r='<select><option value="">'+sVal+'</option>', i, iLen=aData.length;
	for ( i=0 ; i<iLen ; i++ ) { r += '<option value="'+aData[i]+'">'+aData[i]+'</option>'; }
	return r+'</select>';
}


$(document).ready(function() {
	/* Initialise the DataTable */
	var oTable = $('#eventlogtable').dataTable( {
		"bPaginate": false,
		//"iDisplayLength": 100,
		"oLanguage": {
			"sSearch": "Search all columns:"
		},
		"aoColumns": [
			null,
			null,
			null,
			null,
			{ "sType": "html" },
			null
		],
		"sDom": '<"top"iflp>rt<"bottom"<"clear">'
	} );
	
	/* Add a select menu for each TH element in the table footer */
	/*$("tfoot th").each( function ( i ) {
		if (i==0) return true;
		this.innerHTML = fnCreateSelect( oTable.fnGetColumnData(i) );
		$('select', this).change( function () {
			oTable.fnFilter( $(this).val(), i );
		} );
	} );*/
	
	// let's add the filters someplace else!
	
	$("#sort_sev").html(fnCreateSelect(oTable.fnGetColumnData(1),'(sev)'));
	$('#sort_sev select').change( function (){
		oTable.fnFilter($(this).val(),1);
	});
	
	$("#sort_source").html(fnCreateSelect(oTable.fnGetColumnData(2).sort(),'(source)'));
	$('#sort_source select').change(function (){
		oTable.fnFilter($(this).val(),2);
	});
	
	var oTableVDBG = $('#vdbgtable').dataTable( {
		"bPaginate": false,
		"aaSorting": [[ 0, "desc" ]],
		//"aaSorting": [], // passing this prevents initial sorting
		//"iDisplayLength": 100,
		"oLanguage": {
			"sSearch": "Search all columns:"
		},
		"sDom": '<"top"iflp>rt<"bottom"<"clear">'
	} );
	
	var oTableSOL = $('#solstatustable').dataTable( {
		"bPaginate": false,
		//"oLanguage": {
		//	"sSearch": "Search all columns:"
		//},
		"bSort": false,
		"sDom": '<"top">rt<"bottom"<"clear">'
	} );
	
	var oTableScale = $('#scaledetailstable').dataTable( {
		"bPaginate": false,
		//"oLanguage": {
		//	"sSearch": "Search all columns:"
		//},
		"bSort": false,
		"sDom": '<"top">rt<"bottom"<"clear">'
	} );
	
	
	var oTablePWRStats = $('#powerstatstable').dataTable( {
		"bPaginate": false,
		//"oLanguage": {
		//	"sSearch": "Search all columns:"
		//},
		"bSort": false,
		"sDom": '<"top">rt<"bottom"<"clear">'
	} );
	
	var oTableExtraPWRStats = $('#extrapowerstatstable').dataTable( {
		"bPaginate": false,
		//"oLanguage": {
		//	"sSearch": "Search all columns:"
		//},
		"bSort": false,
		"sDom": '<"top">rt<"bottom"<"clear">'
	} );
	
	var oTableExtraPWRStats = $('#pwrdetails').dataTable( {
		"bPaginate": false,
		//"oLanguage": {
		//	"sSearch": "Search all columns:"
		//},
		"bSort": false,
		"sDom": '<"top">rt<"bottom"<"clear">'
	} );
});
