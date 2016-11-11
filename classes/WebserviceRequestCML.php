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
        $methodName = 'import'.Tools::ucfirst($type);

        if (method_exists($this, $methodName)) {
            $path = $this->uploadDir.$this->param['filename'];
            libxml_use_internal_errors(true);

            $xml = simplexml_load_file($path);
            if (!$xml) {
                $this->error = "Ошибка загрузки XML";
                foreach (libxml_get_errors() as $error) {
                    $this->error .= ' '.$error->message;
                }
            } else {
                $version = (string) $xml['ВерсияСхемы'];
                if (Tools::version_compare('2.05', $version, '<=')) {
                    $this->$methodName($xml) && $this->success = "Импорт выполнен успешно";
                } else {
                    $this->error = "Версия ($version) схемы не поддерживается";
                }
            }
        } else {
            $this->error = "Импорт (type: $type) на данный момент не поддерживается";
        }
    }
    /**
     * @param $xml SimpleXMLElement
     * @return bool
     */
    public function importCatalog($xml)
    {
        // todo hook for price product, sync othercurrencyprice module
        // ?? при изменении каталога или другой сущности его GUID изменяется
        try {
            if (isset($xml->Классификатор) && isset($xml->Классификатор->Группы)) {
                $this->importCategory($xml->Классификатор->Группы);
            }
        } catch (Exception $e) {
            $this->error = "Импорт не удался из за ошибки : '{$e->getMessage()}'";
        }
        return empty($this->error);
    }

    /**
     * @param $groups SimpleXMLElement
     * @param $property array
     */
    public function importCategory($groups, $property = array())
    {
        /** @var ImportCML $category */
        static $category;

        if (!isset($category)) {
            $category = new CategoryImportCML();
        }

        foreach ($groups->children() as $group) {
            $category->catchBall($group, $property)->save();

            // add child category
            if (isset($group->Группы)) {
                $idParent = $category->id;
                $levelDepthParent = DB::getInstance()->getValue(
                    (new DbQuery())
                        ->select('level_depth')
                        ->from(Category::$definition['table'])
                        ->where(Category::$definition['primary'].'='.$idParent)
                );
                $this->importCategory(
                    $group->Группы,
                    array(
                        'id_parent' => $idParent,
                        'level_depth' => $levelDepthParent + 1,
                    )
                );
            }
        }
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
    public static $table = WebserviceRequestCML::MODULE_NAME;
    public static $initialized = array();

    /**
     * @param bool $cache Флаг, хранить кеш? Cache::$local[$key] хранит только 1000 елементов, стоит хранить
     * только группы и производителя, так как товаров может быть свыше 1000, их хранение будет затирать кеш
     */
    public $cache = true;

    public $map = array();
    public $mapCommon = array(
        'guid' => 'Ид',
    );

    public $id;
    public $targetClassName;
    /** @var  ObjectModel Содержит целевой класс PS для импорта */
    public $hashEntityCML;

    public $property = array();

    /**
     * @param SimpleXMLElement $xml
     * @param array $property
     * @return $this
     */
    public function catchBall($xml, $property = array())
    {
        $this->id = null;
        $this->property = array();

        $map = array_merge($this->map, $this->mapCommon);
        foreach ($map as $key => $val) {
            $this->property[$key] = isset($xml->{$val}) ? (string) $xml->{$val} : '';
        }
        $this->property = array_merge($this->property, $property);
        $this->hashEntityCML = md5(implode('', $this->property));

        if (!in_array($this->targetClassName, self::$initialized)) {
            $this->initDefaultObj();
            self::$initialized[] = $this->targetClassName;
        }
        return $this;
    }

    public function getCalcProperty()
    {
        $linkRewrite = Tools::str2url($this->property['name']);
        if (!Validate::isLinkRewrite($linkRewrite)) {
            $linkRewrite = 'friendly-url-autogeneration-failed';
//           $this->warnings[] = 'URL rewriting failed to auto-generate a friendly URL for: {$this->property['name']}';
        }
        return array(
            'link_rewrite' => $linkRewrite,
        );
    }

    public static function getId($guid, $cache = false)
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
        new HackObjectModel($this->getDefaultValues(), $this->targetClassName);
    }
    abstract public function getDefaultValues();

    public function save()
    {
        static $idLangDefault;

        if (!isset($idLangDefault)) {
            $idLangDefault = (int) Configuration::get('PS_LANG_DEFAULT');
        }
        if (!isset($this->property)) {
            throw new ImportCMLException('Сперва выполните инициализацию целевого объекта');
        }
        $this->property = array_merge($this->property, $this->getCalcProperty());

        if ($this->id = $this->getId($this->property['guid'], $this->cache)) {
            if (!$this->needUpd()) {
                return true;
            }
            /** @var ObjectModel $targetClass */
            $targetClass = new $this->targetClassName($this->id);
            $defFields = $targetClass::$definition['fields'];
            $fieldsToUpdate = array();
            foreach ($this->property as $key => $value) {
                if (array_key_exists($key, $defFields)) {
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
                    }
                    $fieldsToUpdate[$key] = true;
                }
            }
            $targetClass->setFieldsToUpdate($fieldsToUpdate);
        } else {
            /** @var ObjectModel $targetClass */
            $targetClass = new $this->targetClassName();
        }
        // Установить id_lang, необходимо для правильной работы со свойтвами на нескольких языках
        $targetClass->hydrate($this->property, $idLangDefault);
        if ($targetClass->save()) {
            if ($this->id) {
                $this->updateEntityCML();
            } else {
                $this->id = $targetClass->id;
                $this->addEntityCML();
            }
            return true;
        } else {
            return false;
        }
    }

    public function updateEntityCML()
    {
        return DB::getInstance()->update(
            self::$table,
            array('hash' => $this->hashEntityCML), "guid = '{$this->property['guid']}'"
        );
    }
    public function addEntityCML()
    {
        if (!isset($this->id)) {
            throw new ImportCMLException('Id is not set for entity CML');
        }
        $this->cache && Cache::store($this->property['guid'], $this->id);
        return DB::getInstance()->insert(
            self::$table,
            array('id' => $this->id, 'guid' => $this->property['guid'], 'hash' => $this->hashEntityCML),
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
                ->where("guid = '{$this->property['guid']}' AND hash = '{$this->hashEntityCML}'")
        );
    }
}

class CategoryImportCML extends ImportCML
{
    public $targetClassName = 'Category';

    public $map = array(
        'name' => 'Наименование',
        'description' => 'Описание',
    );

    public function getDefaultValues()
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
    public $targetClassName = 'Product';
    public $cache = false;

    public $map = array(

    );

    public function getDefaultValues()
    {
        // TODO: Implement getDefaultValues() method.
    }
}

class ManufacturerImportCML extends ImportCML
{
    public $targetClassName = 'Manufacturer';

    public $map = array(

    );

    public function getDefaultValues()
    {
        // TODO: Implement getDefaultValues() method.
    }
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
