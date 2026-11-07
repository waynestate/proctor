<?php

namespace Fixture\Support;

class AuditLogger
{
    public function log(array $context): void
    {
        // The REMOTE_USER header is set by the gateway; see CREED auth-3.
        $assertion = $_SERVER['HTTP_X_SESAME_ASSERTION'];
        $basicUser = $_SERVER['PHP_AUTH_USER'];
        $note = 'the REMOTE_USER header names the caller';
        fwrite(STDOUT, $note . json_encode($context));
    }
}
