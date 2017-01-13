<?php

class WebserviceRequestCML
{
    protected static $instance;

    const MODULE_NAME = 'true0rcml';

    /** @var ImportCMLStatus */
    public $status;
    /** @var  XMLReader */
    public $xmlReader;

    public $param;
    public $file;

    public $uploadDir;

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

    public function __construct()
    {
        $this->uploadDir = _PS_UPLOAD_DIR_.self::MODULE_NAME.DIRECTORY_SEPARATOR;

        spl_autoload_register(function ($class) {
            $path = _PS_MODULE_DIR_.self::MODULE_NAME."/classes/$class.php";
            if (is_file($path) && is_readable($path)) {
                require_once $path;

                return true;
            }
            return false;
        });

        $this->status = ImportCMLStatus::getInstance();
    }

    public function fetch($key, $method, $url, $param, $badClassName, $file)
    {
        if (!$this->status->isProgress()) {
            if (isset($param['mode']) && $this->status->message && 'import' === $param['mode']) {
                $this->status->delete();
            } else {
                $this->param = array_map('strtolower', $param);
                $this->file = $file;

                if (!Module::isEnabled(self::MODULE_NAME)) {
                    $this->status->setError("Модуль интеграции с CommerceML 2 (1С:Предприятие 8) отключен");
                } elseif (!Configuration::get('PS_WEBSERVICE')) {
                    $this->status->setError('Веб-сервисы отключены, необходимо выполнить активацию в админ панели');
                } elseif ($this->checkParam()) {
                    $mode = $this->param['mode'];
                    $methodName = 'mode'.Tools::ucfirst($mode);
                    if (method_exists($this, $methodName)) {
                        $this->$methodName();
                    } else {
                        $this->status->setError("Режим (mode: {$mode}) на данный момент не поддерживается");
                    }
                }
            }
        }

        return $this->getResult();
    }

    public function modeCheckauth()
    {
        // todo check ip
        $this->status->setSuccess("Аутентификация успешна");
    }
    public function modeInit()
    {
        $fs = new \Symfony\Component\Filesystem\Filesystem();
        $fs->remove($this->uploadDir);

        $this->status->setSimpleMessage(
            "zip=".(extension_loaded('zip') ? 'yes' : 'no')."\nfile_limit=".Tools::getMaxUploadSize()
        );
    }
    public function modeFile()
    {
        if (!$this->checkUploadedPOST()) {
            return;
        }

        $fs = new \Symfony\Component\Filesystem\Filesystem();
        $file = $this->param['filename'];
        $path = $this->uploadDir.$file;

        if (false !== strpos($file, '../')) {
            $this->status->setError("Попытка доступа к системным файлам");
            return;
        }
        if (!file_exists($path)) {
            $fs->dumpFile($path, $this->file);
        // Дописать в случае загрузки частями
        } elseif (!@file_put_contents($path, $this->file, FILE_APPEND)) {
            $this->status->setError("Файл не был сохренен $file");
            return;
        }
        $this->status->setSuccess("Файл загружен $file");
    }
    public function modeImport()
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        libxml_use_internal_errors(true);

        $this->status->setProgress('Импорт успешно начат');
        $this->terminate();

        // OPTIMIZATION: сопоставить скорость работы непосредственно с zip без распаковки
        $path = $this->uploadDir.$this->param['filename'];
        if (!($this->unzip($path) && $xmlReader = $this->getXmlReader($path))) {
            return;
        }

//        if ('offers.xml' === $this->param['filename'] && !SpecificPrice::isFeatureActive()) {
//            $this->status->setError('Цены не могут быть импортированны так как отключена функция "Специальные цены"';
//        }
        $startTime = microtime(true);
        $timeStep = 20;
        $nextTimeInterval = 0;

        try {
            while ($xmlReader->read()) {
                if ($xmlReader->nodeType != XMLReader::ELEMENT) {
                    continue;
                }
                $name = $xmlReader->localName;
                if (array_key_exists($name, ImportCML::$mapTarget)
                    && ImportCML::$mapTarget[$name]['needWalk']) {
                    $xmlSimple = new SimpleXMLElement($xmlReader->readOuterXml());
                    /** @var ImportCML $class */
                    if (!ImportCML::catchBall($name, $xmlSimple)) {
                        $this->status->setError("Импорт не удался");
                        return;
                    }
                    $xmlReader->next();

                    $processTime = microtime(true) - $startTime;
                    if (($currentTimeInterval = (int) ($processTime / $timeStep)) >= $nextTimeInterval) {
                        $nextTimeInterval = ++$currentTimeInterval;
                        $this->status->setProgress(ImportCML::getStats($startTime));
                    }
                }
            }

            $xmlReader->close();

            ImportCML::runPostImport();
            $this->status->setSuccess(ImportCML::getStats($startTime));
        } catch (Exception $e) {
            $this->status->setError("Импорт не удался из за ошибки : '{$e->getMessage()}'");
        }
    }

    public function checkParam()
    {
        foreach ($this->defParam as $key => $val) {
            if (!isset($this->param[$key]) || empty($this->param[$key])) {
                $this->status->setError("Не установлен $key параметр запроса");
            } elseif (!in_array($this->param[$key], $val)) {
                $this->status->setError("Не верное значение ({$this->param[$key]}) для параметра ($key)"
                    ." Возможные варианты: ".implode('|', $val));
            }
        }

        $mode = $this->param['mode'];
        if (('file' === $mode || 'import' === $mode)
            && (!isset($this->param['filename']) || empty($this->param['filename']))) {
            $this->status->setError('Имя файла не задано');
        }

        return $this->status->isSuccess();
    }
    public function checkUploadedPOST()
    {
        // todo size

        if (is_null($this->file)) {
            $this->status->setError('Файл не отправлен');
        } elseif (!strlen($this->file)) {
            $this->status->setError('Файл пуст');
        }

        return $this->status->isSuccess();
    }

    public function getResult()
    {
        return $result = array(
            'type' => 'txt',
            'content' => $this->status->getMessageResult(),
            'headers' => $this->getHeaders(),
        );
    }
    public function getHeaders()
    {
        return array(
            'Cache-Control: no-store, no-cache',
            'Content-Type : text/plain; charset=utf-8',
        );
    }

    public function terminate()
    {
        ignore_user_abort(true);

        $result = $this->getResult();
        foreach ($result['headers'] as $header) {
            header($header);
        }
        echo $result['content'];

        header("Content-Length: ".ob_get_length());

        // Прервать соединение
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            session_write_close();
            header("Content-Encoding: none");
            header("Connection: close");
            // really send content, can't change the order:
            // 1. ob buffer to normal buffer,
            // 2. normal buffer to output
            ob_end_flush();
            ob_flush();
            flush();
            // continue do something on server side
            ob_start();
        }
    }

    public function unzip($path)
    {
        // Если файл не существует, тогда он в архиве
        if (file_exists($path)) {
            return true;
        }

        $startTime = microtime(true);

        if (!file_exists($this->uploadDir) || @rmdir($this->uploadDir)) {
            $this->status->setError('Файлы импорта не загружены');
        } else {
            $this->status->setProgress('Начата распаковка архива');
            foreach (scandir($this->uploadDir) as $file) {
                if ($file[0] != '.' && 'zip' === pathinfo($file, PATHINFO_EXTENSION)) {
                    if (true === ($zip = new ZipArchive())->open($this->uploadDir.$file)) {
                        if (!$zip->extractTo($this->uploadDir)) {
                            $this->status->setError("Ошибка распаковки zip архива $file");
                        } else {
                            @unlink($this->uploadDir.$file);
                        }
                        $zip->close();
                    } else {
                        $this->status->setError("Не могу открыть zip архив $file");
                    }
                }
            }
        }

        if ($this->status->isSuccess()) {
            $unzipTime = (int) (microtime(true) - $startTime);
            $this->status->setProgress("Архив успешно распакован за $unzipTime");
            return true;
        } else {
            return false;
        }
    }

    public function getXmlReader($path)
    {
        // Не делаю проверку на валидность схеме XML, так как к примеру в украинской редакции
        // испльзуется не стандартный элемент ЕДРПОУ вместо ЕГРПО, также возможны модификации
        $xmlReader = new XMLReader();
        if (!$xmlReader->open($path)) {
            $errorMsg = "Ошибка загрузки XML";
            foreach (libxml_get_errors() as $error) {
                $errorMsg .= ' '.$error->message;
            }
            $this->status->setError($errorMsg);
        } elseif (!$xmlReader->next('КоммерческаяИнформация')) {
            $this->status->setError("XML файл не имеет узла 'КоммерческаяИнформация', что не соответствует CommerceML");
        } elseif (!$version = $xmlReader->getAttribute('ВерсияСхемы')) {
            $this->status->setError("Элемент КоммерческаяИнформация не имеет атрибута ВерсияСхемы");
        } elseif (!Tools::version_compare('2.05', $version, '<=')) {
            $this->status->setError("Версия ($version) схемы не поддерживается");
        }
        if ($this->status->isSuccess()) {
            return $xmlReader;
        } else {
            return false;
        }
    }
}
