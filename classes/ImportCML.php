<?php

class ImportCML
{
    /**
     * @param bool $cache Флаг, хранить кеш? Cache::$local[$key] хранит только 1000 елементов, стоит хранить
     * только группы и производителя, так как товаров может быть свыше 1000, их хранение будет затирать кеш
     */
    public $cache = true;
    public $hash;

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

    public $idTarget;
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

        $targetClassName = self::$mapTargetClassName[$entityCMLName];
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
        $import->idTarget = null;
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
        $this->hash = md5(implode('', $this->fields));

        $this->setIdTarget();

        /** @var ObjectModel $targetClass */
        $targetClass = null;

        // add
        if (!$this->idTarget) {
            $targetClass = new $this->targetClassName();
            // Установить id_lang, необходимо для правильной работы со свойтвами на нескольких языках
            $targetClass->hydrate($this->fields, $idLangDefault);
            if ($targetClass->add()) {
                $this->idTarget = $targetClass->id;
                $entityCML = new EntityCML();
                $entityCML->guid = $this->guid;
                $entityCML->hash = $this->hash;
                $entityCML->id_target = $this->idTarget;
                return $entityCML->add();
            } else {
                return false;
            }

        // update Возможно сущность (без guid, идентификация на базе md5) уже сущетвует,
        // обновление не доступны для этого типа
        } elseif ($this->guid && $targetExists = $this->targetExists()) {
            if (!$this->needUpd()) {
                return true;
            }
            $targetClass = new $this->targetClassName($this->idTarget);
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
            if (count($fieldsToUpdate) > 0) {
                $targetClass->setFieldsToUpdate($fieldsToUpdate);
                $targetClass->hydrate($this->fields, $idLangDefault);
                if (!$targetClass->update()) {
                    return false;
                }
            }
            return EntityCML::updHash($this->guid, $this->hash);

        // recovery Восстановить объект если был удален в магазине
        } elseif (!$targetExists) {
            $targetClass = new $this->targetClassName();
            $targetClass->hydrate($this->fields, $idLangDefault);
            return $targetClass->add();
        }

        return true;
    }

    public function needUpd()
    {
        return !EntityCML::getIdTarget($this->guid, $this->hash);
    }

    public function targetExists()
    {
        $this->setIdTarget();
        if (!$this->idTarget) {
            return false;
        }
        $targetClass = $this->targetClassName;
        return (bool) Db::getInstance()->getValue(
            (new DbQuery())
                ->select('COUNT(*)')
                ->from($targetClass::$definition['table'])
                ->where($targetClass::$definition['table']." = '{$this->idTarget}'")
        );
    }

    public function setIdTarget()
    {
        if (!$this->idTarget) {
            $this->idTarget = $this->guid ? EntityCML::getIdTarget($this->guid, null, $this->cache) :
                EntityCML::getIdTarget(null, $this->hash, $this->cache);
        }
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
