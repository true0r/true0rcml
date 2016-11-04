<?php

class WebserviceRequest1C
{
    protected static $instance;

    const MODULE_NAME = 'true0r1C';

    public $success;
    public $error;
    public $content;

    public $param;
    public $file;

    public $defParam = array(
        'type' => array('catalog', 'sale'),
        'mode' => array('init', 'checkauth', 'import', 'file', 'query'),
    );

    public static function getInstance()
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function fetch($key, $method, $url, $param, $badClassName, $file)
    {
        $this->param = array_map('strtolower', $param);
        $this->file = $file;

        if (!Module::isEnabled(self::MODULE_NAME)) {
            $this->error = "Модуль интеграции с 1С:Предприятие 8 отключен";
        } elseif ($this->checkParam()) {
            $mode = $this->param['mode'];
            $methodName = 'mode'.Tools::ucfirst($mode);
            if (method_exists($this, $methodName)) {
                $this->$methodName();
            } else {
                $this->error = "Режим (mode: {$mode}) на данный момент не поддерживается";
            }
        }

        return $this->getResult();
    }

    public function modeCheckauth()
    {
        // todo check ip
        // ??? нужну ли указывать cookie (success\ncookieName\ncookieValue)
        $this->success = "Аутентификация успешна";
    }
    public function modeInit()
    {
        $this->cleanCache()
        && $this->content = "zip=".(extension_loaded('zip') ? 'yes' : 'no')."\nfile_limit=".Tools::getMaxUploadSize();
    }
    public function modeFile()
    {
        // todo поддержка загрузки частями

        if (!$this->checkUploadedFile()) {
            return;
        }

        $cacheDir = _PS_CACHE_DIR_.self::MODULE_NAME;
        $fileName = $this->param['filename'];
        $path = realpath($cacheDir.DIRECTORY_SEPARATOR.basename($fileName)).DIRECTORY_SEPARATOR.$fileName;

        if (false !== strpos($path, $cacheDir)) {
            $this->error = "Попытка доступа к системным файлам";

        // unzip
        } elseif ($this->saveFile($path)
            && 'zip' === pathinfo($fileName, PATHINFO_EXTENSION)) {
            if (true === ($zip = new ZipArchive())->open($path)) {
                if (!$zip->extractTo($cacheDir)) {
                    $this->error = "Ошибка распаковки zip архива $path";
                }
                $zip->close();
            } else {
                $this->error = "Не могу открыть zip архив $path";
            }
        }
    }
    public function modeImport()
    {
        $type = $this->param['type'];
        $methodName = 'import'.Tools::ucfirst($type);

        if (method_exists($this, $methodName)) {
            $this->$methodName();
        } else {
            $this->error = "Импорт (type: $type) на данный момент не поддерживается";
        }
    }

    public function importCatalog()
    {
        // todo hook for price product, sync othercurrencyprice module
        // ?? при изменении каталога или другой сущности его GUID изменяется
        // ?? содержит только изменения

//        set_time_limit(0);
        $this->success = "Импорт номенклатуры выполнен успешно";
    }

    public function checkParam()
    {
        // todo проверка версии схемы (ВерсияСхемы) &version=v

        foreach ($this->defParam as $key => $val) {
            if (!isset($this->param[$key]) || empty($this->param[$key])) {
                $this->error = "Не установлен {$key} параметр запроса";
            } elseif (!in_array($this->param[$key], $val)) {
                $this->error = "Не верное значение ({$this->param[$key]}) для параметра ({$key})"
                    . " Возможные варианты: " . implode('|', $val);
            }
        }

        $mode = $this->param['mode'];
        if (('file' === $mode || 'import' === $mode)
            && !isset($this->param['filename']) || empty($this->param['filename'])) {
            $this->error = 'Имя файла не задано';
        }

        return empty($this->error);
    }
    public function checkUploadedFile()
    {
        if (is_null($this->file)) {
            $this->error = 'Файл не отправлен';
        } elseif (!strlen($this->file)) {
            $this->error = 'Файл пуст';
        }

        return empty($this->error);
    }

    public function cleanCache()
    {
        $cacheDir = _PS_CACHE_DIR_.self::MODULE_NAME;
        if (!$this->remove($cacheDir)) {
            $this->error = "Не могу очистить папку с кешем $cacheDir";
        }

        return empty($this->error);
    }
    public function remove($path)
    {
        if (file_exists($path)) {
            if (is_dir($path)) {
                $dir = dir($path);
                while (false !== ($fileName = $dir->read())) {
                    if ('.' != $fileName || '..' != $fileName) {
                        if (!$this->remove($dir->path.DIRECTORY_SEPARATOR.$fileName)) {
                            return false;
                        }
                    }
                }
                $dir->close();
                return !@rmdir($path) && file_exists($path);
            } else {
                return !@unlink($path) && file_exists($path);
            }
        } else {
            return true;
        }
    }
    public function saveFile($path)
    {
        $fileName = basename($path);
        $path = dirname($path);

        $dirs = array($path);
        $dir = dirname($path);
        $lastDirName = '';
        while ($lastDirName != $dir) {
            array_unshift($dirs, $dir);
            $lastDirName = $dir;
            $dir = dirname($path);
        }

        foreach ($dirs as $dir) {
            if (!file_exists($dir) && !@mkdir($dir)) {
                $error = error_get_last();
                $this->error = "Не могу создать папку с кешем $dir {$error['massage']}";
                break;
            } elseif (!is_dir($dir)) {
                $this->error = "Это не директрория $dir";
                break;
            }
        }

        if (empty($this->error)) {
            if (!file_put_contents($path.DIRECTORY_SEPARATOR.$fileName, $this->file)) {
                $this->error = "Файл не был сохренен $path/$fileName";
            }
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
