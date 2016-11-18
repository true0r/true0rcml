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

    public static function getIdFeatureValue($guid, $value)
    {
        $cacheId = $guid.$value;
        if (Cache::isStored($cacheId)) {
            return Cache::retrieve($cacheId);
        }

        if ((!$idFeature = EntityCML::getIdTarget($guid, null, true))) {
            throw new ImportCMLException('Свойство товара не существует');
        }

        $fields = array('id_feature' => $idFeature, 'value' => $value);
        if (!$idFeatureValue = self::catchBall('Значение', null, $fields)) {
            throw new ImportCMLException('Значение свойства не было создано');
        }
        Cache::store($cacheId, $idFeatureValue);
        return $idFeatureValue;
    }
}
