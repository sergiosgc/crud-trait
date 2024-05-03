<?php
namespace sergiosgc\crud;

trait ArrayAccess {
    public function offsetExists ( mixed $offset ): bool {
        if (!$this instanceof Describable) throw new Exception("ArrayAccess trait can only be used by Describable classes");
        return in_array($offset, array_keys(static::describeFields()));
    }
    public function offsetGet ( mixed $offset ): mixed {
        if (!$this->offsetExists($offset)) return null;
        return $this->$offset;
    }
    public function offsetSet ( mixed $offset , mixed $value ): void {
        if (!$this->offsetExists($offset)) return;
        $this->$offset = $value;
    }
    public function offsetUnset ( mixed $offset ): void {
        if (!$this->offsetExists($offset)) return;
        unset($this->$offset);
    }
}
