<?php
namespace sergiosgc\crud;

trait JsonSerializable {
    public function jsonSerialize ( ) {
        $result = [];
        foreach (array_keys(static::describeFields()) as $k) $result[$k] = $this->$k;
        return $result;
    }
}
