<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function as(User $u): static
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u);

        return $this;
    }

    private function staff(string $name): User
    {
        return User::factory()->create(['name' => $name, 'role' => 'employee', 'status' => 'active']);
    }

    public function test_direct_chat_with_unread_counts_and_polling(): void
    {
        $priya = $this->staff('Priya');
        $arjun = $this->staff('Arjun');

        $id = $this->as($priya)->postJson('/v1/chat/direct', ['user_id' => $arjun->id])->assertCreated()->assertJsonPath('name', 'Arjun')->json('id');
        $this->postJson('/v1/chat/direct', ['user_id' => $arjun->id])->assertJsonPath('id', $id); // same chat again
        $m1 = $this->postJson("/v1/chat/conversations/$id/messages", ['body' => 'Hi Arjun, client call at 3?'])->assertCreated()->json('id');

        $this->as($arjun)->getJson('/v1/chat/unread')->assertJsonPath('unread', 1);
        $list = $this->getJson('/v1/chat/conversations')->assertOk()->json('data');
        $dm = collect($list)->firstWhere('id', $id);
        $this->assertSame('Priya', $dm['name']);
        $this->assertSame(1, $dm['unread']);
        $this->assertTrue(collect($list)->contains('everyone', true)); // Everyone group exists

        $this->getJson("/v1/chat/conversations/$id/messages")->assertJsonCount(1, 'data')->assertJsonPath('data.0.body', 'Hi Arjun, client call at 3?');
        $this->postJson("/v1/chat/conversations/$id/read", ['message_id' => $m1])->assertOk();
        $this->getJson('/v1/chat/unread')->assertJsonPath('unread', 0);
        $m2 = $this->postJson("/v1/chat/conversations/$id/messages", ['body' => 'Yes 👍'])->json('id');

        // Priya polls for new ones only, and sees Arjun has read hers.
        $this->as($priya)->getJson("/v1/chat/conversations/$id/messages?after_id=$m1")->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $m2)->assertJsonPath('read_upto', $m2);
    }

    public function test_outsiders_and_clients_cannot_read(): void
    {
        $priya = $this->staff('Priya');
        $arjun = $this->staff('Arjun');
        $nina = $this->staff('Nina');
        $id = $this->as($priya)->postJson('/v1/chat/direct', ['user_id' => $arjun->id])->json('id');
        $this->postJson("/v1/chat/conversations/$id/messages", ['body' => 'private']);

        $this->as($nina)->getJson("/v1/chat/conversations/$id/messages")->assertNotFound();
        $this->postJson("/v1/chat/conversations/$id/messages", ['body' => 'x'])->assertNotFound();

        $client = User::factory()->client()->create(['client_id' => Client::create(['company_name' => 'X'])->id]);
        $this->as($client)->getJson('/v1/chat/conversations')->assertForbidden();
        $this->postJson('/v1/chat/direct', ['user_id' => $client->id])->assertForbidden();
        $this->as($priya)->postJson('/v1/chat/direct', ['user_id' => $client->id])->assertStatus(422);
    }

    public function test_groups_files_and_deleting_own_messages(): void
    {
        Storage::fake('local');
        $priya = $this->staff('Priya');
        $arjun = $this->staff('Arjun');
        $nina = $this->staff('Nina');

        $g = $this->as($priya)->postJson('/v1/chat/groups', ['name' => 'Spice Route team', 'member_ids' => [$arjun->id]])->assertCreated()
            ->assertJsonPath('can_edit', true)->assertJsonCount(2, 'members')->json('id');
        $msg = $this->post("/v1/chat/conversations/$g/messages", ['file' => UploadedFile::fake()->image('poster.png')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('files.0.image', true)->json('id');
        $fid = \App\Models\MessageFile::where('message_id', $msg)->value('id');
        $this->get("/v1/chat/files/$fid")->assertOk();

        $this->as($arjun)->get("/v1/chat/files/$fid")->assertOk();
        $this->deleteJson("/v1/chat/messages/$msg")->assertForbidden(); // not his
        $this->patchJson("/v1/chat/conversations/$g", ['name' => 'Renamed'])->assertForbidden();
        $this->as($nina)->get("/v1/chat/files/$fid")->assertNotFound();

        $this->as($priya)->patchJson("/v1/chat/conversations/$g", ['member_ids' => [$arjun->id, $nina->id]])->assertOk()->assertJsonCount(3, 'members');
        $this->deleteJson("/v1/chat/messages/$msg")->assertOk();
        $this->getJson("/v1/chat/conversations/$g/messages")->assertJsonPath('data.1.deleted', true)->assertJsonPath('data.1.files', []);

        $this->as($arjun)->postJson("/v1/chat/conversations/$g/leave")->assertOk();
        $this->getJson("/v1/chat/conversations/$g/messages")->assertNotFound();
        $this->getJson('/v1/chat/conversations');
        $everyone = Conversation::where('is_everyone', true)->first();
        $this->postJson("/v1/chat/conversations/{$everyone->id}/leave")->assertStatus(422);
    }

    public function test_unread_returns_new_messages_for_notifications(): void
    {
        $priya = $this->staff('Priya');
        $arjun = $this->staff('Arjun');
        $id = $this->as($priya)->postJson('/v1/chat/direct', ['user_id' => $arjun->id])->json('id');
        $start = $this->as($arjun)->getJson('/v1/chat/unread')->assertJsonPath('latest', [])->json('max_id');

        $this->as($priya)->postJson("/v1/chat/conversations/$id/messages", ['body' => 'Lunch at 1?']);
        $r = $this->as($arjun)->getJson('/v1/chat/unread?since_id='.$start)->assertOk()->assertJsonPath('unread', 1);
        $this->assertSame('Priya', $r->json('latest.0.from'));
        $this->assertSame('Lunch at 1?', $r->json('latest.0.text'));
        $this->assertNull($r->json('latest.0.chat')); // direct chat

        // Nothing new after that point; own messages never notify.
        $this->getJson('/v1/chat/unread?since_id='.$r->json('max_id'))->assertJsonPath('latest', []);
        $this->postJson("/v1/chat/conversations/$id/messages", ['body' => 'Sure']);
        $this->getJson('/v1/chat/unread?since_id='.$r->json('max_id'))->assertJsonPath('latest', []);
    }

    public function test_several_attachments_in_one_message(): void
    {
        Storage::fake('local');
        $priya = $this->staff('Priya');
        $arjun = $this->staff('Arjun');
        $nina = $this->staff('Nina');
        $id = $this->as($priya)->postJson('/v1/chat/direct', ['user_id' => $arjun->id])->json('id');

        $r = $this->post("/v1/chat/conversations/$id/messages", ['body' => 'Drafts for review', 'files' => [
            UploadedFile::fake()->image('poster-1.png'), UploadedFile::fake()->image('poster-2.jpg'), UploadedFile::fake()->create('brief.pdf', 200, 'application/pdf'),
        ]], ['Accept' => 'application/json'])->assertCreated()->assertJsonCount(3, 'files');
        $this->assertTrue($r->json('files.0.image'));
        $this->assertFalse($r->json('files.2.image'));
        $fileId = $r->json('files.2.id');

        // Files only, no text; preview says how many.
        $this->post("/v1/chat/conversations/$id/messages", ['files' => [UploadedFile::fake()->image('a.png'), UploadedFile::fake()->image('b.png')]], ['Accept' => 'application/json'])->assertCreated();
        $list = $this->as($arjun)->getJson('/v1/chat/conversations')->json('data');
        $this->assertSame('📎 2 files', collect($list)->firstWhere('id', $id)['last']['text']);

        $this->get("/v1/chat/files/$fileId")->assertOk();
        $this->as($nina)->get("/v1/chat/files/$fileId")->assertNotFound();

        // Too many files at once.
        $this->as($priya)->post("/v1/chat/conversations/$id/messages", ['files' => array_map(fn ($i) => UploadedFile::fake()->image("$i.png"), range(1, 11))], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('files');

        // Deleting the message removes its files.
        $this->deleteJson('/v1/chat/messages/'.$r->json('id'))->assertOk();
        $this->assertSame(0, \App\Models\MessageFile::where('message_id', $r->json('id'))->count());
    }

    public function test_everyday_file_types_are_accepted_and_programs_refused(): void
    {
        Storage::fake('local');
        $priya = $this->staff('Priya');
        $arjun = $this->staff('Arjun');
        $id = $this->as($priya)->postJson('/v1/chat/direct', ['user_id' => $arjun->id])->json('id');
        $ok = [
            UploadedFile::fake()->create('IMG_0042.HEIC', 300, 'image/heic'),
            UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml'),
            UploadedFile::fake()->create('banner.ai', 500, 'application/postscript'),
            UploadedFile::fake()->create('mockup.psd', 900, 'image/vnd.adobe.photoshop'),
            UploadedFile::fake()->create('voice-note.ogg', 80, 'audio/ogg'),
            UploadedFile::fake()->create('reel.webm', 900, 'video/webm'),
            UploadedFile::fake()->create('assets.rar', 400, 'application/vnd.rar'),
            UploadedFile::fake()->create('leads.csv', 4, 'text/csv'),
            UploadedFile::fake()->image('photo.JPG'),
            UploadedFile::fake()->create('quote.odt', 30, 'application/vnd.oasis.opendocument.text'),
        ];
        $this->post("/v1/chat/conversations/$id/messages", ['files' => $ok], ['Accept' => 'application/json'])->assertCreated()->assertJsonCount(10, 'files');

        $r = $this->post("/v1/chat/conversations/$id/messages", ['files' => [UploadedFile::fake()->image('fine.png'), UploadedFile::fake()->create('setup.exe', 10)]], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->assertStringContainsString('setup.exe', json_encode($r->json('errors')));
        $this->post("/v1/chat/conversations/$id/messages", ['files' => [UploadedFile::fake()->createWithContent('page.html', '<script>alert(1)</script>')]], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_mentions_notify_only_real_members_named_in_the_text(): void
    {
        $priya = $this->staff('Priya Raj');
        $arjun = $this->staff('Arjun Menon');
        $neha = $this->staff('Neha');
        $outsider = $this->staff('Outsider');
        $g = $this->as($priya)->postJson('/v1/chat/groups', ['name' => 'Spice Route team', 'member_ids' => [$arjun->id, $neha->id]])->json('id');

        // Arjun is named; Neha was picked but her name was deleted from the text; the outsider isn't in the group.
        $m = $this->postJson("/v1/chat/conversations/$g/messages", ['body' => '@Arjun Menon can you check the reel?', 'mention_ids' => [$arjun->id, $neha->id, $outsider->id, $priya->id]])
            ->assertCreated()->assertJsonPath('mentions', [$arjun->id]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $arjun->id, 'data->type' => 'chat_mention']);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $neha->id, 'data->type' => 'chat_mention']);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $outsider->id]);

        // The pop-up for Arjun says he was mentioned.
        $this->as($arjun)->getJson('/v1/chat/unread?since_id='.($m['id'] - 1))->assertJsonPath('latest.0.mention', true);
        $this->getJson("/v1/chat/conversations/$g/messages")->assertJsonPath('data.1.mentions', [$arjun->id]);

        // @everyone reaches the whole group except the sender.
        $this->as($neha)->postJson("/v1/chat/conversations/$g/messages", ['body' => 'Hi @everyone, call at 4', 'mention_everyone' => true])
            ->assertJsonCount(2, 'mentions');
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $priya->id, 'data->type' => 'chat_mention']);
    }

    public function test_read_ticks_sent_delivered_read_in_a_group(): void
    {
        $priya = $this->staff('Priya');
        $arjun = $this->staff('Arjun');
        $neha = $this->staff('Neha');
        $g = $this->as($priya)->postJson('/v1/chat/groups', ['name' => 'Reels', 'member_ids' => [$arjun->id, $neha->id]])->json('id');
        $m = $this->postJson("/v1/chat/conversations/$g/messages", ['body' => 'Reel is ready'])->json('id');
        $tick = fn () => collect($this->as($priya)->getJson("/v1/chat/conversations/$g/messages")->json('receipts'))->keyBy('name');

        // Sent only: nobody's app has fetched it yet.
        $this->assertTrue($tick()->every(fn ($r) => $r['delivered_id'] < $m && $r['read_id'] < $m));

        // Arjun's app polls (in the background): delivered to him, not read.
        $this->as($arjun)->getJson('/v1/chat/unread')->assertOk();
        $r = $tick();
        $this->assertSame($m, $r['Arjun']['delivered_id']);
        $this->assertNotNull($r['Arjun']['delivered_at']);
        $this->assertLessThan($m, $r['Arjun']['read_id']);
        $this->assertLessThan($m, $r['Neha']['delivered_id']);

        // Arjun opens the chat and reads it; Neha opens it too.
        $this->as($arjun)->postJson("/v1/chat/conversations/$g/read", ['message_id' => $m])->assertOk();
        $this->as($neha)->getJson("/v1/chat/conversations/$g/messages")->assertOk(); // delivered on open
        $r = $tick();
        $this->assertSame($m, $r['Arjun']['read_id']);
        $this->assertNotNull($r['Arjun']['read_at']);
        $this->assertSame($m, $r['Neha']['delivered_id']);
        $this->assertCount(2, $r); // never the sender
    }
}
