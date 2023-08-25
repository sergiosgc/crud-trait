<?php
namespace sergiosgc\crud;

class Normalizer {
    public static $normalizerFunctionMap = [
        'type:int' => [
            'callable' => [ '\sergiosgc\crud\BaseNormalizers', 'castToInteger' ]
        ],
        'type:float' => [
            'callable' => [ '\sergiosgc\crud\BaseNormalizers', 'castToFloat' ]
        ],
        'type:boolean' => [
            'callable' => [ '\sergiosgc\crud\BaseNormalizers', 'castToBoolean' ]
        ],
        'trim' => [
            'callable' => '\trim'
        ],
        'lowercase' => [
            'callable' => '\strtolower'
        ],
        'emptyToNull' => [
            'callable' => [ '\sergiosgc\crud\BaseNormalizers', 'emptyToNull' ]
        ],
    ];
    public static function registerNormalizerFunction($normalizer, $function) {
        if (is_callable($function)) return static::registerNormalizerFunction($normalizer, [ 'callable' => $function ]);
        if (!is_array($function) || !\array_key_exists('callable', $function)) throw new Exception('Invalid argument: $function');
        if (!is_callable($function['callable'])) throw new Exception('function callable is not is_callable()');
        static::$normalizerFunctionMap[$normalizer] = $function;
    }
    public static function normalizeValues($describedFields, $values) {
        foreach ($describedFields as $field => $description) {
            if (!array_key_exists($field, $values)) continue;
            if (!isset($description['normalizer'])) $description['normalizer'] = [];
            $description['normalizer'][] = sprintf('type:' . $description['type']);
            foreach ($description['normalizer'] as $normalizer) {
                $normalizerFunction = null;
                $args = [$values[$field]];
                if (
                    (
                        is_string(is_array($normalizer) ? $normalizer[0] : $normalizer)
                        || is_int(is_array($normalizer) ? $normalizer[0] : $normalizer)
                    )
                    && isset(static::$normalizerFunctionMap[is_array($normalizer) ? $normalizer[0] : $normalizer])) {
                        $normalizerFunction = static::$normalizerFunctionMap[is_array($normalizer) ? $normalizer[0] : $normalizer]['callable'];
                        if (\array_key_exists('args', static::$normalizerFunctionMap[is_array($normalizer) ? $normalizer[0] : $normalizer])) $args = array_merge($args, static::$normalizerFunctionMap[is_array($normalizer) ? $normalizer[0] : $normalizer]['args']);
                    } else {
                        if (is_callable(is_array($normalizer) ? $normalizer[0] : $normalizer)) $normalizerFunction = is_array($normalizer) ? $normalizer[0] : $normalizer;
                    }
                if (is_null($normalizerFunction)) continue;
                if (is_array($normalizer)) $args = array_merge($args, array_slice($normalizer, 1));
                $values[$field] = call_user_func_array($normalizerFunction, $args);
            }
        }
        return $values;
    }
}