<?php

namespace Tests\Feature;

use App\Mail\TicketNotificationMail;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\TestWebPushNotification;
use App\Notifications\TicketWebPushNotification;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use NotificationChannels\WebPush\WebPushChannel;
use RuntimeException;
use Tests\TestCase;

class WebPushNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('inertia.testing.ensure_pages_exist', false);
    }

    public function test_push_subscription_store_requires_authentication(): void
    {
        $payload = [
            'endpoint' => 'https://push.example.com/sub/guest',
            'keys' => [
                'p256dh' => 'test-p256dh-key',
                'auth' => 'test-auth-key',
            ],
            'contentEncoding' => 'aes128gcm',
        ];

        $this->post(route('push-subscriptions.store'), $payload)
            ->assertRedirect(route('login'));

        $this->postJson(route('push-subscriptions.store'), $payload)
            ->assertUnauthorized();

        $this->assertDatabaseCount((string) config('webpush.table_name', 'push_subscriptions'), 0);
    }

    public function test_authenticated_user_can_store_and_update_push_subscription(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $endpoint = 'https://push.example.com/subscriptions/device-1';
        $tableName = (string) config('webpush.table_name', 'push_subscriptions');

        $this->actingAs($user)
            ->postJson(route('push-subscriptions.store'), [
                'endpoint' => $endpoint,
                'keys' => [
                    'p256dh' => 'initial-p256dh-key',
                    'auth' => 'initial-auth-key',
                ],
                'contentEncoding' => 'aes128gcm',
            ])
            ->assertOk()
            ->assertJson(['subscribed' => true]);

        $this->assertDatabaseHas($tableName, [
            'subscribable_type' => User::class,
            'subscribable_id' => $user->id,
            'endpoint' => $endpoint,
            'public_key' => 'initial-p256dh-key',
            'auth_token' => 'initial-auth-key',
            'content_encoding' => 'aes128gcm',
        ]);

        $this->actingAs($user)
            ->postJson(route('push-subscriptions.store'), [
                'endpoint' => $endpoint,
                'keys' => [
                    'p256dh' => 'rotated-p256dh-key',
                    'auth' => 'rotated-auth-key',
                ],
                'contentEncoding' => 'aesgcm',
            ])
            ->assertOk()
            ->assertJson(['subscribed' => true]);

        $this->assertDatabaseCount($tableName, 1);
        $this->assertDatabaseHas($tableName, [
            'subscribable_type' => User::class,
            'subscribable_id' => $user->id,
            'endpoint' => $endpoint,
            'public_key' => 'rotated-p256dh-key',
            'auth_token' => 'rotated-auth-key',
            'content_encoding' => 'aesgcm',
        ]);
    }

    public function test_push_subscription_store_rejects_non_https_endpoints_and_missing_keys(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->postJson(route('push-subscriptions.store'), [
                'endpoint' => 'http://push.example.com/insecure-endpoint',
                'keys' => [
                    'p256dh' => 'valid-p256dh',
                    'auth' => 'valid-auth',
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['endpoint']);

        $this->actingAs($user)
            ->postJson(route('push-subscriptions.store'), [
                'endpoint' => 'https://push.example.com/valid-endpoint',
                'keys' => [
                    'auth' => 'valid-auth',
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['keys.p256dh']);

        $this->actingAs($user)
            ->postJson(route('push-subscriptions.store'), [
                'endpoint' => 'https://push.example.com/valid-endpoint',
                'keys' => [
                    'p256dh' => 'valid-p256dh',
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['keys.auth']);

        $this->assertDatabaseCount((string) config('webpush.table_name', 'push_subscriptions'), 0);
    }

    public function test_authenticated_user_can_delete_own_subscription_but_not_another_users_subscription(): void
    {
        $owner = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $otherUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $ownerEndpoint = 'https://push.example.com/subscriptions/owner-device';
        $otherEndpoint = 'https://push.example.com/subscriptions/other-device';
        $tableName = (string) config('webpush.table_name', 'push_subscriptions');

        $owner->updatePushSubscription($ownerEndpoint, 'owner-p256dh', 'owner-auth', 'aes128gcm');
        $otherUser->updatePushSubscription($otherEndpoint, 'other-p256dh', 'other-auth', 'aes128gcm');

        $this->actingAs($owner)
            ->deleteJson(route('push-subscriptions.destroy'), [
                'endpoint' => $otherEndpoint,
            ])
            ->assertOk()
            ->assertJson(['subscribed' => false]);

        $this->assertDatabaseHas($tableName, [
            'subscribable_type' => User::class,
            'subscribable_id' => $otherUser->id,
            'endpoint' => $otherEndpoint,
        ]);

        $this->actingAs($owner)
            ->deleteJson(route('push-subscriptions.destroy'), [
                'endpoint' => $ownerEndpoint,
            ])
            ->assertOk()
            ->assertJson(['subscribed' => false]);

        $this->assertDatabaseMissing($tableName, [
            'subscribable_type' => User::class,
            'subscribable_id' => $owner->id,
            'endpoint' => $ownerEndpoint,
        ]);
        $this->assertDatabaseHas($tableName, [
            'subscribable_type' => User::class,
            'subscribable_id' => $otherUser->id,
            'endpoint' => $otherEndpoint,
        ]);
    }

    public function test_notifications_summary_rejects_guests_and_returns_scoped_recent_notifications(): void
    {
        $this->get(route('notifications.summary'))
            ->assertRedirect(route('login'));

        $this->getJson(route('notifications.summary'))
            ->assertUnauthorized();

        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $otherUser = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $baseTime = Carbon::parse('2026-11-20 08:00:00');
        $createdIds = [];

        for ($index = 1; $index <= 10; $index++) {
            $notification = new UserNotification([
                'user_id' => $user->id,
                'ticket_id' => null,
                'title' => "User Notification {$index}",
                'message' => "Message {$index}",
                'action_url' => '/notifications',
                'is_read' => $index <= 3,
                'read_at' => $index <= 3 ? $baseTime->copy()->addMinutes($index) : null,
            ]);
            $notification->created_at = $baseTime->copy()->addMinutes($index);
            $notification->updated_at = $baseTime->copy()->addMinutes($index);
            $notification->save();
            $createdIds[] = $notification->id;
        }

        $otherNotification = new UserNotification([
            'user_id' => $otherUser->id,
            'ticket_id' => null,
            'title' => 'Other User Notification',
            'message' => 'Should not appear for primary user.',
            'action_url' => '/notifications',
            'is_read' => false,
        ]);
        $otherNotification->created_at = $baseTime->copy()->addMinutes(20);
        $otherNotification->updated_at = $baseTime->copy()->addMinutes(20);
        $otherNotification->save();

        $expectedRecentIds = array_reverse(array_slice($createdIds, -8));

        $response = $this->actingAs($user)
            ->getJson(route('notifications.summary'))
            ->assertOk()
            ->assertJsonPath('unread_count', 7);

        /** @var array{unread_count: int, recent_notifications: list<array{id: int, title: string}>} $json */
        $json = $response->json();

        $this->assertCount(8, $json['recent_notifications']);
        $returnedIds = array_column($json['recent_notifications'], 'id');
        $this->assertSame($expectedRecentIds, $returnedIds);
        $this->assertNotContains($otherNotification->id, $returnedIds);
    }

    public function test_inertia_shares_vapid_public_key_for_authenticated_users_only_and_never_exposes_private_key(): void
    {
        config()->set('webpush.vapid.public_key', 'test-public-key');
        config()->set('webpush.vapid.private_key', 'super-secret-vapid-private-key');

        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $page->where('webpush.vapid_public_key', null);
                $encoded = json_encode($page->toArray(), JSON_THROW_ON_ERROR);
                $this->assertStringNotContainsString('super-secret-vapid-private-key', $encoded);
                $this->assertStringNotContainsString('vapid_private_key', $encoded);
            });

        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $page->where('webpush.vapid_public_key', 'test-public-key');
                $encoded = json_encode($page->toArray(), JSON_THROW_ON_ERROR);
                $this->assertStringNotContainsString('super-secret-vapid-private-key', $encoded);
                $this->assertStringNotContainsString('vapid_private_key', $encoded);
            });
    }

    public function test_notification_service_dispatches_web_push_when_vapid_and_subscription_exist(): void
    {
        Notification::fake();
        Mail::fake();

        config()->set('webpush.vapid.public_key', 'test-public-key');
        config()->set('webpush.vapid.private_key', 'test-private-key');

        $recipient = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
            'email' => 'push-recipient@calltek.local',
        ]);
        $recipient->updatePushSubscription(
            'https://push.example.com/subscriptions/active-user',
            'p256dh-key',
            'auth-key',
            'aes128gcm'
        );

        $ticket = $this->createTicket($recipient);

        $notification = app(NotificationService::class)->notifyUser(
            $recipient,
            $ticket,
            "New Ticket: {$ticket->ticket_number}",
            'A new ticket requires attention.',
            "/tickets/{$ticket->id}"
        );

        Notification::assertSentTo(
            $recipient,
            TicketWebPushNotification::class,
            fn (TicketWebPushNotification $sent): bool => $sent->userNotification->is($notification)
                && $sent->ticket?->is($ticket)
        );
        Mail::assertQueued(TicketNotificationMail::class, 1);
    }

    public function test_notification_service_skips_web_push_when_vapid_missing_or_no_subscriptions(): void
    {
        Notification::fake();
        Mail::fake();

        $recipientWithSub = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
            'email' => 'with-sub@calltek.local',
        ]);
        $recipientWithSub->updatePushSubscription(
            'https://push.example.com/subscriptions/user-sub',
            'p256dh-key',
            'auth-key',
            'aes128gcm'
        );

        $recipientWithoutSub = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
            'email' => 'without-sub@calltek.local',
        ]);

        $ticket = $this->createTicket($recipientWithSub);

        config()->set('webpush.vapid.public_key', '');
        config()->set('webpush.vapid.private_key', '');

        app(NotificationService::class)->notifyUser(
            $recipientWithSub,
            $ticket,
            'Missing VAPID keys',
            'Push should be skipped without VAPID keys.',
            "/tickets/{$ticket->id}"
        );

        Notification::assertNothingSent();

        config()->set('webpush.vapid.public_key', 'test-public-key');
        config()->set('webpush.vapid.private_key', 'test-private-key');

        app(NotificationService::class)->notifyUser(
            $recipientWithoutSub,
            $ticket,
            'No Push Subscription',
            'Push should be skipped without subscriptions.',
            "/tickets/{$ticket->id}"
        );

        Notification::assertNothingSent();
        Mail::assertQueued(TicketNotificationMail::class, 2);
    }

    public function test_notification_service_logs_warning_and_preserves_db_and_mail_when_web_push_throws(): void
    {
        Mail::fake();

        config()->set('webpush.vapid.public_key', 'test-public-key');
        config()->set('webpush.vapid.private_key', 'test-private-key');

        $recipient = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
            'email' => 'resilient@calltek.local',
        ]);
        $recipient->updatePushSubscription(
            'https://push.example.com/subscriptions/failing-channel',
            'p256dh-key',
            'auth-key',
            'aes128gcm'
        );

        $ticket = $this->createTicket($recipient);

        Notification::shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('Simulated push failure'));

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($recipient): bool {
                return $message === 'Failed to queue ticket web push notification.'
                    && ($context['recipient_id'] ?? null) === $recipient->id
                    && ($context['error'] ?? null) === 'Simulated push failure';
            });

        $notification = app(NotificationService::class)->notifyUser(
            $recipient,
            $ticket,
            "Ticket Update: {$ticket->ticket_number}",
            'In-app and email notifications must still succeed.',
            "/tickets/{$ticket->id}"
        );

        $this->assertDatabaseHas('user_notifications', [
            'id' => $notification->id,
            'user_id' => $recipient->id,
            'ticket_id' => $ticket->id,
            'title' => "Ticket Update: {$ticket->ticket_number}",
        ]);

        Mail::assertQueued(
            TicketNotificationMail::class,
            fn (TicketNotificationMail $mail): bool => $mail->hasTo($recipient->email)
        );
    }

    public function test_ticket_web_push_notification_configuration_and_safe_payload(): void
    {
        $recipient = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $ticket = $this->createTicket($recipient);

        $internalNotification = UserNotification::create([
            'user_id' => $recipient->id,
            'ticket_id' => $ticket->id,
            'title' => "New Ticket: {$ticket->ticket_number}",
            'message' => 'Ticket opened by user.',
            'action_url' => "/tickets/{$ticket->id}?tab=comments",
            'is_read' => false,
        ]);

        $webPush = new TicketWebPushNotification($internalNotification, $ticket);

        $this->assertInstanceOf(ShouldQueue::class, $webPush);
        $this->assertSame(3, $webPush->tries);
        $this->assertSame([30, 120, 600], $webPush->backoff);
        $this->assertTrue($webPush->afterCommit);
        $this->assertSame([WebPushChannel::class], $webPush->via($recipient));

        $payload = $webPush->toWebPush($recipient, $webPush)->toArray();

        $this->assertSame($internalNotification->title, $payload['title'] ?? null);
        $this->assertSame($internalNotification->message, $payload['body'] ?? null);
        $this->assertSame("ticket-notif-{$internalNotification->id}", $payload['tag'] ?? null);
        $this->assertSame("/tickets/{$ticket->id}?tab=comments", $payload['data']['url'] ?? null);
        $this->assertArrayNotHasKey('icon', $payload);
        $this->assertArrayNotHasKey('badge', $payload);

        $httpsPhishNotification = UserNotification::create([
            'user_id' => $recipient->id,
            'ticket_id' => $ticket->id,
            'title' => 'External URL test',
            'message' => 'Should fall back to ticket path.',
            'action_url' => 'https://evil.example/phish',
            'is_read' => false,
        ]);
        $httpsPhishPayload = (new TicketWebPushNotification($httpsPhishNotification, $ticket))
            ->toWebPush($recipient, $webPush)
            ->toArray();
        $this->assertSame("/tickets/{$ticket->id}", $httpsPhishPayload['data']['url'] ?? null);

        $protocolRelativeNotification = UserNotification::create([
            'user_id' => $recipient->id,
            'ticket_id' => $ticket->id,
            'title' => 'Protocol relative URL test',
            'message' => 'Should fall back to ticket path.',
            'action_url' => '//evil.example',
            'is_read' => false,
        ]);
        $protocolRelativePayload = (new TicketWebPushNotification($protocolRelativeNotification, $ticket))
            ->toWebPush($recipient, $webPush)
            ->toArray();
        $this->assertSame("/tickets/{$ticket->id}", $protocolRelativePayload['data']['url'] ?? null);

        $noTicketExternalNotification = UserNotification::create([
            'user_id' => $recipient->id,
            'ticket_id' => null,
            'title' => 'No ticket fallback test',
            'message' => 'Should fall back to notifications index.',
            'action_url' => 'https://evil.example/phish',
            'is_read' => false,
        ]);
        $noTicketPayload = (new TicketWebPushNotification($noTicketExternalNotification, null))
            ->toWebPush($recipient, $webPush)
            ->toArray();
        $this->assertSame('/notifications', $noTicketPayload['data']['url'] ?? null);
        $this->assertArrayNotHasKey('icon', $noTicketPayload);
        $this->assertArrayNotHasKey('badge', $noTicketPayload);
    }

    public function test_frontend_web_push_files_meet_static_security_checks(): void
    {
        $serviceWorkerPath = public_path('sw.js');
        $appLayoutPath = resource_path('js/Layouts/AppLayout.vue');
        $notificationsIndexPath = resource_path('js/Pages/Notifications/Index.vue');

        $this->assertFileExists($serviceWorkerPath);
        $this->assertFileExists($appLayoutPath);
        $this->assertFileExists($notificationsIndexPath);

        $serviceWorkerContents = (string) file_get_contents($serviceWorkerPath);
        $appLayoutContents = (string) file_get_contents($appLayoutPath);
        $notificationsIndexContents = (string) file_get_contents($notificationsIndexPath);

        $this->assertStringContainsString('self.location.origin', $serviceWorkerContents);
        $this->assertStringContainsString('useWebPush', $appLayoutContents);
        $this->assertStringContainsString('useWebPush', $notificationsIndexContents);
        $this->assertStringContainsString('handleSendTestNotification', $notificationsIndexContents);
        $this->assertStringContainsString('Send test alert', $notificationsIndexContents);
        $this->assertStringNotContainsString('v-html', $appLayoutContents);
        $this->assertStringNotContainsString('v-html', $notificationsIndexContents);
    }

    public function test_push_subscription_test_requires_authentication(): void
    {
        $this->post(route('push-subscriptions.test'))
            ->assertRedirect(route('login'));

        $this->postJson(route('push-subscriptions.test'))
            ->assertUnauthorized();
    }

    public function test_push_subscription_test_rejects_missing_vapid_keys_or_missing_subscriptions(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        config()->set('webpush.vapid.public_key', '');
        config()->set('webpush.vapid.private_key', '');

        $this->actingAs($user)
            ->postJson(route('push-subscriptions.test'))
            ->assertStatus(422)
            ->assertJson([
                'message' => 'Desktop push notifications are not configured on the server.',
            ]);

        config()->set('webpush.vapid.public_key', 'test-public-key');
        config()->set('webpush.vapid.private_key', 'test-private-key');

        $this->actingAs($user)
            ->postJson(route('push-subscriptions.test'))
            ->assertStatus(422)
            ->assertJson([
                'message' => 'Enable desktop alerts on this browser first.',
            ]);
    }

    public function test_authenticated_user_can_send_test_web_push_notification(): void
    {
        Notification::fake();

        config()->set('webpush.vapid.public_key', 'test-public-key');
        config()->set('webpush.vapid.private_key', 'test-private-key');

        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);
        $user->updatePushSubscription(
            'https://push.example.com/subscriptions/test-device',
            'test-p256dh-key',
            'test-auth-key',
            'aes128gcm'
        );

        $this->actingAs($user)
            ->postJson(route('push-subscriptions.test'))
            ->assertOk()
            ->assertJson([
                'sent' => true,
                'message' => 'Test notification sent. Check your desktop alerts.',
            ]);

        Notification::assertSentTo($user, TestWebPushNotification::class);
        $this->assertDatabaseCount('user_notifications', 0);
    }

    public function test_test_web_push_notification_configuration_and_payload(): void
    {
        $recipient = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $notification = new TestWebPushNotification;

        $this->assertNotInstanceOf(ShouldQueue::class, $notification);
        $this->assertSame([WebPushChannel::class], $notification->via($recipient));

        $payload = $notification->toWebPush($recipient, $notification)->toArray();

        $this->assertSame('CallTek Desk Test Notification', $payload['title'] ?? null);
        $this->assertSame('Desktop push notifications are working on this browser.', $payload['body'] ?? null);
        $this->assertSame('calltek-webpush-test', $payload['tag'] ?? null);
        $this->assertSame('/notifications', $payload['data']['url'] ?? null);
        $this->assertArrayNotHasKey('icon', $payload);
        $this->assertArrayNotHasKey('badge', $payload);
    }

    public function test_notifications_summary_preserves_ticket_submission_success_flash_and_previous_url(): void
    {
        Mail::fake();
        Storage::fake((string) config('filesystems.attachments_disk'));

        $user = User::factory()->create([
            'role' => User::ROLE_SUPERADMIN,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();

        $storeResponse = $this->actingAs($user)->post(route('tickets.store'), [
            'type' => Ticket::TYPE_ISSUE,
            'title' => 'Summary poll flash race check',
            'description' => 'Verifies background summary polling does not drop submission flash.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'environment' => 'production',
            'impact_scope' => 'single_user',
            'attachments' => [
                UploadedFile::fake()->create('trace.log', 10, 'text/plain'),
            ],
        ]);

        $ticket = Ticket::query()->latest('id')->firstOrFail();

        $storeResponse
            ->assertRedirect(route('tickets.show', $ticket))
            ->assertSessionHas('success', "Ticket {$ticket->ticket_number} created successfully.");

        $this->actingAs($user)
            ->get(route('notifications.summary'), ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertSame(route('dashboard'), session()->previousUrl());

        $this->actingAs($user)
            ->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($ticket): void {
                $page->where('flash.success', "Ticket {$ticket->ticket_number} created successfully.");
                $page->where('flash.id', fn ($id): bool => is_string($id) && $id !== '');
            });

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $page->where('flash.success', null);
                $page->where('flash.id', null);
            });
    }

    public function test_push_subscription_endpoints_preserve_session_flash_data(): void
    {
        Mail::fake();
        Storage::fake((string) config('filesystems.attachments_disk'));

        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'is_active' => true,
        ]);

        $storeResponse = $this->actingAs($user)->post(route('tickets.store'), [
            'type' => Ticket::TYPE_ISSUE,
            'title' => 'Push subscription flash race check',
            'description' => 'Verifies push subscription endpoints preserve submission flash.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'environment' => 'production',
            'impact_scope' => 'single_user',
            'attachments' => [
                UploadedFile::fake()->create('trace.log', 10, 'text/plain'),
            ],
        ]);

        $ticket = Ticket::query()->latest('id')->firstOrFail();

        $storeResponse
            ->assertRedirect(route('tickets.show', $ticket))
            ->assertSessionHas('success', "Ticket {$ticket->ticket_number} created successfully.");

        $endpoint = 'https://push.example.com/subscriptions/flash-preservation';

        $this->actingAs($user)
            ->postJson(route('push-subscriptions.store'), [
                'endpoint' => $endpoint,
                'keys' => [
                    'p256dh' => 'valid-p256dh',
                    'auth' => 'valid-auth',
                ],
                'contentEncoding' => 'aes128gcm',
            ])
            ->assertOk();

        $this->actingAs($user)
            ->postJson(route('push-subscriptions.store'), [
                'endpoint' => $endpoint,
                'keys' => [],
            ])
            ->assertUnprocessable();

        $this->actingAs($user)
            ->deleteJson(route('push-subscriptions.destroy'), [
                'endpoint' => $endpoint,
            ])
            ->assertOk();

        $this->actingAs($user)
            ->get(route('tickets.show', $ticket))
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($ticket): void {
                $page->where('flash.success', "Ticket {$ticket->ticket_number} created successfully.");
                $page->where('flash.id', fn ($id): bool => is_string($id) && $id !== '');
            });
    }

    private function createTicket(User $requester): Ticket
    {
        return Ticket::create([
            'ticket_number' => 'ISS-2026-'.str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT),
            'type' => Ticket::TYPE_ISSUE,
            'internal_system_id' => null,
            'requester_id' => $requester->id,
            'lead_engineer_id' => null,
            'title' => 'Sample Ticket for Web Push Tests',
            'description' => 'Sample ticket description.',
            'priority' => Ticket::PRIORITY_MEDIUM,
            'status' => Ticket::STATUS_OPEN,
            'environment' => 'production',
            'impact_scope' => 'single_user',
        ]);
    }
}
