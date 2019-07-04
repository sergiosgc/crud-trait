<?php
namespace sergiosgc\Crud;

class BaseValidations {
    public static function required($field, $values, $describedFields, $message = null) {
        $message = $message ?: _('Field is required');
        if (!isset($values[$field])) return [ $message ];
        return [];
    }
    public static function nonempty($field, $values, $describedFields, $message = null) {
        $result = static::required($field, $values, $describedFields, $message);
        if (count($result)) return $result;
        $message = $message ?: _('Field must be non-empty');
        if (is_string($values[$field]) && $values[$field] == '' || is_null($values[$field])) return [ $message ];
        return [];
    }
    public static function regexp($field, $values, $describedFields, $regex, $message = null) {
        if (!isset($values[$field])) return [ ];
        $message = $message ?: sprintf(_('Field must match regex %s'), $regex);
        $result = preg_match($regex, $values[$field]);
        if ($result === FALSE) throw new Exception('Error executing regex: ' . $regex);
        if ($result == 0) return [ $message ];
        return [];
    }
}