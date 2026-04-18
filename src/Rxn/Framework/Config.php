<?php declare(strict_types=1);

namespace Rxn\Framework;

/**
 * Concrete default configuration. Apps can subclass this to override
 * individual fields, or replace it outright by binding an alternative
 * into the container under Rxn\Framework\Config::class.
 *
 * All framework-level defaults live on BaseConfig; this class just
 * fills in the pieces that depend on runtime environment (product
 * namespace, file-cache directory).
 */
class Config extends BaseConfig
{
    /**
     * PSR-4 namespace for the application on top of the framework.
     * Resolved from the APP_NAMESPACE env var (validated at boot by
     * Startup::setEnv), so `{product_namespace}\Http\Controller\v1\Foo`
     * is a valid class lookup.
     *
     * @var string
     */
    public $product_namespace;

    /**
     * Absolute directory where Filecache stores serialized objects.
     *
     * @var string
     */
    public $fileCacheDirectory;

    public function __construct()
    {
        parent::__construct();

        $namespace = getenv('APP_NAMESPACE');
        $this->product_namespace = is_string($namespace) ? rtrim($namespace, '\\') : '';

        $this->fileCacheDirectory = defined(__NAMESPACE__ . '\\APP_ROOT')
            ? constant(__NAMESPACE__ . '\\APP_ROOT') . 'filecache'
            : sys_get_temp_dir() . '/rxn-filecache';
    }
}
