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
        $this->logger = new FileLogger(FileLogger::DEBUG);
        $this->logger->setFilename(_PS_MODULE_DIR_.self::MODULE_NAME.DIRECTORY_SEPARATOR.'log.txt');
    }

    public function fetch($key, $method, $url, $param, $badClassName, $file)
    {
        $this->logger->logDebug($_SERVER['QUERY_STRING']);

        $this->param = array_map('strtolower', $param);
        $this->file = $file;

        if (!Module::isEnabled(self::MODULE_NAME)) {
            $this->error = "Модуль интеграции с CommerceML 2 (1С:Предприятие 8) отключен";
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
        $this->cleanCache()
        && $this->content = "zip=".(extension_loaded('zip') ? 'yes' : 'no')."\nfile_limit=".Tools::getMaxUploadSize();
    }
    public function modeFile()
    {
        // todo поддержка загрузки частями

        if (!$this->checkUploadedPOST()) {
            return;
        }

        $fileName = $this->param['filename'];
        $path = $this->uploadDir.$fileName;

        if (false !== strpos($fileName, '../')) {
            $this->error = "Попытка доступа к системным файлам";

        // unzip
        } elseif ($this->saveFile($path)
            && 'zip' === pathinfo($fileName, PATHINFO_EXTENSION)) {
            if (true === ($zip = new ZipArchive())->open($path)) {
                if (!$zip->extractTo($this->uploadDir)) {
                    $this->error = "Ошибка распаковки zip архива $path";
                } else {
                    $this->success = "Zip архив загружен и распакован $path";
                }
                $zip->close();
                @unlink($path);
            } else {
                $this->error = "Не могу открыть zip архив $path";
            }
        }
    }
    public function modeImport()
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');

        $type = $this->param['type'];
        $method = 'import'.Tools::ucfirst($type);

        if (method_exists($this, $method)) {
            $path = $this->uploadDir.$this->param['filename'];
            libxml_use_internal_errors(true);

            $xmlReader = new XMLReader();

            if (!$xmlReader->open($path)) {
                $this->error = "Ошибка загрузки XML";
                foreach (libxml_get_errors() as $error) {
                    $this->error .= ' '.$error->message;
                }
            } else {
                if (!$xmlReader->next('КоммерческаяИнформация')) {
                    $this->error = "XML файл не имеет узла 'КоммерческаяИнформация', что не соответствует CommerceML 2";
                } else {
                    $version = $xmlReader->getAttribute('ВерсияСхемы');
                    if (Tools::version_compare('2.05', $version, '<=')) {
                        $startTime = microtime(true);
                        if ($this->{$method}($xmlReader)) {
                            $stats =
                                "Peak memory: ".(memory_get_peak_usage(true) / 1024 / 1024)
                                ."MB Time: ".(microtime(true) - $startTime);
                            $this->success = "Импорт выполнен успешно ".$stats;
                        }
                        $xmlReader->close();
                    } else {
                        $this->error = "Версия ($version) схемы не поддерживается";
                    }
                }
            }
        } else {
            $this->error = "Импорт (type: $type) на данный момент не поддерживается";
        }
    }
    /**
     * @param $xmlReader XMLReader
     * @return bool
     */
    public function importCatalog($xmlReader)
    {
        // todo hook for price product, sync othercurrencyprice module
        // ?? при изменении каталога или другой сущности его GUID изменяется
        $map = array(
            'Группы' => 'categories',
            'Свойство' => 'feature',
            'Товар' => 'product'
        );
        try {
            while ($xmlReader->read()) {
                if ($xmlReader->nodeType != XMLReader::ELEMENT) {
                    continue;
                }
                $name = $xmlReader->localName;
                if (array_key_exists($name, $map)) {
                    $method = 'import'.Tools::ucfirst($map[$name]);
                    if (method_exists($this, $method)) {
                        $xmlSimple = new SimpleXMLElement($xmlReader->readOuterXml());
                        $this->{$method}($xmlSimple);
                    }
                    $xmlReader->next();
                }
            }
        } catch (Exception $e) {
            $this->error = "Импорт не удался из за ошибки : '{$e->getMessage()}'";
        }
        return empty($this->error);
    }

    /**
     * @param $xml SimpleXMLElement
     * @param $fields array
     */
    public function importCategories($xml, $fields = array())
    {
        $category = CategoryImportCML::getInstance();
        foreach ($xml->children() as $group) {
            $category->catchBall($group, $fields)->save();

            // add child category
            if (isset($group->Группы)) {
                $idParent = $category->id;
                $levelDepthParent = DB::getInstance()->getValue(
                    (new DbQuery())
                        ->select('level_depth')
                        ->from(Category::$definition['table'])
                        ->where(Category::$definition['primary'].'='.$idParent)
                );
                $this->importCategories(
                    $group->Группы,
                    array(
                        'id_parent' => $idParent,
                        'level_depth' => $levelDepthParent + 1,
                    )
                );
            }
        }
    }
    /**
     * @param $xml SimpleXMLElement
     */
    public function importFeature($xml)
    {
        FeatureImportCML::getInstance()->catchBall($xml);
    }
    /**
     * @param $xml SimpleXMLElement
     */
    public function importProduct($xml)
    {
        ProductImportCML::getInstance()->catchBall($xml);
    }

    public function checkParam()
    {
        // todo проверка версии схемы (ВерсияСхемы) &version=v

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

    public function cleanCache()
    {
        if (!$this->remove($this->uploadDir)) {
            $this->error = "Не могу очистить папку с кешем {$this->uploadDir}";
        }

        return empty($this->error);
    }
    public function remove($path)
    {
        if (file_exists($path)) {
            if (is_dir($path)) {
                $dir = dir($path);
                while (false !== ($fileName = $dir->read())) {
                    if ('.' != $fileName && '..' != $fileName) {
                        if (!$this->remove($dir->path.DIRECTORY_SEPARATOR.$fileName)) {
                            return false;
                        }
                    }
                }
                $dir->close();
                return @rmdir($path) || !file_exists($path);
            } else {
                return @unlink($path) || !file_exists($path);
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
            $dir = dirname($dir);
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
                $this->error = "Файл не был сохренен $fileName";
            } else {
                $this->success = "Файл загружен $fileName";
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

// CommerceML
abstract class ImportCML
{
    /** @var  ImportCML[] */
    protected static $instance = array();

    public static $idLangDefault;
    public static $table = WebserviceRequestCML::MODULE_NAME;
    public static $initialized = array();

    /**
     * @param bool $cache Флаг, хранить кеш? Cache::$local[$key] хранит только 1000 елементов, стоит хранить
     * только группы и производителя, так как товаров может быть свыше 1000, их хранение будет затирать кеш
     */
    public $cache = true;

    public $map = array();

    public $id;
    public $guid;
    public $xml;
    public static $targetClassName;
    /** @var  ObjectModel Содержит целевой класс PS для импорта */
    public $hashEntityCML;

    public $fields = array();

    protected function __construct()
    {
        self::$idLangDefault = (int) Configuration::get('PS_LANG_DEFAULT');
    }
    public static function getInstance()
    {
        if (!isset(static::$targetClassName)) {
            throw new ImportCMLException('Target classs is not set');
        }
        if (!isset(self::$instance[static::$targetClassName])) {
            self::$instance[static::$targetClassName] = new static();
        }

        return self::$instance[static::$targetClassName];
    }

    /**
     * @param SimpleXMLElement $xml
     * @param array $fields
     * @return $this
     */
    public function catchBall($xml, $fields = array())
    {
        $this->id = null;
        $this->guid = null;
        $this->xml = $xml;
        $this->fields = array();
        if (isset($xml->Ид)) {
            $this->guid = (string) $xml->Ид;
        }

        foreach ($this->map as $key => $val) {
            $this->fields[$key] = isset($xml->{$val}) ? (string) $xml->{$val} : '';
        }
        $this->fields = array_merge($this->fields, $fields);

        if (!in_array(static::$targetClassName, self::$initialized)) {
            $this->initDefaultObj();
            self::$initialized[] = static::$targetClassName;
        }
        return $this;
    }

    public static function getIdByGuid($guid, $cache = false)
    {
        if ($cache && Cache::isStored($guid)) {
            return Cache::retrieve($guid);
        }

        $id = Db::getInstance()->getValue(
            (new DbQuery())
                ->select('id')
                ->from(self::$table)
                ->where("guid = '$guid'")
        );
        $cache && $id && Cache::store($guid, $id);
        return $id;
    }

    public function initDefaultObj()
    {
        if (count($defaultFields = $this->getDefaultFields()) > 0) {
            new HackObjectModel($defaultFields, static::$targetClassName);
        }
    }

    public function getDefaultFields()
    {
        return array();
    }
    public function getCalcFields()
    {
        $linkRewrite = Tools::str2url($this->fields['name']);
        if (!Validate::isLinkRewrite($linkRewrite)) {
            $linkRewrite = 'friendly-url-auto-generation-failed';
//           $this->warnings[] = 'URL rewriting failed to auto-generate a friendly URL for: {$this->fields['name']}';
        }
        return array(
            'link_rewrite' => $linkRewrite,
        );
    }
    public function clearFields()
    {
        $this->fields = array_filter($this->fields, function ($key) {
            // fix "incorrect access to static class member"
            $targetClassName = static::$targetClassName;
            return array_key_exists($key, $targetClassName::$definition['fields']);
        }, ARRAY_FILTER_USE_KEY);
    }

    public function save()
    {
        if (!isset($this->fields) || !count($this->fields)) {
            throw new ImportCMLException('Сперва выполните инициализацию целевого объекта');
        }
        $this->fields = array_merge($this->fields, $this->getCalcFields());
        // Убрать случайно попавшие поля, предотвратив случайное обновление и неправльный хеш
        $this->clearFields();
        $this->hashEntityCML = md5(implode('', ksort($this->fields)));

        if ($this->id = $this->getIdByGuid($this->guid, $this->cache)) {
            if (!$this->needUpd()) {
                return true;
            }
            /** @var ObjectModel $targetClass */
            $targetClass = new static::$targetClassName($this->id);
            $defFields = $targetClass::$definition['fields'];
            $fieldsToUpdate = array();
            foreach ($this->fields as $key => $value) {
                if (isset($targetClass->{$key})) {
                    // field lang
                    if (is_array($targetClass->{$key})) {
                        if (isset($targetClass->{$key}[self::$idLangDefault])) {
                            if ($targetClass->{$key}[self::$idLangDefault] == $value) {
                                continue;
                            }
                        }
                    } elseif ($targetClass->{$key} == $value) {
                        continue;
                    }
                    $fieldsToUpdate[$key] = true;
                }
            }
            // Возможно хеш изменился из за изменений в алгоритме обработки свойств, но целевой обьект нет
            if (count($fieldsToUpdate) == 0) {
                return $this->updateEntityCML();
            }
            $targetClass->setFieldsToUpdate($fieldsToUpdate);
        } else {
            /** @var ObjectModel $targetClass */
            $targetClass = new static::$targetClassName();
        }
        // Установить id_lang, необходимо для правильной работы со свойтвами на нескольких языках
        $targetClass->hydrate($this->fields, self::$idLangDefault);
        if ($targetClass->save()) {
            if ($this->id) {
                return $this->updateEntityCML();
            } else {
                $this->id = $targetClass->id;
                return $this->addEntityCML();
            }
        } else {
            return false;
        }
    }

    public function updateEntityCML()
    {
        return DB::getInstance()->update(
            self::$table,
            array('hash' => $this->hashEntityCML), "guid = '{$this->guid}'"
        );
    }
    public function addEntityCML()
    {
        if (!isset($this->id)) {
            throw new ImportCMLException('Id is not set for entity CML');
        }
        $this->cache && Cache::store($this->guid, $this->id);
        return DB::getInstance()->insert(
            self::$table,
            array('id' => $this->id, 'guid' => $this->guid, 'hash' => $this->hashEntityCML),
            false,
            false
        );
    }

    public function needUpd()
    {
        return !Db::getInstance()->getValue(
            (new DbQuery())
                ->select('1')
                ->from(self::$table)
                ->where("guid = '{$this->guid}' AND hash = '{$this->hashEntityCML}'")
        );
    }
}

class CategoryImportCML extends ImportCML
{
    public static $targetClassName = 'Category';

    public $map = array(
        'name' => 'Наименование',
        'description' => 'Описание',
    );

    public function getDefaultFields()
    {
        $idParent = Configuration::get('PS_HOME_CATEGORY');
        $catParent = new Category($idParent);

        return array(
            'id_parent' => $idParent,
            'level_depth' => $catParent->level_depth + 1,
        );
    }
}

class ProductImportCML extends ImportCML
{
    public static $targetClassName = 'Product';

    public $cache = false;

    public $map = array(
        'name' => 'Наименование',
        'description' => 'Описание',
//        'description_short' => '',
    );

    public function getCalcFields()
    {
        $fields = array();

        return array_merge($fields, parent::getCalcFields());
    }
}

class FeatureImportCML extends ImportCML
{
    public static $targetClassName = 'Feature';

    public $map = array(
        'name' => 'Наименование',
    );
}

class ManufacturerImportCML extends ImportCML
{
    public static $targetClassName = 'Manufacturer';

    public $map = array(

    );
}

class HackObjectModel extends ObjectModel
{
    public function __construct($defaultValue, $className)
    {
        // init self::$loaded_classes[$className]
        !array_key_exists($className, self::$loaded_classes) && new $className();

        foreach ($defaultValue as $key => $value) {
            array_key_exists($key, self::$loaded_classes[$className])
            && self::$loaded_classes[$className][$key] = $value;
        }
    }
}

class ImportCMLException extends Exception
{
}
