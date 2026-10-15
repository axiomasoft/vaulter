<?php

declare(strict_types=1);

namespace Vaulter\Profiles;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Exceptions\Profiles\InvalidProfileException;

/** A fluent policy builder; the registry stores independent frozen snapshots. */
#[Api]
final class Profile
{
    private bool $frozen = false;

    private string $key = '';

    /** @var list<class-string<Model>> */
    private array $owners = [];

    private ?string $disk = null;

    private ?int $defaultQuota = null;

    private ?UploadPolicy $uploads = null;

    private ?string $permissionDriver = null;

    /** @var list<string> */
    private array $documentTypes = [];

    private ?bool $grants = null;

    private ?bool $shareLinks = null;

    private ?bool $comments = null;

    private ?int $revisionLimit = null;

    private ?int $trashRetentionDays = null;

    private ?DrivePolicy $drive = null;

    public function key(string $key): static
    {
        $this->assertMutable();
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D', $key) !== 1) {
            throw new InvalidProfileException('Invalid profile key.');
        }
        $this->key = $key;

        return $this;
    }

    public function owners(string ...$modelClasses): static
    {
        $this->assertMutable();
        $owners = [];
        foreach ($modelClasses as $class) {
            if (!is_a($class, Model::class, true)) {
                throw new InvalidProfileException('Profile owners must extend Eloquent Model.');
            }
            $owners[] = $class;
        }
        $this->owners = $owners;

        return $this;
    }

    public function disk(string $disk): static
    {
        $this->assertMutable();
        if (trim($disk) === '') {
            throw new InvalidProfileException('Profile disk must be nonempty.');
        }
        $this->disk = $disk;

        return $this;
    }

    public function defaultQuota(?int $bytes): static
    {
        $this->assertMutable();
        $this->nonnegative($bytes);
        $this->defaultQuota = $bytes;

        return $this;
    }

    public function uploads(UploadPolicy $policy): static
    {
        $this->assertMutable();
        $this->uploads = $policy;

        return $this;
    }

    public function permissionDriver(string $driverKey): static
    {
        $this->assertMutable();
        if (trim($driverKey) === '') {
            throw new InvalidProfileException('Profile permission driver must be nonempty.');
        }
        $this->permissionDriver = $driverKey;

        return $this;
    }

    public function documentTypes(string ...$typeKeys): static
    {
        $this->assertMutable();
        foreach ($typeKeys as $key) {
            if (trim($key) === '') {
                throw new InvalidProfileException('Document type keys must be nonempty.');
            }
        }
        $this->documentTypes = array_values($typeKeys);

        return $this;
    }

    public function grants(bool $enabled = true): static
    {
        $this->assertMutable();
        $this->grants = $enabled;

        return $this;
    }

    public function shareLinks(bool $enabled = true): static
    {
        $this->assertMutable();
        $this->shareLinks = $enabled;

        return $this;
    }

    public function comments(bool $enabled = true): static
    {
        $this->assertMutable();
        $this->comments = $enabled;

        return $this;
    }

    /**
     * Shorthand for the drive policy's revision retention: keep at most
     * $max revisions per file, the head included — maxNoncurrentRevisions = $max − 1;
     * null keeps every revision. The other retention settings stay as they are.
     */
    public function revisionLimit(?int $max): static
    {
        $this->assertMutable();
        if ($max !== null && $max < 1) {
            throw new InvalidProfileException('Revision limit must be positive or null.');
        }
        $this->revisionLimit = $max;
        $current = $this->drive ?? new DrivePolicy;
        $retention = $current->revisionRetention;
        $this->drive = (new DrivePolicyBuilder($current))->revisionRetention(new RevisionRetentionPolicy(
            maxNoncurrentRevisions: $max === null ? null : $max - 1,
            maxNoncurrentAgeDays: $retention->maxNoncurrentAgeDays,
            minNoncurrentAgeDays: $retention->minNoncurrentAgeDays,
            protectPinned: $retention->protectPinned,
        ))->build();

        return $this;
    }

    public function trashRetentionDays(?int $days): static
    {
        $this->assertMutable();
        $this->nonnegative($days);
        $this->trashRetentionDays = $days;

        return $this;
    }

    /**
     * Tree and content limits of the profile's drives:
     * ->drivePolicy(fn (DrivePolicyBuilder $p) => $p->maxFolderChildren(5000)).
     *
     * @param callable(DrivePolicyBuilder): mixed $configure
     */
    public function drivePolicy(callable $configure): static
    {
        $this->assertMutable();
        $builder = new DrivePolicyBuilder($this->drive ?? new DrivePolicy);
        $configure($builder);
        $this->drive = $builder->build();

        return $this;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    /** @return list<class-string<Model>> */
    public function getOwners(): array
    {
        return $this->owners;
    }

    public function getDisk(): ?string
    {
        return $this->disk;
    }

    public function getDefaultQuota(): ?int
    {
        return $this->defaultQuota;
    }

    public function getUploadPolicy(): UploadPolicy
    {
        return $this->uploads ?? new UploadPolicy;
    }

    /**
     * A profile upload policy's maxFileSize predates the drive policy; it still
     * applies when the drive policy sets no maxFileBytes of its own.
     */
    public function getDrivePolicy(): DrivePolicy
    {
        $policy = $this->drive ?? new DrivePolicy;
        $legacy = $this->uploads?->maxFileSize;
        if ($policy->maxFileBytes !== null || $legacy === null) {
            return $policy;
        }

        return (new DrivePolicyBuilder($policy))->maxFileBytes($legacy)->build();
    }

    public function getPermissionDriver(): ?string
    {
        return $this->permissionDriver;
    }

    /** @return list<string> */
    public function getDocumentTypes(): array
    {
        return $this->documentTypes;
    }

    public function grantsEnabled(): bool
    {
        return $this->grants ?? true;
    }

    public function shareLinksEnabled(): bool
    {
        return $this->shareLinks ?? true;
    }

    public function commentsEnabled(): bool
    {
        return $this->comments ?? true;
    }

    public function getRevisionLimit(): ?int
    {
        return $this->revisionLimit;
    }

    public function getTrashRetentionDays(): ?int
    {
        return $this->trashRetentionDays;
    }

    /** Nullable flags distinguish explicit enabling from inheritance. */
    #[Internal]
    public function featureFlag(string $feature): ?bool
    {
        return match ($feature) {
            'grants' => $this->grants,
            'share_links' => $this->shareLinks,
            'comments' => $this->comments,
            default => null,
        };
    }

    /** Called only on the registry's independent copy. */
    #[Internal]
    public function freeze(): void
    {
        $this->frozen = true;
    }

    private function assertMutable(): void
    {
        if ($this->frozen) {
            throw new LogicException('A registered profile is immutable.');
        }
    }

    private function nonnegative(?int $value): void
    {
        if ($value !== null && $value < 0) {
            throw new InvalidProfileException('Profile limits must be nonnegative or null.');
        }
    }
}
