<?php

declare(strict_types=1);

namespace Vaulter\Tenant;

use Tenant\Data\IsolationIdentity;

/** The frozen P1 scalar profile; business identity deliberately excludes incarnation. */
final readonly class ScalarProjection
{
    public function __construct(private ?string $namespace = null)
    {
        if ($namespace !== null && !preg_match('/^[a-z][a-z0-9._-]{0,31}$/D', $namespace)) {
            throw new \InvalidArgumentException('identity_encoding_unsupported');
        }
    }

    public function encode(IsolationIdentity $identity): string
    {
        $ns = $identity->namespace;
        $id = $identity->id;
        if (($this->namespace !== null && $ns !== $this->namespace)
            || strlen($ns) > 32 || strlen($ns) + strlen($id) > 44) {
            throw new \InvalidArgumentException('identity_encoding_unsupported');
        }

        return 't1.'.rtrim(strtr(base64_encode(chr(strlen($ns)).$ns.$id), '+/', '-_'), '=');
    }
}
