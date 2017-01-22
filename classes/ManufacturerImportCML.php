<?php

class ManufacturerImportCML extends ImportCML
{
    public $idEntityCMLName = null;

    public function save()
    {
        if (!($status = parent::save())) {
            self::setWarning("ТорговаяМарка '%s' не сохранен(а|ы)", $this->fields['name']);
        }
        return $status;
    }

    public function getDefaultFields()
    {
        // По умолчанию производитель не активирован
        return array_merge(parent::getDefaultFields(), array('active' => true));
    }
}
