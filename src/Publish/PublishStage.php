<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Publish;

/**
 * The steps a publish run moves through, surfaced to consumers as progress
 * events (and later recorded on the run row for the dashboard).
 */
enum PublishStage: string
{
    case Uploading = 'uploading';
    case Polling = 'polling';
    case CreatingWebsite = 'creating_website';
    case UpdatingWebsite = 'updating_website';
    case CreatingIpnsKey = 'creating_ipns_key';
    case PublishingIpns = 'publishing_ipns';
    case CheckingReadiness = 'checking_readiness';
    case Completed = 'completed';
    case Failed = 'failed';
    case Resumable = 'resumable';
}
