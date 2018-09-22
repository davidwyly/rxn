<?php

/**
 * Bootstrap file for RXN Application
 */

define('Rxn\\Framework\\ENVIRONMENT', getenv('Rxn\\Framework\\ENVIRONMENT'));
define('Rxn\\Framework\\APP_NAMESPACE', getenv('Rxn\\Framework\\APP_NAMESPACE'));
define('Rxn\\Framework\\APP_TIMEZONE', getenv('Rxn\\Framework\\APP_TIMEZONE'));
define('Rxn\\Framework\\APP_MULTIBYTE', getenv('Rxn\\Framework\\APP_MULTIBYTE'));

define('Rxn\\Framework\\TEST_OAUTH_CONSUMER_KEY', getenv('Rxn\\Framework\\TEST_OAUTH_CONSUMER_KEY'));
define('Rxn\\Framework\\TEST_OAUTH_CONSUMER_SECRET', getenv('Rxn\\Framework\\TEST_OAUTH_CONSUMER_SECRET'));
define('Rxn\\Framework\\TEST_OAUTH_TOKEN_KEY', getenv('Rxn\\Framework\\TEST_OAUTH_TOKEN_KEY'));
define('Rxn\\Framework\\TEST_OAUTH_TOKEN_SECRET', getenv('Rxn\\Framework\\TEST_OAUTH_TOKEN_SECRET'));
