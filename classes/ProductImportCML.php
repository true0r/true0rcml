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
            if ($this->entity->id_target) {
                if ($this->targetExists()) {
                    (new Product($this->entity->id_target))->delete();
                }
                $this->entity->delete();
            }
            return true;
        }
//        return parent::save();
        return true;
    }

    public function getCalcFields()
    {
        $fields = array();


        if (isset($this->xml->ТорговаяМарка)) {
            $fields['id_manufacturer']  = self::catchBall(
                $this->xml->ТорговаяМарка->getName(),
                null,
                array('name' => (string) $this->xml->ТорговаяМарка)
            );
        }

        if (isset($this->xml->Штрихкод)) {
            $upcOrEan13 = (string) $this->xml->Штрихкод;
            $fields[Tools::strlen($upcOrEan13) == 13 ? 'ean13' : 'upc'] = $upcOrEan13;
        }

        return array_merge($fields, parent::getCalcFields());
    }
}
