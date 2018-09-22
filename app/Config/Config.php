<?php

namespace Organization\Product\Config;

use Rxn\Framework\BaseConfig;

final class Config extends BaseConfig
{
    /**
     * Defines the organization/product namespace for your application's models and controllers
     *
     * Default value: '\\Organization\\Product';
     *
     * @var string
     */
    public $product_namespace = '\\Organization\\Product';

    /**
     * Uncomment this below to define an application key for encryption
     */
    // public $applicationKey = "application key goes here";

    /**
     * Enable or disable file caching of objects
     *
     * Default value: false
     *
     * @var bool
     */
    public $use_file_caching = false;

    /**
     * Enable or disable query caching of queries
     *
     * Default value: false
     *
     * @var bool
     */
    public $use_query_caching = false;

    /**
     * enable or disable IO sanitization to help stop XSS attacks
     *
     * Default value: false
     *
     * @var bool
     */
    public $use_input_output_sanitization = true;

    /**
     * Use a valid \DateTime timezone
     *
     * Default value: 'America/Denver'
     *
     * @var string
     */
    public $timezone = 'America/Denver';
}
