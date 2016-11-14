<?php

class ProductImportCML extends ImportCML
{
    public $cache = false;

    public $map = array(
        'reference' => 'Артикул',

        'name' => 'Наименование',
        'description' => 'Описание',
//        'description_short' => '',
    );

    public function __construct()
    {
        $param = true;
        $this->map['reference'] = $param ? 'Артикул' : 'Штрихкод';
        parent::__construct();
    }

    public function save()
    {
        // Удалить обьект если он был удален в ERP
        if (self::getXmlElemAttrValue($this->xml, 'СтатусТип') == 'Удален') {
            // EntityCML будет удален c помощью хука
            return (new Product(self::getId($this->guid)))->delete();
        }
//        return parent::save();
        return true;
    }

    public function getCalcFields()
    {
        $fields = array();

        if (isset($this->xml->Штрихкод)) {
            $upcOrEan13 = (string) $this->xml->Штрихкод;
            $fields[Tools::strlen($upcOrEan13) == 13 ? 'ean13' : 'upc'] = $upcOrEan13;
        }



        return array_merge($fields, parent::getCalcFields());
    }
}
