<?php

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
    public $idElementName = 'Ид';
    public $fields = array();
    public $map = array();
    public static $mapTargetClassName = array(
        'Группа' => 'Category',
        'Свойство' => 'Feature',
        'Справочник' => 'FeatureValue',
        'Значение' => 'FeatureValue',
        'Товар' => 'Product',
//        'Производитель' => 'Manufacturer',
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

        if ($xml) {
            if (isset($xml->{$import->idElementName})) {
                $import->guid = (string) $xml->{$import->idElementName};
            }

            foreach ($import->map as $key => $val) {
                $import->fields[$key] = isset($xml->{$val}) ? (string) $xml->{$val} : '';
            }
        }
        $import->fields = array_merge($import->fields, $fields);

        return $import->save();
    }

    public static function getId($guid, $hash = null, $cache = false)
    {
        if (!$guid && !$hash) {
            throw new ImportCMLException('Значиние guid или hash должно быть задано');
        }
        $cacheId = $guid.$hash;
        $where = $guid ? "guid = '$guid'" : '';
        $where .= $guid && $hash ? ' AND ' : '';
        $where .= $hash ? "hash = '$hash'" : '';

        if ($cache && Cache::isStored($cacheId)) {
            return Cache::retrieve($cacheId);
        }

        $id = Db::getInstance()->getValue(
            (new DbQuery())
                ->select('id')
                ->from(self::$table)
                ->where($where)
        );
        $cache && $id && Cache::store($cacheId, $id);
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

        // update
        if ($this->guid && $this->id = $this->getId($this->guid, null, $this->cache)) {
            if (!$this->needUpd()) {
                return true;
            }
            /** @var ObjectModel $targetClass */
            $targetClass = new $this->targetClassName($this->id);
            $fieldsToUpdate = array();
            foreach ($this->fields as $key => $value) {
                // field lang
                if (!empty($targetClass::$definition['fields'][$key]['lang'])) {
                    if (!isset($targetClass->{$key}[$idLangDefault])
                        || $targetClass->{$key}[$idLangDefault] != $value) {
                        $fieldsToUpdate[$key][$idLangDefault] = true;
                    }
                } elseif ($targetClass->{$key} != $value) {
                    $fieldsToUpdate[$key] = true;
                }
            }
            // Возможно хеш изменился из за изменений в алгоритме обработки свойств, но целевой обьект нет
            if (count($fieldsToUpdate) == 0) {
                return $this->updateEntityCML();
            }
            $targetClass->setFieldsToUpdate($fieldsToUpdate);

        // Возможно сущность (без guid, идентификация на базе md5) уже сущетвует, обновление не доступны для этого типа
        } elseif (self::getId(null, $this->hashEntityCML, $this->cache)) {
            return true;
        // add
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
        $this->cache && $this->guid && Cache::store($this->guid, $this->id);
        return DB::getInstance()->insert(
            self::$table,
            array('id' => $this->id, 'guid' => $this->guid, 'hash' => $this->hashEntityCML),
            false,
            false
        );
    }

    public function needUpd()
    {
        return !self::getId($this->guid, $this->hashEntityCML);
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
    /**
     * @param SimpleXMLElement $xml
     * @param string $name
     *
     * @return bool|string
     */
    public static function getXmlElemAttrValue($xml, $name)
    {
        /** @var SimpleXMLElement $attr */
        foreach ($xml->attributes() as $attrName => $value) {
            if ($attrName == $name) {
                return $value;
            }
        }
        return false;
    }
}
