<?php

// This function returns JSON responses
header('Content-Type: application/json');

// Only allow requests from the www domain
header('Access-Control-Allow-Origin: https://www.hexr.org');
header('Access-Control-Allow-Headers: Content-Type');

// OPTIONS requests are used for preflight; there will be no body
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
	http_response_code(204);
	die();
}

// Because this is a JSON API, we need to parse the input as JSON
$_POST = json_decode(file_get_contents('php://input'), true);

if (!$_POST || json_last_error()) {
	http_response_code(400);
	die('{"errors": ["Unable to process input data"]}');
}

if(!isset($_POST['submitted'])) {
	die('{"errors":["No submission detected"]}');
}

$config = require('config.php');
/* Begin Function definitions */

/*
 * @name: clean
 * @description: trim and clean up an index of an array
 * @param $arr - the array which contains an element
 * @param $idx - the index of $arr to clean
 * @param $filter - filter to use in `filter_var`. Defaults to STRING
 * @note: the reason this modifies an array index is to make getCleanData a lot more readable
 * @returns: if the data was clean or not
*/
function clean(Array $arr, String $idx, $filter = FILTER_SANITIZE_STRING) : bool{
	$data = trim($arr[$idx]);
	if (filter_var($data, $filter)) {
		$arr[$idx] = filter_var($data, $filter);
		return true;
	}

	return false;
}

/*
 * @name: stripNewLines
 * @description: remove new line characters from an index of an array
 * @param $arr - the array which contains an element
 * @param $idx - the index of $arr to strip
 * @note: the reason this modifies an array index is to make getCleanData a lot more readable
 * @returns: void
*/
function stripNewLines(Array $arr, String $idx) {
	$arr[$idx] = preg_replace('(\r|\n)', '', $arr[$idx]);
}

/*
 * @name: getCleanData
 * @description: validates and sanitizes post data for use in the email
 * @returns: associative array of processed post data
*/
function getCleanData() : Array {
	$errors = [];
	// Suppress index not defined warnings because we're validating in bulk
	$vars = @array(
		'captcha' => $_POST['g-recaptcha-response'],
		'firstName' => $_POST['first_name'],
		'lastName' => $_POST['last_name'],
		'emailAddress' => $_POST['email'],
		'phoneNumber' => $_POST['phone'],
		'organization' => $_POST['organization'],
		'about' => $_POST['org-desc'],
		'requirements' => $_POST['org-web-requirements'],
		'employees' => $_POST['num-employees'],
		'income' => $_POST['income'],
	);

	$filterSettings = array(
		'captcha' => FILTER_SANITIZE_STRING,
		'firstName' => FILTER_SANITIZE_STRING,
		'lastName' => FILTER_SANITIZE_STRING,
		'emailAddress' => FILTER_SANITIZE_EMAIL,
		'phoneNumber' => FILTER_SANITIZE_NUMBER_INT,
		'organization' => FILTER_SANITIZE_STRING,
		'about' => FILTER_SANITIZE_STRING,
		'requirements' => FILTER_SANITIZE_STRING,
		'employees' => FILTER_SANITIZE_NUMBER_INT,
		'income' => FILTER_SANITIZE_NUMBER_FLOAT,
	);

	stripNewLines($vars, 'firstName');
	stripNewLines($vars, 'lastName');
	stripNewLines($vars, 'organization');

	foreach ($vars as $var=>$resp) {
		if (empty($resp) || !clean($vars, $var, $filterSettings[$var])) {
			array_push($errors, ucfirst($var).' is not valid');
		}
	}

	if (count($errors) > 0) {
		die(json_encode(array('errors' => $errors)));
	}

	// Website is optional
	$vars['website'] = $_POST['website'] ?? '';
	clean($vars, 'website', FILTER_SANITIZE_ENCODED);

	$vars['forProfit'] = (Boolean) $_POST['profit'] ? 'For Profit' : 'Not for profit';
	$vars['ip'] = $_SERVER['REMOTE_ADDR'];

	return $vars;
}

/*
 * @name: sendEmail
 * @description: uses the MailGun API to send an email based on $fields
 * @param: $fields - a list of fields to be sent to the API, documented here:
 *       https://sendgrid.com/docs/API_Reference/Web_API_v3/Mail/index.html
 * @returns: response from API and status Code
*/
function sendEmail(Array &$fields) : Array {
	global $config;
	$instance = curl_init();
	curl_setopt_array($instance, array(
		CURLOPT_URL => 'https://api.sendgrid.com/v3/mail/send',
		CURLOPT_HTTPHEADER => array(
			'authorization: Bearer '.$config['sendgrid_key'],
			'content-type: application/json'
		),
		CURLOPT_CUSTOMREQUEST => 'POST',
		CURLOPT_RETURNTRANSFER => 1,
		CURLOPT_POSTFIELDS => json_encode($fields),
	));
	$data = curl_exec($instance);
	$code = curl_getinfo($instance, CURLINFO_HTTP_CODE);
	curl_close($instance);

	return array(
		'code' => $code,
		'data' => $data
	);
}

/*
 * @name: isCaptchaValid
 * @description: determines if a visitor is probably a robot using Google's ReCaptcha API
 * @param: $response - the g-recaptcha-response field made in a post request
 * @returns: $isValid - if you can assume the request is legit
*/
function isCaptchaValid(String $response) : bool {
	global $config;
	/* First Google Captcha */
	$options = array(
		// use key 'http' even if you send the request to https://...
		'http' => array(
			'header' => "Content-type: application/x-www-form-urlencoded\r\n",
			'method' => 'POST',
			'content' => http_build_query(array(
				// @note: this secret is not valid and will be removed!
				'secret' => $config['captcha_key'],
				'remoteip' => $_SERVER['REMOTE_ADDR'],
				'response' => $response
			)),
		)
	);

	$context  = stream_context_create($options);
	$result = file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $context);
	$recaptchaResult = json_decode($result, true);

	return (Boolean) $recaptchaResult['success'];
}

/* End function definitions */

$data = getCleanData();

if (!isCaptchaValid($data['captcha'])) {
	die('{"errors":["You were deemed a robot"]}');
}

$body = "Hello!

Thank you for requesting a website from HexR. This is confirmation that we received your request. If you didn't ask for this website, please contact us immediately so we can remove you from our communication mechanisms.

Here's the information we received:
<strong>First name</strong>: ".$data['firstName']."
<strong>Last name</strong>: ".$data['lastName']."
<strong>Email Address</strong>: ".$data['emailAddress']."
<strong>Phone Number</strong>: ".$data['phoneNumber']."
<strong>Organization</strong>: ".$data['organization']."
<strong>Current Site</strong>: ".$data['website']."
<strong>Organization Description</strong>: ".$data['about']."
<strong>Site Requirements</strong>: ".$data['requirements']."
<strong>Number Employees</strong>: ".$data['employees']."
<strong>Income</strong>: ".$data['income']."
<strong>Type of Organization</strong>: ".$data['forProfit']."
<strong>Requesting IP</strong>: ".$data['ip']."

===This email was sent by a robot. Please contact <a href='mailto:support@hexr.org'>support@hexr.org</a> for any issues.===";

$body = str_replace("\n", "<br/>\n", $body);
$emailAddress = $data['emailAddress'];
$emailFields = array(
	'personalizations' => [array(
		'to' => [array(
			'email' => $emailAddress,
			'name' => $data['firstName']. ' '. $data['lastName']
		)],
		'cc' => [array(
			'email' => 'request@hexr.org',
			'name' => 'HexR Request Management'
		)],
		'subject' => 'Thanks for Requesting a website!',
	)],
	'from' => array(
		'email' => 'request@robots.hexr.org',
		'name' => 'HexR Request Robot'
	),
	'reply_to' => array(
		'email' => 'request@hexr.org',
		'name' => 'HexR Request Management'
	),
	'content' => [
		array(
			'type' => 'text/plain',
			'value' => strip_tags($body)
		),
		array(
			'type' => 'text/html',
			'value' => $body
		)
	]
);

$sentEmailResponse = sendEmail($emailFields);

if ($sentEmailResponse['code'] !== 202) {
	die('{"errors":["Message failed to send. Please contact us so we can manually create the request."], "code": "E_NOT_200"}');
}

die('{"errors":[]}');

?>
