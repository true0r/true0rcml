<?php

class FeatureImportCML extends ImportCML
{
    public $map = array(
        'name' => 'Наименование',
    );

    public function save()
    {
        if (!parent::save()) {
            return false;
        }
        /** @var $this->xml->ВариантыЗначений SimpleXMLElement */
        if (isset($this->xml->ВариантыЗначений)) {
            $fields = array('id_feature' => $this->entity->id_target);

            if (isset($this->xml->ТипЗначений) && (string) $this->xml->ТипЗначений == 'Справочник') {
                return self::walkChildren($this->xml->ВариантыЗначений, $fields);
            } else {
                /** @var $featureValue SimpleXMLElement */
                foreach ($this->xml->ВариантыЗначений->children() as $featureValue) {
                    $fields = array_merge($fields, array('value' => (string) $featureValue));
                    self::catchBall($featureValue->getName(), null, $fields);
                }
            }
        }
        return true;
    }
}
