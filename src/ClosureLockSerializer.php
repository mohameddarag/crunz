<?php

namespace Crunz;

use Laravel\SerializableClosure\Serializers\Native;

class ClosureLockSerializer extends Native
{
    protected function mapByReference(&$data)
    {
        // Native uses spl_object_hash(); lock identities must survive a new PHP process.
        $index = 0;
        foreach ($this->scope as $closure) {
            if ($closure === $this->closure) {
                $this->reference = 'closure-' . $index;
                break;
            }
            ++$index;
        }

        parent::mapByReference($data);
    }
}
