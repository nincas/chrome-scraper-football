<?php

# Defined Constants
$const = [
	'OPTIONS' 	   => getOptions(),
	'TIMEOUT_SEC'  => (int) getenv('TIMEOUT'),
	'FILE_PATH'    => dirname(dirname(dirname(__FILE__))) . getenv('FILES_PATH'),
	'PARAM_LIMIT'  => getenv('PARAMETER_LIMIT'),
	'KILL_CHROME'  => getenv('KILL_CHROME'),
	'BASE_NS'	   => 'Scraper\\Build\\Controller\\',
	'NL'		   => PHP_EOL,
	'ABSPATH'	   => dirname(dirname(dirname(__FILE__))),
	'ERR_LEVELS'   => [
        0 => 'Warning: ',
        1 => 'Error: ',
        2 => 'Fatal: '
    ]
];

define_const($const);