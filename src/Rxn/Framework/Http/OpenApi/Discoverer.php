<?php declare(strict_types=1);

namespace Rxn\Framework\Http\OpenApi;

/**
 * Resolves Rxn convention controller classes under a given
 * `<appRoot>/app/Http/Controller` tree. Separated from Generator so
 * tests can drive the generator with a fixed class list and apps can
 * choose either entry point.
 *
 *     $classes = (new Discoverer('/srv/app', 'App'))->all();
 *     $spec    = (new Generator(info: [...], controllers: $classes))->generate();
 */
final class Discoverer
{
    /**
     * @param bool $requireFiles require_once each discovered file so
     *        the class becomes reflectable even outside a composer
     *        autoloader. CLI sets this to true; library callers that
     *        already autoload their app leave it false.
     */
    public function __construct(
        private string $appRoot,
        private string $namespacePrefix,
        private bool $requireFiles = false
    ) {}

    /** @return list<class-string> */
    public function all(): array
    {
        $base = rtrim($this->appRoot, '/') . '/app/Http/Controller';
        if (!is_dir($base)) {
            return [];
        }
        $out  = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iter as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = ltrim(substr($file->getPathname(), strlen($base)), '/');
            if (!str_ends_with($relative, 'Controller.php')) {
                continue;
            }
            $class = rtrim($this->namespacePrefix, '\\')
                . '\\Http\\Controller\\'
                . str_replace('/', '\\', substr($relative, 0, -strlen('.php')));
            if ($this->requireFiles) {
                require_once $file->getPathname();
            }
            $out[] = $class;
        }
        sort($out);
        return $out;
    }
}
