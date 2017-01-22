<?php

class FeatureValueImportCML extends ImportCML
{
    // ! Не использовать Значение как idCMLName, так как схема XML Товар > ЗначенияСвойства > (не определяет ИдЗначения)
    // в отличии от Свойство > Справочник > (определено ИдЗначения). Если Значение одинаковы, тогда хеш будет
    // связкой между ЗначенияСвойства и Справочник.
    public $idEntityCMLName = 'ИдЗначения';
    public $map = array(
        'value' => 'Значение',
    );

    public function save()
    {
        if (!($status = parent::save())) {
            self::setWarning("Значени(е|я) '%s' не сохранен(о|ы)", $this->fields['value']);
        }
        return $status;
    }

    public static function getIdFeatureValue($guid, $value)
    {
        $cacheId = $guid.$value;
        if (Cache::isStored($cacheId)) {
            return Cache::retrieve($cacheId);
        }

        if (!$idFeatureValue = EntityCML::getIdTarget($value, null, true)) {
            if ((!$idFeature = EntityCML::getIdTarget($guid, null, true))) {
                self::setWarning("Свойств(о|a) товара c guid '%s' не существу(ет|ют)", $guid);
                return false;
            }

            $fields = array('id_feature' => $idFeature, 'value' => $value);
            if (!$idFeatureValue = self::catchBall('Значение', null, $fields)) {
                return false;
            }
        }

        Cache::store($cacheId, $idFeatureValue);
        return $idFeatureValue;
    }
}
