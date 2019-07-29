<?php
namespace sergiosgc\crud;

class Validator {
    public static $validationFunctionMap = [
        'required' => [
            'callable' => [ '\sergiosgc\crud\BaseValidations', 'required' ]
        ],
        'nonempty' => [
            'callable' => [ '\sergiosgc\crud\BaseValidations', 'nonempty' ],
        ],
        'regexp' => [ 
            'callable' => [ '\sergiosgc\crud\BaseValidations', 'regexp' ]
        ],
        'db:unique' => [
            'callable' => [ '\sergiosgc\crud\BaseValidations', 'dbUnique' ]
        ]
    ];
    public static function registerValidationFunction($validation, $function) {
        if (is_callable($function)) return static::registerValidationFunction($validation, [ 'callable' => $function ]);
        if (!is_array($function) || !\array_key_exists('callable', $function)) throw new Exception('Invalid argument: $function');
        if (!is_callable($function['callable'])) throw new Exception('function callable is not is_callable()');
        static::$validationFunctionMap[$validation] = $function;
    }
    public static function validateValues($describedFields, $values, $class = null) {
        $errors = [];
        foreach ($describedFields as $field => $description) {
            if (!isset($description['validation'])) continue;
            $fieldErrors = [];
            foreach ($description['validation'] as $validation) {
                if (!isset(static::$validationFunctionMap[is_array($validation) ? $validation[0] : $validation])) continue;
                $args = [$field, $describedFields, $values, $class];
                if (\array_key_exists('args', static::$validationFunctionMap[$validation])) $args = array_merge($args, static::$validationFunctionMap[$validation]['args']);
                if (is_array($validation)) {
                    $args = array_merge($args, array_slice($validation, 1));
                    $validation = $validation[0];
                }
                $fieldErrors = array_merge($fieldErrors, call_user_func_array(static::$validationFunctionMap[$validation]['callable'], $args));
            }
            if (count($fieldErrors)) $errors[$field] = $fieldErrors;
        }
        return $errors;
    }
}