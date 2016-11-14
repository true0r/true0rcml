<?php

class EntityCML extends ObjectModel
{
    public $id_target;
    public $hash;
    public $guid;

    public static $definition = array(
        'table' => 'entitycml',
        'primary' => 'id_entitycml',
        'fields' => array(
            'id_target' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true),
            'hash' => array('type' => self::TYPE_STRING, 'required' => true, 'size' => 32),
            'guid' => array('type' => self::TYPE_STRING, 'size' => 80),
        ),
    );


    public static function getIdTarget($guid, $hash = null, $cache = false)
    {
        if (!$guid && !$hash) {
            throw new Exception('Должно быть задано одно из значиний guid или hash');
        }
        $cacheId = $guid.$hash;
        $where = $guid ? "guid = '$guid'" : '';
        $where .= $guid && $hash ? ' AND ' : '';
        $where .= $hash ? "hash = '$hash'" : '';

        if ($cache && Cache::isStored($cacheId)) {
            return Cache::retrieve($cacheId);
        }

        $id = Db::getInstance()->getValue(
            (new DbQuery())
                ->select('id_target')
                ->from(self::$definition['table'])
                ->where($where)
        );
        $cache && $id && Cache::store($cacheId, $id);
        return $id;
    }
    public static function deleteByGuidOrHash($guid, $hash = null)
    {
        if (!$guid && !$hash) {
            throw new Exception('Должно быть задано одно из значиний guid или hash');
        }
        $where = $guid ? "guid = '$guid'" : '';
        $where .= $guid && $hash ? ' OR ' : '';
        $where .= $hash ? "hash = '$hash'" : '';

        return Db::getInstance()->delete(self::$definition['table'], $where);
    }

    public static function updHash($guid, $hash)
    {
        if (!$guid) {
            throw new Exception('Guid is not set');
        }
        return DB::getInstance()->update(self::$definition['table'], array('hash' => $hash), "guid = '$guid'");
    }
}
