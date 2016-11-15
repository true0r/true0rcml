<?php

class ImportCML
{
    public static $mapTargetClassName = array(
        'Группа' => 'Category',
        'Свойство' => 'Feature',
        'Справочник' => 'FeatureValue',
        'Значение' => 'FeatureValue',
        'Товар' => 'Product',
//        'Производитель' => 'Manufacturer',
    );
    /**
     * @param bool $cache Флаг, хранить кеш? Cache::$local[$key] хранит только 1000 елементов, стоит хранить
     * только группы и производителя, так как товаров может быть свыше 1000, их хранение будет затирать кеш
     */
    public $cache = true;

    public $hash;

    public $targetClassName;
    public $idElementName = 'Ид';
    public $guid;
    public $map = array();
    public $fields = array();


    /** @var SimpleXMLElement */
    public $xml;

    /** @var  EntityCML */
    public $entity;

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
        $import->xml = $xml;
        $import->guid = null;
        $import->entity = null;
        $import->fields = array();

        if ($xml) {
            if (isset($xml->{$import->idElementName})) {
                $import->guid = (string) $xml->{$import->idElementName};
            }

            foreach ($import->map as $key => $val) {
                $import->fields[$key] = isset($xml->{$val}) ? (string) $xml->{$val} : '';
            }
        }
        $import->fields = array_merge($import->fields, $fields, $import->getCalcFields());
        // Убрать случайно попавшие поля, предотвратив случайное обновление и неправльный хеш
        $import->clearFields();
        ksort($import->fields);
        $import->hash = md5(implode('', $import->fields));
        $import->entity = new EntityCML(EntityCML::getId($import->guid, $import->hash, $import->cache));

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

        $entity = $this->entity;
        /** @var ObjectModel $targetClass */
        $targetClass = null;

        // add target and entityCML
        if (!$entity->id) {
            $targetClass = new $this->targetClassName();
            // Установить id_lang, необходимо для правильной работы со свойтвами на нескольких языках
            $targetClass->hydrate($this->fields, $idLangDefault);
            if ($targetClass->add()) {
                $entity->id_target = $targetClass->id;
                $entity->guid = $this->guid;
                $entity->hash = $this->hash;
                return $entity->add();
            } else {
                return false;
            }
        }
        $targetExists = $this->targetExists();
        // update Возможно сущность (без guid, идентификация на базе md5) уже сущетвует,
        // обновление не доступны для этого типа. При редактировании сущности в ERP связь с EntityCML будет утеряна,
        // запись останется в бд как мусор (необходимо найти способ удалить его)
        if ($targetExists && $this->guid) {
            if (!$this->needUpd()) {
                return true;
            }
            $targetClass = new $this->targetClassName($entity->id_target);
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
            $entity->hash = $this->hash;
            $entity->setFieldsToUpdate(array('hash' => true));
            return $entity->update();

        // recovery Восстановить объект если был удален в магазине
        } elseif (!$targetExists) {
            $targetClass = new $this->targetClassName();
            $targetClass->hydrate($this->fields, $idLangDefault);
            if (!$targetClass->add()) {
                return false;
            }
            $entity->id_target = $targetClass->id;
            $entity->setFieldsToUpdate(array('id_target' => true));
            return $entity->update();
        }

        return true;
    }

    public function needUpd()
    {
        return $this->hash != $this->entity->hash;
    }

    public function targetExists()
    {
        if (!$this->entity->id_target) {
            throw new Exception('Id target is not set');
        }
        $targetClass = $this->targetClassName;
        return (bool) Db::getInstance()->getValue(
            (new DbQuery())
                ->select('COUNT(*)')
                ->from($targetClass::$definition['table'])
                ->where($targetClass::$definition['primary']." = '{$this->entity->id_target}'")
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
