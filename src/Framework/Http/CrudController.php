<?php declare(strict_types=1);

namespace Rxn\Framework\Http;

use \Rxn\Framework\Container;
use \Rxn\Framework\Config;
use \Rxn\Framework\Data\Database;
use \Rxn\Framework\Http\Response;
use \Rxn\Framework\Http\Controller\Crud;

class CrudController extends Controller implements Crud
{
    /**
     * @var string
     */
    private $called_class;

    /**
     * @var string
     */
    public $record_class;

    /**
     * CrudController constructor.
     *
     * @param Config    $config
     * @param Request   $request
     * @param Database  $database
     * @param Response  $response
     * @param Container $container
     *
     * @throws \Exception
     */
    public function __construct(
        Config $config,
        Request $request,
        Database $database,
        Response $response,
        Container $container
    ) {
        parent::__construct($config, $request, $database, $response, $container);

        // if a record class is not explicitly defined, assume the short name of the crud controller
        $this->called_class = get_called_class();
        $this->record_class = $this->guessRecordClass();
        $this->validateRecordClass();
        $this->triggerInit();
    }

    /**
     * @return array
     * @throws \Rxn\Framework\Error\ContainerException
     */
    public function create()
    {
        $key_values = $this->request->getCollector()->getFromRequest();
        $order      = $this->container->get($this->record_class);
        $created_id = $order->create($key_values);
        return ['created_id' => $created_id];
    }

    /**
     * @return mixed
     * @throws \Exception
     * @throws \Rxn\Framework\Error\ContainerException
     */
    public function read()
    {
        $record_id = $this->request->getCollector()->getParamFromGet('id');
        $order     = $this->container->get($this->record_class);
        return $order->read($record_id);
    }

    public function update()
    {
        $record_id  = $this->request->getCollector()->getParamFromGet('id');
        $key_values = $this->request->getCollector()->getFromRequest();
        unset($key_values['id']);
        $order      = $this->container->get($this->record_class);
        $updated_id = $order->update($record_id, $key_values);
        return ['updated_id' => $updated_id];
    }

    /**
     * @return array
     * @throws \Exception
     */
    public function delete()
    {
        $record_id  = $this->request->getCollector()->getParamFromGet('id');
        $record     = $this->container->get($this->record_class);
        $deleted_id = $record->delete($record_id);
        return ['deleted_id' => $deleted_id];
    }

    /**
     * @return string
     */
    private function guessRecordClass()
    {
        $reflection       = new \ReflectionClass($this->called_class);
        $short_name       = $reflection->getShortName();
        $potential_record = "{$this->config->product_namespace}\\Model\\$short_name";
        return $potential_record;
    }

    /**
     * @throws \Exception
     */
    private function validateRecordClass()
    {
        $called_class = get_called_class();
        if (!class_exists($this->record_class, true)) {
            throw new \Exception("CrudController '$called_class' references missing model '$this->record_class'", 500);
        }
    }

    private function triggerInit()
    {
        if (method_exists($this, 'init')) {

        }
    }
}
