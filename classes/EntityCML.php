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


    public static function getId($guid, $hash, $cache = false)
    {
        if (!$guid && !$hash) {
            throw new Exception('Должно быть задано одно из значиний guid или hash');
        }
        $where = $guid ? "guid = '$guid'" : '';
        $where .= $guid && $hash ? ' OR ': '';
        $where .= $hash ? "hash = '$hash'" : '';

        $cacheId = $guid.$hash;
        if ($cache && Cache::isStored($cacheId)) {
            return Cache::retrieve($cacheId);
        }

        $id = Db::getInstance()->getValue(
            (new DbQuery())
                ->select(self::$definition['primary'])
                ->from(self::$definition['table'])
                ->where($where)
        );
        $cache && $id && Cache::store($cacheId, $id);
        return $id;
    }
}
