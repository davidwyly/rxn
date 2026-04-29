<?php declare(strict_types=1);

namespace Rxn\Framework\Tests\Http {

    use PHPUnit\Framework\TestCase;
    use Rxn\Framework\Container;
    use Rxn\Framework\Http\CrudController;

    /**
     * CrudController guesses the record class as
     * `{product_namespace}\Model\{ShortName}` and refuses to instantiate
     * if that class isn't autoloadable. The test subclasses each
     * publish a `create_v1` method so the parent Controller constructor
     * (which validates `{action_name}_{action_version}` exists) clears.
     */
    final class CrudControllerTest extends TestCase
    {
        public function testGuessRecordClassDerivesNamespacedModelName(): void
        {
            $config = new \Rxn\Framework\Config();
            $config->product_namespace = 'CrudFixture';

            $crud = $this->buildCrud(CrudControllerSampleProduct::class, $config);

            $this->assertSame(
                'CrudFixture\\Model\\CrudControllerSampleProduct',
                $crud->record_class
            );
        }

        public function testMissingModelClassRaises(): void
        {
            $config = new \Rxn\Framework\Config();
            $config->product_namespace = 'NoSuchNamespaceForCrud';

            $this->expectException(\Exception::class);
            $this->expectExceptionMessage('references missing model');

            $this->buildCrud(CrudControllerSampleProduct::class, $config);
        }

        public function testInitHookFiresOnConstruction(): void
        {
            $config = new \Rxn\Framework\Config();
            $config->product_namespace = 'CrudFixture';

            $crud = $this->buildCrud(CrudControllerWithInit::class, $config);
            $this->assertTrue($crud->initFired);
        }

        private function buildCrud(string $class, \Rxn\Framework\Config $config): CrudController
        {
            return new $class(
                $config,
                $this->fakeRequest(),
                $this->fakeDatabase(),
                new \Rxn\Framework\Http\Response(),
                new Container(),
            );
        }

        /**
         * Produce a Request whose only externally-visible state is
         * action_name='create' / action_version='v1' — enough for
         * Controller::__construct to find a `create_v1()` method on
         * each test subclass.
         */
        private function fakeRequest(): \Rxn\Framework\Http\Request
        {
            $cls     = new \ReflectionClass(\Rxn\Framework\Http\Request::class);
            $request = $cls->newInstanceWithoutConstructor();

            $name = $cls->getProperty('action_name');
            $name->setAccessible(true);
            $name->setValue($request, 'create');

            $version = $cls->getProperty('action_version');
            $version->setAccessible(true);
            $version->setValue($request, 'v1');

            return $request;
        }

        private function fakeDatabase(): \Rxn\Framework\Data\Database
        {
            return (new \ReflectionClass(\Rxn\Framework\Data\Database::class))
                ->newInstanceWithoutConstructor();
        }
    }

    /**
     * Stub controllers under the test namespace. CrudController's
     * `guessRecordClass` reflects on `get_called_class()`, takes the
     * short name (`CrudControllerSampleProduct` etc.), and prepends
     * `{product_namespace}\Model\`. For the "missing model" case, the
     * fixture model classes are NOT defined; for the success cases
     * the matching classes are declared in the `CrudFixture\Model`
     * namespace below.
     */
    class CrudControllerSampleProduct extends CrudController
    {
        public function create_v1(): array { return []; }
    }

    class CrudControllerWithInit extends CrudController
    {
        public bool $initFired = false;
        public function create_v1(): array { return []; }
        public function init(): void
        {
            $this->initFired = true;
        }
    }
}

namespace CrudFixture\Model {
    /**
     * Autoloadable targets for the CrudController record-class
     * lookup. Class names exactly match the controller short names
     * declared above.
     */
    class CrudControllerSampleProduct {}
    class CrudControllerWithInit {}
}
