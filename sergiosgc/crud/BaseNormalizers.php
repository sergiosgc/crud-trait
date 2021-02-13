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
        if ($value === "f" || $value === "0" || $value === 0 | $value === "false") return false;
        return (bool) $value;
    }
    public static function emptyToNull($value) {
        if (!empty($value)) return $value;
        return null;
    }
}