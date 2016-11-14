<?php

class WebserviceRequestCML
{
    protected static $instance;

    const MODULE_NAME = 'true0rcml';

    public $success;
    public $error;
    public $content;

    public $param;
    public $file;

    public $logger;
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
        $path = _PS_MODULE_DIR_.self::MODULE_NAME.DIRECTORY_SEPARATOR.'log.txt';

        // удалить большой лог
        if (file_exists($path) && filesize($path) > Tools::convertBytes('2M')) {
            @unlink($path);
        }

        $this->logger = new FileLogger(FileLogger::DEBUG);
        $this->logger->setFilename($path);
    }

    public function fetch($key, $method, $url, $param, $badClassName, $file)
    {
        spl_autoload_register(function ($class) {
            $path = _PS_MODULE_DIR_.self::MODULE_NAME."/classes/$class.php";
            if (is_file($path) && is_readable($path)) {
                require_once $path;

                return true;
            }
            return false;
        });

        $this->logger->logDebug($_SERVER['QUERY_STRING']);

        $this->param = array_map('strtolower', $param);
        $this->file = $file;

        if (!Module::isEnabled(self::MODULE_NAME)) {
            $this->error = "Модуль интеграции с CommerceML 2 (1С:Предприятие 8) отключен";
        } elseif (!Configuration::get('PS_WEBSERVICE')) {
            $this->error = 'Веб-сервисы отключены, необходимо выполнить активацию в админ панели';
        } elseif ($this->checkParam()) {
            $mode = $this->param['mode'];
            $methodName = 'mode'.Tools::ucfirst($mode);
            if (method_exists($this, $methodName)) {
                $this->$methodName();
            } else {
                $this->error = "Режим (mode: {$mode}) на данный момент не поддерживается";
            }
        }

        if (!empty($this->error)) {
            $this->logger->logError($this->error);
        } else {
            $this->logger->logInfo($this->content ? str_replace("\n", '; ', $this->content) : $this->success);
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
        $fs = new \Symfony\Component\Filesystem\Filesystem();
        $fs->remove($this->uploadDir);

        $this->content = "zip=".(extension_loaded('zip') ? 'yes' : 'no')."\nfile_limit=".Tools::getMaxUploadSize();
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
            $this->error = "Попытка доступа к системным файлам";
            return;
        }
        if (!file_exists($path)) {
            $fs->dumpFile($path, $this->file);
        // Дописать в случае загрузки частями
        } elseif (!@file_put_contents($path, $this->file, FILE_APPEND)) {
            $this->error = "Файл не был сохренен $file";
            return;
        }
        $this->success = "Файл загружен $file";
    }
    public function modeImport()
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        libxml_use_internal_errors(true);

        // Не делаю проверку на валидность схеме XML, так как к примеру в украинской редакции
        // испльзуется не стандартный элемент ЕДРПОУ вместо ЕГРПО, также возможны модификации
        $xmlReader = new XMLReader();

        if (!$xmlReader->open($this->uploadDir.$this->param['filename'])) {
            $this->error = "Ошибка загрузки XML";
            foreach (libxml_get_errors() as $error) {
                $this->error .= ' ' . $error->message;
            }
        } elseif (!$xmlReader->next('КоммерческаяИнформация')) {
            $this->error = "XML файл не имеет узла 'КоммерческаяИнформация', что не соответствует CommerceML 2";
        } elseif (!$version = $xmlReader->getAttribute('ВерсияСхемы')) {
            $this->error = "Элемент КоммерческаяИнформация не имеет атрибута ВерсияСхемы";
        } elseif (!Tools::version_compare('2.05', $version, '<=')) {
            $this->error = "Версия ($version) схемы не поддерживается";
        }
        if (!empty($this->error)) {
            return;
        }

        $startTime = microtime(true);
        try {
            while ($xmlReader->read()) {
                if ($xmlReader->nodeType != XMLReader::ELEMENT) {
                    continue;
                }
                $name = $xmlReader->localName;
                if (array_key_exists($name, ImportCML::$mapTargetClassName)) {
                    $xmlSimple = new SimpleXMLElement($xmlReader->readOuterXml());
                    /** @var ImportCML $class */
                    if (!ImportCML::catchBall($name, $xmlSimple)) {
                        $this->error = "Импорт не удался";
                        return;
                    }
                    $xmlReader->next();
                }
            }
            $xmlReader->close();
            $stats =
                "Peak memory: ".(memory_get_peak_usage(true) / 1024 / 1024)
                ."MB Time: ".(microtime(true) - $startTime);
            $this->success = "Импорт выполнен успешно ".$stats;
        } catch (Exception $e) {
            $this->error = "Импорт не удался из за ошибки : '{$e->getMessage()}'";
        }
    }

    public function checkParam()
    {
        foreach ($this->defParam as $key => $val) {
            if (!isset($this->param[$key]) || empty($this->param[$key])) {
                $this->error = "Не установлен $key параметр запроса";
            } elseif (!in_array($this->param[$key], $val)) {
                $this->error = "Не верное значение ({$this->param[$key]}) для параметра ($key)"
                    ." Возможные варианты: ".implode('|', $val);
            }
        }

        $mode = $this->param['mode'];
        if (('file' === $mode || 'import' === $mode)
            && (!isset($this->param['filename']) || empty($this->param['filename']))) {
            $this->error = 'Имя файла не задано';
        }

        return empty($this->error);
    }
    public function checkUploadedPOST()
    {
        // todo size

        if (is_null($this->file)) {
            $this->error = 'Файл не отправлен';
        } elseif (!strlen($this->file)) {
            $this->error = 'Файл пуст';
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
