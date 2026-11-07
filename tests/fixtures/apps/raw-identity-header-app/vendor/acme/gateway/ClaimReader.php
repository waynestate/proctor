<?php

namespace Acme\Gateway;

class ClaimReader
{
    public function user(): ?string
    {
        return getenv('REMOTE_USER');
    }
}
