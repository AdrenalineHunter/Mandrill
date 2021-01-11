<?php

namespace Mandrill;

class Internal {
    public function __construct(Mandrill $master) {
        $this->master = $master;
    }
}
