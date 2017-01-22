<?php

class ManufacturerImportCML extends ImportCML
{
    public $idEntityCMLName = null;

    public function save()
    {
        if (!($status = parent::save())) {
            self::setWarning("ТорговаяМарка '{$this->fields['name']}' не сохранена");
        }
        return $status;
    }

    public function getDefaultFields()
    {
        // По умолчанию производитель не активирован
        return array_merge(parent::getDefaultFields(), array('active' => true));
    }
}
