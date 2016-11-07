<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class True0r1C extends Module
{
    const NAME_CLASS_REQUEST = 'WebserviceRequest1C';
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
        return Db::getInstance()->insert(
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
        $this->name = 'true0r1C';
        $this->tab = 'others';
        $this->version = '0.1.0';
        $this->author = 'Alexander Galaydyuk';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array('min' => '1.7', 'max' => _PS_VERSION_);
        $this->bootstrap = true;

        parent::__construct();
        // @codingStandardsIgnoreStart
        $this->displayName = $this->l('Интеграция с 1С:Предприятие 8');
        $this->description = $this->l('Интеграция на базе протокола CommerceML2, для выгрузки товаров и цен');
        $this->confirmUninstall = $this->l('Надеюсь Вам больше не нужен этот модоль так как вы нашли замену 1С из мира open source &)');
        // @codingStandardsIgnoreEnd
    }

    public function install()
    {
        $fileName = self::NAME_CLASS_REQUEST.'.php';
        $classAdd = copy($this->getLocalPath().'classes/'.$fileName, _PS_CLASS_DIR_.'webservice/'.$fileName);
        PrestaShopAutoload::getInstance()->generateIndex();

        return (
            $classAdd
            && $this->addWsKey()
            && $this->registerHooks()
            && parent::install()
        );
    }
    public function uninstall()
    {
        @unlink(_PS_CLASS_DIR_.'webservice/'.self::NAME_CLASS_REQUEST.'.php');

        return (
            $this->delWsKey()
            && $this->unregisterHooks()
//            && Db::getInstance()->execute("DROP TABLE IF EXISTS {$this->getTableName()};")
            && parent::uninstall()
        );
    }

    protected function registerHooks()
    {
        // Проверить существование хука, убедится в совместимости версий ПО
        foreach ($this->hooks as $hook) {
            if ($alias = Hook::getRetroHookName($hook)) {
                $hook = $alias;
            }
            if (!Hook::getIdByName($hook)) {
                return false;
            }


            if (!$this->registerHook($hook)) {
                $this->_errors[] = sprintf($this->l('Failed to install hook "%s"'), $hook);
                return false;
            }
        }
        return true;
    }
    protected function unregisterHooks()
    {
        foreach ($this->hooks as $hook) {
            if (!$this->unregisterHook($hook)) {
                $this->_errors[] = sprintf($this->l('Failed to uninstall hook "%s"'), $hook);
                return false;
            }
        }
        return true;
    }
}
