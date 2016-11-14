<?php

class HackObjectModel extends ObjectModel
{
    public function __construct($defaultFields, $className)
    {
        // init self::$loaded_classes[$className]
        !array_key_exists($className, self::$loaded_classes) && new $className();

        foreach ($defaultFields as $key => $value) {
            array_key_exists($key, self::$loaded_classes[$className])
            && self::$loaded_classes[$className][$key] = $value;
        }
    }
}
