<?php

namespace ESBackendSearch;

use Elasticsearch\Client;
use Pimcore\API\Plugin as PluginLib;
use Pimcore\Model\Object\Concrete;

class Plugin extends PluginLib\AbstractPlugin implements PluginLib\PluginInterface
{

    const QUEUE_TABLE_NAME = "plugin_esbackendsearch_update_queue";

    public function init()
    {
        parent::init();

        // register your events here
        \Pimcore::getEventManager()->attach("object.postUpdate", array($this, "updateObject"));
        \Pimcore::getEventManager()->attach("object.preDelete", array($this, "deleteObject"));

        \Pimcore::getEventManager()->attach('system.console.init', function (\Zend_EventManager_Event $e) {
            /** @var \Pimcore\Console\Application $application */
            $application = $e->getTarget();

            // add a namespace to autoload commands from
            $application->addAutoloadNamespace(
                'ESBackendSearch\\Console\\Command', __DIR__ . '/Console/Command'
            );
        });


        $pluginInstance = $this;
        \Pimcore::getEventManager()->attach("system.maintenance", function ($e) use ($pluginInstance) {
            $e->getTarget()->registerJob(new \Pimcore\Model\Schedule\Maintenance\Job(get_class($pluginInstance), $pluginInstance, "maintenance"));
        });

    }

    public function updateObject($event)
    {
        $object = $event->getTarget();
        if($object instanceof Concrete) {
            $service = new \ESBackendSearch\Service();
            $service->doUpdateIndexData($object);
        }
    }

    public function deleteObject($event)
    {
        $object = $event->getTarget();
        if($object instanceof Concrete) {
            $service = new \ESBackendSearch\Service();
            $service->doDeleteFromIndex($object);
        }
    }

    public function maintenance() {
        $service = new Service();
        $service->processUpdateQueue(500);
    }


    public static function install()
    {
        if(!file_exists(PIMCORE_WEBSITE_PATH . "/config/esbackendsearch")) {
            \Pimcore\File::mkdir(PIMCORE_WEBSITE_PATH . "/config/esbackendsearch");
            copy(PIMCORE_PLUGINS_PATH . "/ESBackendSearch/install/config.php", PIMCORE_WEBSITE_PATH . "/config/esbackendsearch/config.php");
        }

        \Pimcore\File::mkdir(PIMCORE_WEBSITE_VAR . "/plugins/ESBackendSearch");
        file_put_contents(PIMCORE_WEBSITE_VAR . "/plugins/ESBackendSearch/installed.dummy", "true");

        return true;
    }

    public static function needsReloadAfterInstall()
    {
        return true;
    }


    public static function uninstall()
    {
        unlink(PIMCORE_WEBSITE_VAR . "/plugins/ESBackendSearch/installed.dummy");
    }

    public static function isInstalled()
    {
        return file_exists(PIMCORE_WEBSITE_VAR . "/plugins/ESBackendSearch/installed.dummy");
    }


    /**
     * @var Client
     */
    protected static $esClient = null;

    /**
     * @return Client
     */
    public static function getESClient() {

        if(empty(self::$esClient)) {
            $config = self::getConfig();
            self::$esClient = \Elasticsearch\ClientBuilder::create()->setHosts($config['hosts'])->build();
        }

        return self::$esClient;
    }

    /**
     * @var array
     */
    protected static $config;
    public static function getConfig() {
        if(empty(self::$config)) {
            $file = \Pimcore\Config::locateConfigFile("esbackendsearch/config.php");
            if(file_exists($file)) {
                $config = include($file);
            } else {
                throw new \Exception($file . " doesn't exist");
            }
            self::$config = $config;
        }

        return self::$config;
    }


    /**
     * @param string $language
     * @return string path to the translation file relative to plugin directory
     */
    public static function getTranslationFile($language)
    {
        return sprintf('/ESBackendSearch/texts/%s.csv', $language);
    }
}
