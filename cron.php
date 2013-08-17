<?php

// SONA login info
$username		  = "notrealuser";
$password		  = "notrealpw";


// Config
$base_url     = "https://example.sona-systems.com/";
$url_login    = $base_url."default.aspx";
$url_to_pull  = $base_url."all_exp.aspx";
$emails_file  = "emails.txt";
$cookie_file  = "cookies.txt";
$src_file     = "all_exp.aspx";
$timezone     = "America/Toronto";
$max_attempts = 3;


// Debug options [default value]
// Refresh studies from SONA system [true]
$refresh = true;
//$refresh = false;

// Log src of each check [false]
$log_page_src	= false;
//$log_page_src	= true;

// Ignore study availability and create notifications anyway [false]
$force_notify	= false;
//$force_notify	= true;

// Output email contents [false]
$show_email		= false;
//$show_email		= true;

// Send notifications [true]
$send_email		= true;
//$send_email		= false;

// Send notificaitons to others [true]
$send_to_others	= true;
//$send_to_others	= false;


// Notificaiton Email settings
$from			= "Sona Notifier <sona-notifier@example.com>";
$to				= "";
$bcc			= "";
if ($send_to_others) {
	include 'emails.php';
	$bcc = $emails;
}
$bcc			.= "recipient@example.com";
$subject		= "Updated: Sona System";
$footer			= "<p>This footer will be appended to the body of the email, after this list of studies.</p>";


// importSrcIntoArray: imports the studies from a page source
function importSrcIntoArray(&$studies, &$study_rows, $page_source)
{
	// Remove newlines for preg match
	$page_source = str_replace("\n", "", $page_source);

	// Each study is a table row
	$study_pattern = '/<tr id="ctl00_ContentPlaceHolder1_repStudentStudies_ctl.*_RepeaterRow" class="colorWHITE">.*<\/tr>/U';
	preg_match_all ($study_pattern, $page_source, $study_rows, PREG_SET_ORDER);

	foreach ($study_rows as $key => $value)
	{
		// Get availability table cell
		$availability_pattern = '/<td class="normCENTER">.*<\/td>/U';
		$availability_matches = array();
		preg_match($availability_pattern, $value[0], $availability_matches);
		$availability = "No";
		if (strpos($availability_matches[0], "Timeslots Available") > 0) {
			$availability = "Timeslots Available";
		}

		// experiment_id is unique for each study - use as key
		$id_pattern = '/"exp_info.aspx\?experiment_id=[0-9]*"/U';
		$id_matches = array();
		preg_match($id_pattern, $value[0], $id_matches);
		$id = $id_matches[0][29].$id_matches[0][30].$id_matches[0][31];

		// Add to old_studies array
		$studies[$id] = $availability;
	}
}

// regexExtract: return the nthValue occurence of a regex in text
function regexExtract($text, $regex, $regs, $nthValue)
{
	if (preg_match($regex, $text, $regs)) $result = $regs[$nthValue];
	else $result = "";
	return $result;
}


// Begin log
header('Content-type: text/plain');
date_default_timezone_set($timezone);
echo date("m/d H:i");
echo "\t";


// Import old studies list
$old_studies = array();
$study_rows = array();
importSrcIntoArray($old_studies, $study_rows, file_get_contents($src_file));

// If not connecting, just use the old array
$new_studies = $old_studies;

// Connect to SONA system
if ($refresh)
{
	// Retry $max_attempts times
	for ($i=0; $i < $max_attempts; $i++)
	{
		$ch = curl_init();

		//first cURL to get __VIEWSTATE and __EVENTVALIDATION
		$regexViewstate	= '/__VIEWSTATE\" value=\"(.*)\"/i';
		$regexEventVal	= '/__EVENTVALIDATION\" value=\"(.*)\"/i';

		curl_setopt($ch, CURLOPT_URL, $url_login);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
		$data = curl_exec($ch);

		//second cURL to get cookie
		$viewstate = regexExtract($data,$regexViewstate,$regs,1);
		$eventval = regexExtract($data, $regexEventVal,$regs,1);

		$postData = '__VIEWSTATE='.rawurlencode($viewstate)
				  .'&__EVENTVALIDATION='.rawurlencode($eventval)
				  .'&'.rawurlencode('ctl00$ContentPlaceHolder1$userid').'='.$username
				  .'&'.rawurlencode('ctl00$ContentPlaceHolder1$pw').'='.$password
				  .'&'.rawurlencode('ctl00$ContentPlaceHolder1$default_auth_button').'='.'Log In'
				  ;

		curl_setOpt($ch, CURLOPT_POST, TRUE);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
		curl_setopt($ch, CURLOPT_URL, $url_login);
		curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
		curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);     
		$data = curl_exec($ch);

		//third cURL to get DATA about studies
		curl_setOpt($ch, CURLOPT_POST, FALSE);
		curl_setopt($ch, CURLOPT_URL, $url_to_pull);
		curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
		$page_source = curl_exec($ch);

		curl_close($ch);

		// Check to make sure it's not the login page
		if (strlen($page_source) > 10000)
		{
			// Write to file
			$handle = fopen($src_file, 'w');
			fwrite($handle, $page_source);

			// Log prev files
			if ($log_page_src)
			{
				$handle = fopen($src_file.".".date("U"), 'w');
				fwrite($handle, $page_source);
			}

			// Process new studies list
			$new_studies = array();
			$study_rows = array();
			importSrcIntoArray($new_studies, $study_rows, $page_source);
			
			break;
		}

		// Give up after $max_attempts connects to SONA system
		else if ($i == ($max_attempts-1))
		{
			echo "Unable to retrieve page after ".($i+1)." tries\n";
			exit(1);
		}
	}
}


// Compare - notify if study has timeslots available now and not at the previous check
$notify = $force_notify;
foreach ($new_studies as $id => $value)
{
	if ($value == "Timeslots Available" && $old_studies[$id] != "Timeslots Available")
	{
		$notify = true;
	}
}


// Notify if necessary
if ($notify)
{
	echo "About to notify... ";

	$message = "<html><body><table>\n";
	
	// Make links absolute
	foreach ($study_rows as $key => $value)
	{
		$row = preg_replace("/href=\"/", "href=\"".$base_url, $value[0]);
		$message .= $row;
		$message .= "\n";
	}
	
	$message .= "</table>\n\n";

	// Insert footer
	$message .= $footer;

	$message .="\n\n</body></html>";


	// Headers - To send HTML mail, the Content-type header must be set
	$headers  = 'MIME-Version: 1.0' . "\r\n";
	$headers .= 'Content-type: text/html; charset=iso-8859-1' . "\r\n";
	$headers .= 'From: ' . $from . "\r\n";
	$headers .= 'Bcc: ' . $bcc . "\r\n";

	if ($show_email)
	{
		echo "\n--- Email Below ---\n";
		echo $headers;
		echo "To: " . $to . "\r\n";
		echo "Subject: " . $subject . "\r\n";

		echo "\n";
		echo $message;
		echo "\n--- Email Above ---\n";
	}

	if ($send_email) {
		if (mail($to, $subject, $message, $headers)) echo "Success";
		else echo "Failed";
	}
	else echo "Email disabled";
}
else echo "No changes";

echo "\n";
