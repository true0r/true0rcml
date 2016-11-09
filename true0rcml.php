<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class True0rCML extends Module
{
    const NAME_CLASS_REQUEST = 'WebserviceRequestCML';
    protected $hooks = array();

    public function getContent()
    {
        $this->postProcess();

        $this->context->smarty->assign($this->getConfValues());
        return $this->context->smarty->fetch($this->getLocalPath().'/views/templates/admin/configure.tpl');
    }
    public function getWarning()
    {
        // todo не установлен модуль мультивалюты
    }
    protected function getConfValues()
    {
        $link = $this->context->link->getAdminLink('AdminModules').'&configure='.$this->name
            .'&tab_module='.$this->tab.'&module_name='.$this->name.'&';

        return array(
            'title' => $this->displayName,
            'link1C' => preg_replace(
                '/^(https?:\/\/)/',
                "$1{$this->getWsKey()}@",
                "{$this->context->link->getBaseLink()}api/"),
            'linkAction' => array(
                'newWsKey' => $link.'new_ws_key',
            )
        );
    }

    public function postProcess()
    {
        if (Tools::isSubmit('new_ws_key')) {
            $this->newWsKey();
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
    public function newWsKey()
    {
        return (
            $this->delWsKey()
            && $this->addWsKey()
        );
    }

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

    public function install()
    {
        $fileName = self::NAME_CLASS_REQUEST.'.php';
        $pathModule = $this->getLocalPath().'classes/'.$fileName;
        $pathClasses = _PS_CLASS_DIR_.'webservice/'.$fileName;

        file_exists($pathClasses) && @unlink($pathClasses);
        if (!@copy($pathModule, $pathClasses)) {
            $this->_errors[] = sprintf($this->l('Не могу скопировать ксласс %s в папку classes/'), $fileName);
            return false;
        }
        PrestaShopAutoload::getInstance()->generateIndex();

        $dbStatus = DB::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS '._DB_PREFIX_.$this->name.' (
                `id` int(10) unsigned NOT NULL,
                `guid` VARCHAR(40) NOT NULL,
                `hash` VARCHAR(32) NOT NULL,
                PRIMARY KEY (`guid`)
            ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8'
        );
        !$dbStatus && $this->_errors[] = $this->l('Cannot create table for module');

        return (
            $dbStatus
            && $this->addWsKey()
            && $this->registerHook($this->hooks)
            && parent::install()
        );
    }
    public function uninstall()
    {
        @unlink(_PS_CLASS_DIR_.'webservice/'.self::NAME_CLASS_REQUEST.'.php');

        $this->delWsKey();
        return (
            Db::getInstance()->execute('DROP TABLE IF EXISTS '._DB_PREFIX_.$this->name)
            && parent::uninstall()
        );
    }
}
