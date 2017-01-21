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
        'Предложение' => array('className' => 'SpecificPrice', 'idClass' => 7, 'needWalk' => 1),
        'Цена' => array('className' => 'SpecificPrice', 'idClass' => 7, 'needWalk' => 0),
    );
    /**
     * @param bool $cache Флаг, хранить кеш? Cache::$local[$key] хранит только 1000 елементов, стоит хранить
     * только группы и производителя, так как товаров может быть свыше 1000, их хранение будет затирать кеш
     */
    public $cache = true;

    public $hash;

    public $targetClassName;
    public $targetIdClass;
    /** @var ObjectModel $targetClass */
    public $targetClass;
    public $idEntityCMLName = 'Ид';
    public $idEntityCML;
    public $map = array();
    public $fields = array();

    public $idLangDefault;
    public $countUpd = 0;
    public $countAdd = 0;

    /** @var SimpleXMLElement */
    public $xml;

    /** @var  EntityCML */
    public $entity;
    protected static $warning = array();

    protected function __construct()
    {
        $this->idLangDefault = (int) Configuration::get('PS_LANG_DEFAULT');
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
        $import->idEntityCML = null;
        $import->targetClass = null;
        $import->entity = null;
        $import->fields = array();

        if ($xml) {
            if (isset($import->idEntityCMLName)) {
                if (is_array($import->idEntityCMLName)) {
                    foreach ($import->idEntityCMLName as $id) {
                        $import->idEntityCML = isset($xml->{$id}) ? (string) $xml->{$id} : null;
                        if ($import->idEntityCML) {
                            break;
                        }
                    }
                } else {
                    $import->idEntityCML = isset($xml->{$import->idEntityCMLName})
                        ? (string) $xml->{$import->idEntityCMLName} : null;
                }
            }

            foreach ($import->map as $key => $val) {
                $import->fields[$key] = isset($xml->{$val}) ? (string) $xml->{$val} : '';
            }
        }
        $import->fields = array_merge($import->fields, $fields, $import->getCalcFields());
        // Убрать случайно попавшие поля, предотвратив случайное обновление и неправльный хеш
        $import->clearFields();
        $import->setHash();
        $import->setEntity();
        $import->save();

        return isset($import->entity) ? $import->entity->id_target : false;
    }

    public static function runPostImport()
    {
        foreach (self::$instance as $instance) {
            if (method_exists($instance, 'postImport')) {
                $instance::postImport();
            }
        }
    }

    public static function getStats($startTime = null)
    {
        $exclude = array('Справочник',  'Предложение');
        $stats = '';
        $countAllUpd = 0;
        $countAllAdd = 0;
        foreach (self::$mapTarget as $entity => $target) {
            if (isset(self::$instance[$target['className']]) && !in_array($entity, $exclude)) {
                $countUpd = self::$instance[$target['className']]->countUpd;
                $countAdd = self::$instance[$target['className']]->countAdd;
                if ($countUpd || $countAdd) {
                    $stats .= "$entity $countAdd/$countUpd, ";
                    $countAllUpd += $countUpd;
                    $countAllAdd += $countAdd;
                }
            }
        }
        $time = $startTime ? "Time: ".(int) (microtime(true) - $startTime)." s " : "";
        $stats .= "All $countAllAdd/$countAllUpd, Pm: ".(memory_get_peak_usage(true) / 1024 / 1024)." MB $time";
        return $stats;
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

    public function setHash()
    {
        ksort($this->fields);
        $this->hash = md5(implode('', $this->fields));
    }
    public function setEntity()
    {
        $this->entity = new EntityCML(EntityCML::getId($this->idEntityCML, $this->hash, $this->cache));
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
        if (!isset($this->fields) || !count($this->fields)) {
            throw new ImportCMLException('Сперва выполните инициализацию целевого объекта');
        }

        $entity = $this->entity;
        // add
        if (!$entity->id) {
            $this->countAdd++;
            /** @var ObjectModel $targetClass */
            $this->targetClass = $targetClass = new $this->targetClassName();
            // Установить id_lang, необходимо для правильной работы со свойтвами на нескольких языках
            $targetClass->hydrate($this->fields, $this->idLangDefault);
            $this->modTargetBeforeAdd();
            if (!$targetClass->add()) {
                return false;
            }
            $entity->id_target = $targetClass->id;
            $entity->target_class = $this->targetIdClass;
            $entity->guid = $this->idEntityCML;
            $entity->hash = $this->hash;
            return $entity->add();

        // update
        } elseif ($this->needUpd()) {
            $this->countUpd++;
            /** @var ObjectModel $targetClass */
            $this->targetClass = $targetClass = new $this->targetClassName($entity->id_target);
            $fieldsToUpdate = array();
            foreach ($this->fields as $key => $value) {
                // field lang
                if (!empty($targetClass::$definition['fields'][$key]['lang'])) {
                    if (!isset($targetClass->{$key}[$this->idLangDefault])
                        || $targetClass->{$key}[$this->idLangDefault] != $value) {
                        $fieldsToUpdate[$key][$this->idLangDefault] = true;
                    }
                } elseif ($targetClass->{$key} != $value) {
                    $fieldsToUpdate[$key] = true;
                }
            }
            // Возможно хеш изменился из за изменений в алгоритме обработки свойств, но целевой обьект нет
            if (count($fieldsToUpdate) > 0) {
                $targetClass->setFieldsToUpdate($fieldsToUpdate);
                $targetClass->hydrate($this->fields, $this->idLangDefault);
                // $targetClass->update_fields is protected
                $this->modTargetBeforeUpd($fieldsToUpdate);
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
        $status = true;
        /** @var SimpleXMLElement $child */
        foreach ($parent->children() as $child) {
            if (!self::catchBall($child->getName(), $child, $fields)) {
                $status = false;
            }
        }
        return $status;
    }

    public function modTargetBeforeAdd()
    {
    }
    public function modTargetBeforeUpd($fieldsToUpdate)
    {
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

    public static function getWarning()
    {
        return self::$warning ? ' Warning: '.implode(', ', self::$warning).';' : '';
    }
    public static function setWarning($warning)
    {
        if (!in_array($warning, self::$warning)) {
            self::$warning[] = $warning;
        }
    }
}
