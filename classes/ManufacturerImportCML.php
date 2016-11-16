<?php

class ManufacturerImportCML extends ImportCML
{
    public $idEntityCMLName = null;

    public function getDefaultFields()
    {
        // По умолчанию производитель не активирован
        return array_merge(parent::getDefaultFields(), array('active' => true));
    }
}
