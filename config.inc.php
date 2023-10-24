<?php
// enter your Wistia and Vimeo settings and preferences
$config = [
	'wistia' => [
		'api_token' => 'WISTIA_API_TOKEN_HERE',
		'file_count' => 8300,
	],
	'vimeo' => [
		'php_sdk_path' => dirname(__FILE__).'/vimeo.php/autoload.php',
		'client_id' => 'VIMEO_CLIENT_ID_HERE',
		'client_secret' => 'VIMEO_CLIENT_SECRET_HERE', 
		'access_token' => 'VIMEO_ACCESS_TOKEN_HERE',
		'privacy' => [
			'view' => 'unlisted', 
			'embed' => 'whitelist',
		],
		'embed_domains' => [
			'your-domain-name-here.test',
		],
	],
];
