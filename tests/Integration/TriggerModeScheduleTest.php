<?php

declare(strict_types=1);

namespace LumeWeb\Cast\Tests\Integration;

use LumeWeb\Cast\Jobs\ContentPublishScheduler;
use LumeWeb\Cast\Jobs\JobsHookSubscriber;
use LumeWeb\Cast\Jobs\PublishIdentity;
use LumeWeb\Cast\Jobs\PublishMode;
use LumeWeb\Cast\Jobs\WordPressActionScheduler;
use LumeWeb\Cast\Jobs\WordPressIdentityGateway;
use LumeWeb\Cast\Jobs\WordPressPublishModeStore;
use LumeWeb\Cast\Persistence\WordPressRunRepository;
use PHPUnit\Framework\TestCase;
use ActionScheduler_Store;

/**
 * Real WordPress + real Action Scheduler trigger-mode scheduling.
 *
 * This is the smallest end-to-end scenario of the publish scheduling feature:
 * a save burst (several publish-relevant `transition_post_status` firings)
 * routed through the real hook engine, the real option-backed run/mode/identity
 * stores and the real Action Scheduler adapter (WordPressActionScheduler, the
 * composition CastPlugin wires). It proves that `OnUpdate` mode coalesces an
 * entire burst into exactly one auto-tick event and that the default `Manual`
 * mode never schedules anything, no matter how many saves land.
 */
final class TriggerModeScheduleTest extends TestCase
{
    protected function setUp(): void
    {
        // Boot the plugin once per PHPUnit process. require_once keeps it
        // idempotent: if another integration test already included cast.php the
        // hooks are already registered and this is a no-op.
        require_once dirname(__DIR__, 2) . '/cast.php';

        // The wptests_ database is shared across suite runs, so deterministically
        // reset every option and Action Scheduler event this test class writes
        // before firing a save burst.
        delete_option(WordPressRunRepository::OPTION_KEY);
        delete_option(WordPressPublishModeStore::OPTION_KEY);
        delete_option(WordPressIdentityGateway::OPTION_KEY);
        as_unschedule_all_actions(ContentPublishScheduler::AUTO_HOOK, [], WordPressActionScheduler::DEFAULT_GROUP);
        as_unschedule_all_actions(ContentPublishScheduler::FOLLOW_UP_HOOK, [], WordPressActionScheduler::DEFAULT_GROUP);

        self::assertNotFalse(has_action(JobsHookSubscriber::TRANSITION_HOOK));
    }

    public function testOnUpdateSaveBurstSchedulesExactlyOneAutoTick(): void
    {
        update_option(WordPressPublishModeStore::OPTION_KEY, PublishMode::OnUpdate->value, false);
        $this->storeReadyIdentity();

        $this->fireSaveBurst();

        self::assertNotFalse(as_next_scheduled_action(
            ContentPublishScheduler::AUTO_HOOK,
            [],
            WordPressActionScheduler::DEFAULT_GROUP,
        ));
        self::assertSame(1, $this->scheduledActionCount(ContentPublishScheduler::AUTO_HOOK));
        self::assertFalse(as_next_scheduled_action(
            ContentPublishScheduler::FOLLOW_UP_HOOK,
            [],
            WordPressActionScheduler::DEFAULT_GROUP,
        ));
    }

    public function testManualModeSaveBurstSchedulesNoEvent(): void
    {
        // Manual is the default mode: deleting the mode option leaves a fresh
        // install state, and manual publish must never auto-schedule.
        delete_option(WordPressPublishModeStore::OPTION_KEY);
        $this->storeReadyIdentity();

        $this->fireSaveBurst();

        self::assertFalse(as_next_scheduled_action(
            ContentPublishScheduler::AUTO_HOOK,
            [],
            WordPressActionScheduler::DEFAULT_GROUP,
        ));
        self::assertSame(0, $this->scheduledActionCount(ContentPublishScheduler::AUTO_HOOK));
        self::assertSame(0, $this->scheduledActionCount(ContentPublishScheduler::FOLLOW_UP_HOOK));
    }

    /**
     * Fire the real `transition_post_status` action the way a save burst does:
     * a first publish, several same-item updates, a publish-relevant
     * unavailability move, and revision/autosave traffic that must be ignored.
     */
    private function fireSaveBurst(): void
    {
        $post = $this->post('publish');

        // First publish — the only event the debounce should ever arm.
        do_action(JobsHookSubscriber::TRANSITION_HOOK, 'publish', 'draft', $post);
        // Same-item edits fold into the same quiet-period debounce.
        do_action(JobsHookSubscriber::TRANSITION_HOOK, 'publish', 'publish', $post);
        do_action(JobsHookSubscriber::TRANSITION_HOOK, 'publish', 'publish', $post);
        // Published content becoming unavailable is still publish-relevant, but
        // never stacks a second event.
        do_action(JobsHookSubscriber::TRANSITION_HOOK, 'draft', 'publish', $post);
        // Revisions and autosaves inside the burst must never enqueue.
        do_action(
            JobsHookSubscriber::TRANSITION_HOOK,
            'publish',
            'draft',
            (object) ['ID' => 9, 'post_type' => 'revision', 'post_status' => 'inherit'],
        );
    }

    /**
     * A ready publish identity so the auto pipeline is allowed to schedule.
     */
    private function storeReadyIdentity(): void
    {
        $identity = new PublishIdentity(
            websiteId: 'website-test-1',
            websiteName: 'test-blog.example.org',
            ipnsKeyId: 'ipns-key-test-1',
            ipnsKeyName: 'test-blog-key',
            ready: true,
        );
        update_option(WordPressIdentityGateway::OPTION_KEY, $identity->toOptionValue(), false);
    }

    /**
     * How many schedule runs Action Scheduler has queued for $hook under Cast's
     * group.
     *
     * Counts through the real AS store's query API (pending + running), the
     * faithful successor to the WP-Cron `cron`-option tally this test once read
     * — the same "queued to run, or currently running" population a WP-Cron
     * single event occupied. Because AS unique scheduling allows at most one
     * pending slot per (hook, args, group), a count of 1 proves the burst
     * coalesced into the dedupe guarantee.
     */
    private function scheduledActionCount(string $hook): int
    {
        return count(ActionScheduler_Store::instance()->query_actions([
            'hook'     => $hook,
            'group'    => WordPressActionScheduler::DEFAULT_GROUP,
            'status'   => [ActionScheduler_Store::STATUS_PENDING, ActionScheduler_Store::STATUS_RUNNING],
            'per_page' => -1,
        ]));
    }

    /**
     * @return object{ID: int, post_type: string, post_status: string}
     */
    private function post(string $status): object
    {
        return (object) ['ID' => 1, 'post_type' => 'post', 'post_status' => $status];
    }
}
