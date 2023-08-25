<?php
namespace sergiosgc\Crud;

class BaseValidations {
    public static function required($field, $describedFields, $values, $class, $message = null) {
        $message = $message ?: __('Field is required');
        if (!isset($values[$field])) return [ $message ];
        return [];
    }
    public static function nonempty($field, $describedFields, $values, $class, $message = null) {
        $result = static::required($field, $describedFields, $values, $class, $message);
        if (count($result)) return $result;
        $message = $message ?: __('Field must be non-empty');
        if (is_string($values[$field]) && $values[$field] == '' || is_null($values[$field])) return [ $message ];
        return [];
    }
    public static function regexp($field, $describedFields, $values, $class, $regex, $message = null) {
        if (!isset($values[$field])) return [ ];
        $message = $message ?: sprintf(__('Field must match regex %s'), $regex);
        $result = preg_match($regex, $values[$field]);
        if ($result === FALSE) throw new Exception('Error executing regex: ' . $regex);
        if ($result == 0) return [ $message ];
        return [];
    }
    public static function dbUnique($field, $describedFields, $values, $class, $message = null) {
        $message = $message ?: __('Field must be unique');
        if (!array_key_exists($field, $values)) return [];
        $keys = $class::dbKeyFields();
        if (array_reduce($keys, function ($acc, $key) use ($values) { return $acc && array_key_exists($key, $values); }, true)) {
            $query = sprintf('"%s" = ? AND NOT ((%s) = (%s))', 
                $field, 
                implode(', ', array_map(function ($key) { return sprintf('"%s"', $key); }, $keys)),
                implode(', ', array_map(function ($key) { return '?'; }, $keys))
            );
            $dbReadPagedArgs = array_merge( 
                [ null, null, $query, null, null],
                [ $values[$field] ],
                array_map(function ($key) use ($values) { return $values[$key]; }, $keys)
            );
        } else {
            $query = sprintf('"%s" = ?', $field);
            $dbReadPagedArgs = array_merge( 
                [ null, null, $query, null, null],
                [ $values[$field] ]
            );
        }
        $duplicates = call_user_func_array( [ $class, 'dbReadPaged' ], $dbReadPagedArgs)[0];
        return count($duplicates) ? [ $message ] : [];
    }
    public static function email($field, $describedFields, $values, $class, $message = null) {
        if ( 0 == (int) preg_match('/^(?P<username>(?:[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+(?:\.[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+)*|"(?:[\x01-\x08\x0b\x0c\x0e-\x1f\x21\x23-\x5b\x5d-\x7f]|\\[\x01-\x09\x0b\x0c\x0e-\x7f])*"))@(?P<domain>(?:(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]*[a-z0-9])?|\[(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?|[a-z0-9-]*[a-z0-9]:(?:[\x01-\x08\x0b\x0c\x0e-\x1f\x21-\x5a\x53-\x7f]|\\[\x01-\x09\x0b\x0c\x0e-\x7f])+)\]))$/',
                                   $values[$field])) {
            return [ $message ?: __("Email must be in the format username@domain") ];
        }
        return [];
    }
}