<?php

// todo hook for price product, sync othercurrencyprice module
// ?? при изменении каталога или другой сущности его GUID изменяется

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
        libxml_use_internal_errors(true);

        $xmlReader = new XMLReader();

        if (!$xmlReader->open($this->uploadDir.$this->param['filename'])) {
            $this->error = "Ошибка загрузки XML";
            foreach (libxml_get_errors() as $error) {
                $this->error .= ' ' . $error->message;
            }
        } elseif (!$xmlReader->next('КоммерческаяИнформация')) {
            $this->error = "XML файл не имеет узла 'КоммерческаяИнформация', что не соответствует CommerceML 2";
        } elseif (!Tools::version_compare('2.05', $version = $xmlReader->getAttribute('ВерсияСхемы'), '<=')) {
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
class ImportCML
{
    public static $table = WebserviceRequestCML::MODULE_NAME;
    /**
     * @param bool $cache Флаг, хранить кеш? Cache::$local[$key] хранит только 1000 елементов, стоит хранить
     * только группы и производителя, так как товаров может быть свыше 1000, их хранение будет затирать кеш
     */
    public $cache = true;
    public $hashEntityCML;

    public $targetClassName;
    public $fields = array();
    public $map = array();
    public static $mapTargetClassName = array(
        'Группа' => 'Category',
        'Свойство' => 'Feature',
//       'Товар' => 'product'

    );

    public $id;
    public $guid;
    /** @var SimpleXMLElement */
    public $xml;

    protected function __construct()
    {
    }

    public static function getInstance($entityCMLName)
    {
        /** @var  ImportCML[] */
        static $instance = array();

        $targetClassName = ImportCML::$mapTargetClassName[$entityCMLName];
        if (!isset($instance[$targetClassName])) {
            $importClassName = $targetClassName.__CLASS__;
            if (!class_exists($importClassName)) {
                throw new ImportCMLException("Class $importClassName is not exists");
            }
            /** @var ImportCML $import */
            $import = new $importClassName();
            $import->targetClassName = $targetClassName;
            $instance[$targetClassName] = $import;
            if (count($defaultFields = $import->getDefaultFields()) > 0) {
                new HackObjectModel($defaultFields, $targetClassName);
            }
        }

        return $instance[$targetClassName];
    }

    /**
     * @param string
     * @param SimpleXMLElement $xml
     * @param array $fields
     * @return bool
     */
    public static function catchBall($entityCMLName, $xml, $fields = array())
    {
        /** @var ImportCML $import */
        $import = self::getInstance($entityCMLName);
        $import->id = null;
        $import->guid = null;
        $import->xml = $xml;
        $import->fields = array();
        if (isset($xml->Ид)) {
            $import->guid = (string) $xml->Ид;
        }

        foreach ($import->map as $key => $val) {
            $import->fields[$key] = isset($xml->{$val}) ? (string) $xml->{$val} : '';
        }
        $import->fields = array_merge($import->fields, $fields);

        if ($import->save()) {
            if (method_exists($import, 'beforeSave')) {
                return $import->beforeSave();
            }
            return true;
        }
        return false;
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

    public function getDefaultFields()
    {
        return array();
    }
    public function getCalcFields()
    {
        $fields = array();
        if ($this->hasDefinitionField('link_rewrite')) {
            $linkRewrite = Tools::str2url($this->fields['name']);
            if (!Validate::isLinkRewrite($linkRewrite)) {
                $linkRewrite = 'friendly-url-auto-generation-failed';
//           $this->warnings[] = 'URL rewriting failed to auto-generate a friendly URL for: {$this->fields['name']}';
            }
            $fields['link_rewrite'] = $linkRewrite;
        }
        return $fields;
    }
    public function clearFields()
    {
        $this->fields = array_filter($this->fields, function ($key) {
            return $this->hasDefinitionField($key);
        }, ARRAY_FILTER_USE_KEY);
    }

    public function hasDefinitionField($field)
    {
        static $definition;

        if (!isset($definition)) {
            // fix "incorrect access to static class member"
            $targetClassName = $this->targetClassName;
            $definition = $targetClassName::$definition;
        }

        return array_key_exists($field, $definition['fields']);
    }

    public function save()
    {
        static $idLangDefault;

        if (!isset($this->fields) || !count($this->fields)) {
            throw new ImportCMLException('Сперва выполните инициализацию целевого объекта');
        }

        if (!isset($idLangDefault)) {
            $idLangDefault = (int) Configuration::get('PS_LANG_DEFAULT');
        }
        $this->fields = array_merge($this->fields, $this->getCalcFields());
        // Убрать случайно попавшие поля, предотвратив случайное обновление и неправльный хеш
        $this->clearFields();
        ksort($this->fields);
        $this->hashEntityCML = md5(implode('', $this->fields));

        if ($this->id = $this->getIdByGuid($this->guid, $this->cache)) {
            if (!$this->needUpd()) {
                return true;
            }
            /** @var ObjectModel $targetClass */
            $targetClass = new $this->targetClassName($this->id);
            $fieldsToUpdate = array();
            foreach ($this->fields as $key => $value) {
                if (isset($targetClass->{$key})) {
                    // field lang
                    if (is_array($targetClass->{$key})) {
                        if (isset($targetClass->{$key}[$idLangDefault])) {
                            if ($targetClass->{$key}[$idLangDefault] == $value) {
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
            $targetClass = new $this->targetClassName();
        }
        // Установить id_lang, необходимо для правильной работы со свойтвами на нескольких языках
        $targetClass->hydrate($this->fields, $idLangDefault);
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

    /**
     * @param SimpleXMLElement $parent
     * @param array $fields
     *
     * @return bool
     */
    public static function walkChildren($parent, $fields = array())
    {
        /** @var SimpleXMLElement $child */
        foreach ($parent->children() as $child) {
            if (!self::catchBall($child->getName(), $child, $fields)) {
                return false;
            }
        }
        return true;
    }
}

class CategoryImportCML extends ImportCML
{
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

    public function beforeSave()
    {
        // Добавления здесь свойств необходимо для поддержания рекурсии и избежания повторного обхода групп
        if (isset($this->xml->Свойства) && !ImportCML::walkChildren($this->xml->Свойства)) {
            return false;
        }
        if (!isset($this->xml->Группы)) {
            return true;
        }
        // add child category
        $levelDepthParent = DB::getInstance()->getValue(
            (new DbQuery())
                ->select('level_depth')
                ->from(Category::$definition['table'])
                ->where(Category::$definition['primary'].'='.$this->id)
        );
        $fields = array(
            'id_parent' => $this->id,
            'level_depth' => $levelDepthParent + 1,
        );
        return ImportCML::walkChildren($this->xml->Группы, $fields);
    }
}

class ProductImportCML extends ImportCML
{
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
    public $map = array(
        'name' => 'Наименование',
    );

    public function beforeSave()
    {
        // todo save feature value
        return true;
    }
}

class HackObjectModel extends ObjectModel
{
    public function __construct($defaultFields, $className)
    {
        // init self::$loaded_classes[$className]
        !array_key_exists($className, self::$loaded_classes) && new $className();

        foreach ($defaultFields as $key => $value) {
            array_key_exists($key, self::$loaded_classes[$className])
            && self::$loaded_classes[$className][$key] = $value;
        }
    }
}

class ImportCMLException extends Exception
{
}
