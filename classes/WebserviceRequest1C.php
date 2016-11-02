<?php

class WebserviceRequest1C
{
    protected static $instance;
    public $success;
    public $error;
    public $content;

    public $defParam = array(
        'type' => array('catalog', 'sale'),
        'mode' => array('init', 'checkauth', 'import', 'file'),
    );

    public static function getInstance()
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function fetch($key, $method, $url, $param, $badClassName, $inputXml)
    {
        if (!Module::isEnabled('true0r1C')) {
            $this->error = "Модуль интеграции с 1С:Предприятие отключен, "
                ."необходимо его включить через админ PrestaShop в разделе модули";
        } elseif ($this->checkParam($param, $inputXml)) {
            $mode = Tools::strtolower($param['mode']);
            $type = Tools::strtolower($param['type']);

            switch ($mode) {
                case 'init':
                case 'checkauth':
                    $this->{'mode'.Tools::ucfirst($mode)}();
                    break;
                case 'import':
                case 'file':
                    if (method_exists($this, $methodName = 'type'.Tools::ucfirst($type))) {
                        $this->$methodName($inputXml);
                    } else {
                        $this->error = "Тип {$methodName} операции на данный момент не поддерживается";
                    }
                    break;
            }
        }

        return $this->getResult();
    }

    public function typeCatalog()
    {
        // todo hook for price product, sync othercurrencyprice module

        $this->success = "Импорт номенклатуры выполнен успешно";
    }

    public function modeInit()
    {
        // apache_get_modules, extension_loaded
        $zip = 'no';

        $this->content = "zip=".$zip."\nfile_limit=".Tools::getMaxUploadSize();
    }
    public function modeCheckauth()
    {
        // todo check ip
        // ??? нужну ли указывать cookie (success\ncookieName\ncookieValue)
        $this->success = "Аутентификация успешна";
    }

    public function checkParam($param, $input)
    {
        foreach ($this->defParam as $key => $val) {
            if (!isset($param[$key]) || empty($param[$key])) {
                $this->error = "Не установлен {$key} параметр запроса";
            } elseif (!in_array($param[$key], $val)) {
                $this->error = "Не верное значение ({$param[$key]}) для параметра ({$key})"
                    ." Возможные варианты: ".implode('|', $val);
            }
        }

        if ($input && (!isset($param['filename']) || empty($param['filename']))) {
            $this->error = "Не задано имя файла";
        } elseif ($input && !strlen($input)) {
            $this->error = "Файл импорта пуст";
        }

        return empty($this->error);
    }

    public function getResult()
    {
        $content = !empty($this->error) ? "failure\n{$this->error}" :
            (empty($this->content) ? "success\n{$this->success}" : $this->content);

        return $result = array(
            'type' => 'txt',
            'content' => $content,
            'headers' => $this->getHeaders(),
        );
    }
    public function getHeaders()
    {
        return array(
            'Cache-Control: no-store, no-cache',
            //'Content-Type:',
        );
    }
}
