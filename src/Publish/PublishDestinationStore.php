<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * Read/write wrapper for the persisted first-publish destination setup.
 *
 * The lifecycle is the immutability boundary: while the stored state is a
 * draft the destination may be re-saved; a confirmed destination may not be
 * changed (only re-confirmed unchanged); and once the website was created or
 * attached every mutation is rejected. Adapters read an absent or corrupt
 * stored value as null ("no setup yet").
 */
interface PublishDestinationStore
{
    public function read(): ?PublishDestinationState;

    /**
     * Persist the destination as an editable draft.
     *
     * @throws \InvalidArgumentException when a confirmed or
     * created/attached destination is already stored.
     */
    public function saveDraft(PublishDestination $destination): void;

    /**
     * Freeze the destination as the confirmed first-publish choice.
     *
     * Idempotent for an unchanged confirmed destination; rejects any change.
     *
     * @throws \InvalidArgumentException when the stored destination differs or
     * the website was already created/attached.
     */
    public function confirm(PublishDestination $destination): void;

    /**
     * Record that the website for the confirmed destination was created or
     * attached, freezing the destination permanently.
     *
     * @throws \InvalidArgumentException when nothing is confirmed.
     */
    public function markCreatedOrAttached(): void;
}
