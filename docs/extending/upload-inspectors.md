---
title: Upload inspectors
---

# Upload inspectors

An inspector checks an upload before it is accepted — a content rule your app needs beyond size and
MIME type. It rejects by throwing a domain exception; returning normally accepts.

<!-- snippet: signature Vaulter\Drive\Contracts\Uploads\UploadInspector -->

```php
interface UploadInspector
{
    // A stable identifier of the inspector.
    public function key(): string;

    // Lower runs first.
    public function priority(): int;

    public function inspect(UploadFacts $facts, UploadPolicy $policy, OperationContext $context): void;
}
```

Inspectors run on the facts Vaulter has gathered, not on a stream of your own. Malware scanning is
a separate, durable mechanism — see [Operations](/guides/operations) and [Uploads](/guides/uploads).
