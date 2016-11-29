<?php

class EntityCML extends ObjectModel
{
    public $id_target;
    public $target_class;
    public $hash;
    public $guid;

    public static $definition = array(
        'table' => 'entitycml',
        'primary' => 'id_entitycml',
        'fields' => array(
            'id_target' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true),
            'target_class' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true),
            'hash' => array('type' => self::TYPE_STRING, 'required' => true, 'size' => 32),
            'guid' => array('type' => self::TYPE_STRING, 'size' => 80),
        ),
    );

    public static function getIdEntityCMLAndTarget($guid, $hash = null, $cache = false)
    {
        if (!$guid && !$hash) {
            throw new Exception('Должно быть задано одно из значиний guid или hash');
        }

        $cacheId = $guid ? $guid : $hash;
        if (Cache::isStored($cacheId)) {
            return Cache::retrieve($cacheId);
        }

        $ids = Db::getInstance()->getRow(
            (new DbQuery())
                ->select(self::$definition['primary'].', id_target')
                ->from(self::$definition['table'])
                ->where($guid ? "guid = '$guid'" : "hash = '$hash'")
        );
        if ($ids) {
            $cache && Cache::store($cacheId, $ids);
            return $ids;
        }
        return false;
    }

    public static function getId($guid, $hash = null, $cache = false)
    {
        $ids = self::getIdEntityCMLAndTarget($guid, $hash, $cache);
        return $ids ? (int) $ids[self::$definition['primary']] : false;
    }
    public static function getIdTarget($guid, $hash = null, $cache = false)
    {
        $ids = self::getIdEntityCMLAndTarget($guid, $hash, $cache);
        return $ids ? (int) $ids['id_target'] : false;
    }
}
