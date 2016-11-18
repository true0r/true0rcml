<?php

// todo Импорт вручную через UI PS

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once(__DIR__.'/classes/EntityCML.php');

class True0rCML extends Module
{
    const NAME_CLASS_REQUEST = 'WebserviceRequestCML';
    protected $hooks = array();

    public function __construct()
    {
        $this->name = 'true0rcml';
        $this->tab = 'others';
        $this->version = '0.1.0';
        $this->author = 'Alexander Galaydyuk';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array('min' => '1.7', 'max' => _PS_VERSION_);
        $this->bootstrap = true;

        parent::__construct();
        // @codingStandardsIgnoreStart
        $this->displayName = $this->l('Интеграция с CommerceML 2 (1С:Предприятие 8)');
        $this->description = $this->l('Интеграция на базе протокола CommerceML2, для выгрузки товаров и цен');
        $this->confirmUninstall = $this->l('Будут удаленны все данные о синхронизации, если потребуется воспользоватся модулем снова, то сперва прейдется импортировать все товары');
        // @codingStandardsIgnoreEnd
    }

    public function getContent()
    {
        $this->postProcess();

        $this->context->smarty->assign($this->getConfValues());
        return $this->context->smarty->fetch($this->getLocalPath().'/views/templates/admin/configure.tpl');
    }
    public function getWarning()
    {
    }
    public function getConfValues()
    {
        $link = $this->context->link->getAdminLink('AdminModules').'&configure='.$this->name
            .'&tab_module='.$this->tab.'&module_name='.$this->name.'&';

        return array(
            'title' => $this->displayName,
            'wsKey' => $this->getWsKey(),
            'link' => $this->context->link->getBaseLink()."api",
            'linkAction' => array(
                'newWsKey' => $link.'new_ws_key',
            )
        );
    }

    public function postProcess()
    {
        if (Tools::isSubmit('new_ws_key')) {
            $this->delWsKey() && $this->addWsKey();
        }
    }

    public function useNormalPermissionBehaviour()
    {
        return false;
    }

    public function addWsKey()
    {
        $dbStatus = Db::getInstance()->insert(
            WebserviceKey::$definition['table'],
            array(
                'key' => Tools::strtoupper(Tools::passwdGen(32)),
                'class_name' => self::NAME_CLASS_REQUEST,
                'active' => 1,
                'is_module' => 1,
                'module_name' => $this->name,
                'description' => $this->description,
            )
        );
        !$dbStatus && $this->_errors[] = $this->l('Cannot create webservice key for module');
        return $dbStatus;
    }
    public function getWsKey()
    {
        static $key;

        if (!$key) {
            $key = Db::getInstance()->getValue(
                (new DbQuery())
                    ->select("`key`")
                    ->from(WebserviceKey::$definition['table'])
                    ->where("module_name = '{$this->name}'")
            );

            // if key does not exists
            !$key && $this->addWsKey() && $key = $this->getWsKey();
        }

        return $key;
    }
    public function delWsKey()
    {
        return Db::getInstance()->delete(WebserviceKey::$definition['table'], "module_name = '{$this->name}'");
    }

    public function install()
    {
        Configuration::updateGlobalValue('PS_WEBSERVICE', 1);

        $file = self::NAME_CLASS_REQUEST.'.php';
        $pathModule = $this->getLocalPath().'classes/'.$file;
        $pathClasses = _PS_CLASS_DIR_.'webservice/'.$file;

        file_exists($pathClasses) && @unlink($pathClasses);
        if (!@symlink($pathModule, $pathClasses)) {
            $this->_errors[] = sprintf($this->l('Не могу скопировать ксласс %s в папку classes/'), $file);
            return false;
        }
        PrestaShopAutoload::getInstance()->generateIndex();

        $dbStatus = DB::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS '._DB_PREFIX_.EntityCML::$definition['table'].' (
                `id_entitycml` int(10) unsigned NOT NULL auto_increment,
                `id_target` int(10) unsigned NOT NULL,
                `target_class` int(2) unsigned NOT NULL,
                `guid` varchar(80),
                `hash` varchar(32) NOT NULL,
                PRIMARY KEY (`id_entitycml`)
            ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8'
        );
        !$dbStatus && $this->_errors[] = $this->l('Cannot create table for module');

        return (
            $dbStatus
            && $this->addWsKey()
            && parent::install()
            && $this->registerHook($this->hooks)
        );
    }
    public function uninstall()
    {
        Configuration::updateGlobalValue('PS_WEBSERVICE', 0);

        $fs = new \Symfony\Component\Filesystem\Filesystem();
        $fs->remove(_PS_UPLOAD_DIR_.$this->name);
        @unlink(_PS_CLASS_DIR_.'webservice/'.self::NAME_CLASS_REQUEST.'.php');

        $this->delWsKey();
        return (
            Db::getInstance()->execute('DROP TABLE IF EXISTS '._DB_PREFIX_.EntityCML::$definition['table'])
            && parent::uninstall()
        );
    }
}
