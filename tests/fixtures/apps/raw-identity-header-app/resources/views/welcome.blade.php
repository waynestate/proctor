<p>{{ $_SERVER['REMOTE_USER'] }}</p>
<p>{{ request()->header('X-Authenticated-User') }}</p>
<p>{{ request()->server('HTTP_OIDC_CLAIM_EMAIL') }}</p>
