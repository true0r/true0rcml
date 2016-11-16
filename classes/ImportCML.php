<?php

class ImportCML
{
    /** @var  ImportCML[] */
    private static $instance = array();
    public static $mapTarget = array(
        'Группа' => array('className' => 'Category', 'idClass' => 1, 'needWalk' => 1),
        'Товар' => array('className' => 'Product', 'idClass' => 2, 'needWalk' => 1),
        'Свойство' => array('className' => 'Feature', 'idClass' => 3, 'needWalk' => 1),
        'Справочник' => array('className' => 'FeatureValue', 'idClass' => 4, 'needWalk' => 0),
        'Значение' => array('className' => 'FeatureValue', 'idClass' => 4, 'needWalk' => 0),
        'ТорговаяМарка' => array('className' => 'Manufacturer', 'idClass' => 5, 'needWalk' => 0),
        'Картинка' => array('className' => 'Image', 'idClass' => 6, 'needWalk' => 0),
    );
    /**
     * @param bool $cache Флаг, хранить кеш? Cache::$local[$key] хранит только 1000 елементов, стоит хранить
     * только группы и производителя, так как товаров может быть свыше 1000, их хранение будет затирать кеш
     */
    public $cache = true;

    public $hash;

    public $targetClassName;
    public $targetIdClass;
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
        if (!isset(self::$mapTarget[$entityCMLName])) {
            throw new ImportCMLException("Сущность CML '$entityCMLName' не имеет ассоциации с целевым классом");
        }
        $targetClassName = self::$mapTarget[$entityCMLName]['className'];
        if (!isset(self::$instance[$targetClassName])) {
            $importClassName = $targetClassName.__CLASS__;
            if (!class_exists($importClassName)) {
                throw new ImportCMLException("Class $importClassName is not exists");
            }
            /** @var ImportCML $import */
            $import = new $importClassName();
            $import->targetClassName = $targetClassName;
            $import->targetIdClass = self::$mapTarget[$entityCMLName]['idClass'];
            self::$instance[$targetClassName] = $import;

            if (count($defaultFields = $import->getDefaultFields()) > 0) {
                new HackObjectModel($defaultFields, $targetClassName);
            }

            // Удалить сущность CML если целевой объект удален в магазине
            $primary = EntityCML::$definition['primary'];
            $table = EntityCML::$definition['table'];
            $targetDef = $targetClassName::$definition;
            Db::getInstance()->delete(
                $table,
                "$primary IN (
                    SELECT tmp.$primary FROM (
                        SELECT $primary FROM "._DB_PREFIX_."$table
                        WHERE target_class = {$import->targetIdClass}
                            AND id_target NOT IN (
                                SELECT {$targetDef['primary']} FROM "._DB_PREFIX_.$targetDef['table']."
                            )
                    ) as tmp
                )"
            );
        }

        return self::$instance[$targetClassName];
    }

    /**
     * @param string
     * @param SimpleXMLElement $xml
     * @param array $fields
     * @return int
     * @throws ImportCMLException
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
            if (isset($import->idElementName)) {
                if (is_array($import->idElementName)) {
                    foreach ($import->idElementName as $id) {
                        $import->guid = isset($xml->{$id}) ? (string) $xml->{$id} : null;
                        if ($import->guid) {
                            break;
                        }
                    }
                } else {
                    $import->guid = isset($xml->{$import->idElementName})
                        ? (string) $xml->{$import->idElementName} : null;
                }
            }

            foreach ($import->map as $key => $val) {
                $import->fields[$key] = isset($xml->{$val}) ? (string) $xml->{$val} : '';
            }
        }
        $import->fields = array_merge($import->fields, $fields, $import->getCalcFields());
        // Убрать случайно попавшие поля, предотвратив случайное обновление и неправльный хеш
        $import->clearFields();
        ksort($import->fields);
        $import->hash = $import->getHash();
        $import->entity = new EntityCML(EntityCML::getId($import->guid, $import->hash, $import->cache));

        if (!$import->save()) {
            throw new ImportCMLException("Ошибка сохранения {$import->targetClassName}");
        }
        return $import->entity->id_target;
    }

    public function getHash()
    {
        return md5(implode('', $this->fields));
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
        // add
        if (!$entity->id) {
            /** @var ObjectModel $targetClass */
            $targetClass = new $this->targetClassName();
            // Установить id_lang, необходимо для правильной работы со свойтвами на нескольких языках
            $targetClass->hydrate($this->fields, $idLangDefault);
            if (!$targetClass->add()) {
                return false;
            }
            $entity->id_target = $targetClass->id;
            $entity->target_class = $this->targetIdClass;
            $entity->guid = $this->guid;
            $entity->hash = $this->hash;
            return $entity->add();

        // update
        } elseif ($this->needUpd()) {
            /** @var ObjectModel $targetClass */
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
        }
        return true;
    }

    public function needUpd()
    {
        return $this->hash != $this->entity->hash;
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
