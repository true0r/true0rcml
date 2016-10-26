<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class True0r1C extends Module
{
    protected $hooks = array(
        
    );

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
        $this->displayName = $this->l('Интеграция с 1С Предприятие');
        $this->description = $this->l('Интеграция на базе протокола CommerceML2, для выгрузки товаров и цен');
        $this->confirmUninstall = $this->l('Надеюсь Вам больше не нужен этот модоль так как вы нашли замену 1С из мира open source &)');
        // @codingStandardsIgnoreEnd
    }

    public function install()
    {
        return (parent::install()
            && $this->registerHooks()
        );
    }
    public function uninstall()
    {
        return (parent::uninstall()
            && $this->unregisterHooks()
            && Db::getInstance()->execute("DROP TABLE IF EXISTS {$this->getTableName()};")
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

    public function getTableName($addPrefix = false)
    {
        return ($addPrefix ? _DB_PREFIX_ : '').Tools::strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', __CLASS__));
    }
}
