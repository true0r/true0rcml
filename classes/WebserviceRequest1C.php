<?php

class WebserviceRequest1C
{
    protected static $instance;

    public static function getInstance()
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function fetch($key, $method, $url, $param, $badClassName, $inputXml)
    {
        $result = array();
        $result['type'] = 'txt';
        $result['headers'] = array(
            'Cache-Control: no-store',
        );

        if (!Module::isEnabled('true0r1C')) {
            return $result['content'] = "failure\nМодуль интеграции с 1С:Предприятие отключен, "
                ."необходимо его включить через админ PrestaShop в разделе модули";
        }

        $mode = isset($param['mode']) ? Tools::strtolower($param['mode']) : '';

        if ('init' == $mode || 'checkauth' == $mode) {
            $modeName = 'mode'.Tools::ucfirst($mode);
            $result['content'] = $this->$modeName();
        } else {
            if (isset($param['type']) && method_exists($this, $methodName = 'type'.Tools::ucfirst($param['type']))) {
                $result['content'] = $this->$methodName();
            } else {
                $result['content'] = "failure\nНеправильный параметр для type (может принимать значения catalog|sale)";
            }
        }

        return $result;
    }

    public function typeSale()
    {
        return "failure\nФункция обработки заказов на данный момент не реализованна";
    }
    public function typeCatalog()
    {
        // todo hook for price product, sync a othercurrency module


        return;
    }

    public function modeInit()
    {
        // apache_get_modules, extension_loaded
        $zip = 'no';

        return "zip=".$zip."\nfile_limit=".Tools::getMaxUploadSize();
    }
    public function modeCheckauth()
    {
        // note check ip

        // ??? нужну ли указывать cookie (success\ncookieName\ncookieValue)
        return "success\n";
    }
}