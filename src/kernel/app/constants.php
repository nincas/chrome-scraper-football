<?php

// Defined Constants
$const = [
	'OPTIONS' 	  => getOptions(),
	'TIMEOUT_SEC' => (int) getenv('TIMEOUT'),
	'FILE_PATH'   => dirname(dirname(__DIR__)) . getenv('FILES_PATH'),
	'PARAM_LIMIT' => getenv('PARAMETER_LIMIT'),
	'KILL_CHROME' => getenv('KILL_CHROME'),
	'DS'		  => DIRECTORY_SEPARATOR
];

define_const($const);