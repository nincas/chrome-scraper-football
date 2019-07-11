<?php

// Defined Constants
$const = [
	'OPTIONS' 	  => getOptions(),
	'TIMEOUT_SEC' => (int) getenv('TIMEOUT'),
	'FILE_PATH'   => dirname(dirname(dirname(__FILE__))) . getenv('FILES_PATH'),
	'PARAM_LIMIT' => getenv('PARAMETER_LIMIT'),
	'KILL_CHROME' => getenv('KILL_CHROME'),
	'BASE_NS'	  => 'Scraper\\Build\\Controller\\',
	'NL'		  => PHP_EOL,
	'ABSPATH'	  => dirname(dirname(dirname(__FILE__)))
];

define_const($const);