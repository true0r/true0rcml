<?php

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

    public function save()
    {
        if (!parent::save()) {
            return false;
        }
        // Добавления здесь свойств необходимо для поддержания рекурсии и избежания повторного обхода групп
        if (isset($this->xml->Свойства) && !self::walkChildren($this->xml->Свойства)) {
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
                ->where(Category::$definition['primary'].'='.$this->entity->id_target)
        );
        $fields = array(
            'id_parent' => $this->entity->id_target,
            'level_depth' => $levelDepthParent + 1,
        );
        return self::walkChildren($this->xml->Группы, $fields);
    }

    public function modTargetClass()
    {
        static $groupBox = array();

        if (empty($groupBox)) {
            $groupBox = array();
            $groups = Group::getGroups($this->idLangDefault);
            foreach ($groups as $group) {
                $groupBox[] = $group['id_group'];
            }
        }

        if (!$this->targetClass->id) {
            $this->targetClass->groupBox = $groupBox;
        }
    }
}
