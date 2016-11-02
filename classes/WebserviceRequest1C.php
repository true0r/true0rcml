<?php

class WebserviceRequest1C
{
    protected static $instance;
    public $success;
    public $error;
    public $content;

    public $type;
    public $mode;
    public $param = array(
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
        } elseif ($this->checkParam($param)) {
            $this->mode = Tools::strtolower($param['mode']);
            $this->type = Tools::strtolower($param['type']);

            if ('init' == $this->mode || 'checkauth' == $this->mode) {
                $this->{'mode'.Tools::ucfirst($this->mode)}();
            } elseif ($this->checkUploadedFile()) {
                if (method_exists($this, $typeName = 'type'.Tools::ucfirst($this->type))) {
                    $this->$typeName();
                }
            }
        }

        return $this->getResult();
    }

    public function typeSale()
    {
        $this->error = "Функция обработки заказов на данный момент не реализованна";
        unlink($_FILES['tmp_name']);
    }
    public function typeCatalog()
    {
        // todo hook for price product, sync othercurrencyprice module

        $this->success = "Импорт номенклатуры выполнен успешно";
        unlink($_FILES['tmp_name']);
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

    public function checkParam($param)
    {
        foreach ($this->param as $keyParam) {
            if (!isset($param[$keyParam]) || empty($param[$keyParam])) {
                $this->error = "Не установлен {$keyParam} параметр запроса";
            } elseif (!in_array($param[$keyParam], $this->param[$keyParam])) {
                $this->error = "Не верное значение ({$param[$keyParam]}) для параметра ({$keyParam})"
                    ." Возможные варианты: ".implode('|', $this->param[$keyParam]);
            }
        }

        return empty($this->error) != true;
    }

    public function checkUploadedFile()
    {
        if (!isset($param['filename']) || empty($param['filename'])) {
            $this->error = "Не задано имя файла";
        } else {
            $filename = $param['filename'];

            if ($_FILES[$filename]['error'] != UPLOAD_ERR_OK) {
                // todo Детальное описание ошибки
                $this->error = "Ошибка загрузки";
            } elseif ($_FILES[$filename]['size'] == 0) {
                $this->error = "Размер файла 0 byte, он не содержит онформации для импорта";
            } elseif (!is_uploaded_file($_FILES[$filename])) {
                $this->error = "Попытка доступа к системным файлам. Файл существует, но не был загружен";
            }
        }

        return empty($this->error) != true;
    }

    public function getResult()
    {
        $status = empty($this->error) != true;
        $content = $status == false ? "failure\n{$this->error}" :
            empty($this->content) ? "success\n{$this->success}" : $this->content;

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

