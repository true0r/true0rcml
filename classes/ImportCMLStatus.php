<?php

class ImportCMLStatus
{
    const MODULE_NAME = 'true0rcml';
    const STATUS_PROGRESS = 1;
    const STATUS_SUCCESS = 2;
    const STATUS_ERROR = 3;
    // for init mode
    const STATUS_SIMPLE_MESSAGE = 4;

    protected static $instance;

    public $confName = array();
    public $status;
    public $message;

    /** @var FileLogger */
    public $logger;

    protected function __construct()
    {
        $this->confName = array(
            'status' => self::MODULE_NAME.'STATUS',
            'message' => self::MODULE_NAME.'STATUS_MESSAGE',
        );

        $path = _PS_MODULE_DIR_.self::MODULE_NAME.DIRECTORY_SEPARATOR.'log.txt';

        // удалить большой лог
        if (file_exists($path) && filesize($path) > Tools::convertBytes('2M')) {
            @unlink($path);
        }

        $this->logger = new FileLogger(FileLogger::DEBUG);
        $this->logger->setFilename($path);
//        $this->logger->logDebug($_SERVER['QUERY_STRING']);
    }
    public static function getInstance()
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function isProgress($mode)
    {
        // Не установлен
        if (($status = Configuration::get($this->confName['status'])) === false) {
            return false;
        }

        if ('import' == $mode) {
            $this->setMessage(Configuration::get($this->confName['message']), $status);
        } elseif (self::STATUS_PROGRESS == $status) {
            $this->setMessage('Подождите окончание процесса импорта, прежде чем сделать новый', self::STATUS_ERROR);
        }

        if (self::STATUS_PROGRESS != $status) {
            $this->removeStatus();
        }

        return isset($this->message);
    }

    public function isSuccess()
    {
        return $this->status != self::STATUS_ERROR;
    }

    public function getMessageResult()
    {

        switch ($this->status) {
            case self::STATUS_PROGRESS:
                $messageStatus = "progress\n";
                break;
            case self::STATUS_SUCCESS:
                $messageStatus = "success\n";
                break;
            case self::STATUS_ERROR:
                $messageStatus = "failure\n";
                break;
            case self::STATUS_SIMPLE_MESSAGE:
            default:
                $messageStatus = "";
                break;
        }

        // 1C не поспринимает кодировку UTF-8 не смотря на заголовки HTTP
        // необходимо добавить маркер BOM
        return chr(0xEF).chr(0xBB).chr(0xBF).$messageStatus.$this->message;
    }

    public function setMessage($msg, $status = self::STATUS_SUCCESS)
    {
        $this->status = $status;
        $this->message = $msg;

        if (self::STATUS_ERROR == $status) {
            $this->logger->logError($msg);
        } else {
            if (self::STATUS_SIMPLE_MESSAGE == $status) {
                $msg = str_replace("\n", '; ', $msg);
            }
            $this->logger->logInfo($msg);
        }
    }
    public function setError($msg)
    {
        $this->setMessage($msg, self::STATUS_ERROR);
    }
    public function setSuccess($msg)
    {
        $this->setMessage($msg, self::STATUS_SUCCESS);
    }
    public function setProgress($msg)
    {
        $this->setMessage($msg, self::STATUS_PROGRESS);
        $this->saveStatus();
    }
    public function setSimpleMessage($msg)
    {
        $this->setMessage($msg, self::STATUS_SIMPLE_MESSAGE);
    }

    public function saveStatus()
    {
        Configuration::updateGlobalValue($this->confName['status'], $this->status);
        Configuration::updateGlobalValue($this->confName['message'], $this->message);
    }
    public function removeStatus()
    {
        Configuration::deleteByName($this->confName['status']);
        Configuration::deleteByName($this->confName['message']);
    }
}
