<?php
namespace sergiosgc\Crud;

class BaseNormalizers {
    public static function castToInteger($value) {
        return (int) $value;
    }
    public static function castToFloat($value) {
        return (float) $value;
    }
    public static function castToBoolean($value) {
        return (bool) $value;
    }
    public static function emptyToNull($value) {
        if (!empty($value)) return $value;
        return null;
    }
}