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
            ->assertCreated()->assertJsonPath('file.image', true)->json('id');
        $this->get("/v1/chat/messages/$msg/file")->assertOk();

        $this->as($arjun)->get("/v1/chat/messages/$msg/file")->assertOk();
        $this->deleteJson("/v1/chat/messages/$msg")->assertForbidden(); // not his
        $this->patchJson("/v1/chat/conversations/$g", ['name' => 'Renamed'])->assertForbidden();
        $this->as($nina)->get("/v1/chat/messages/$msg/file")->assertNotFound();

        $this->as($priya)->patchJson("/v1/chat/conversations/$g", ['member_ids' => [$arjun->id, $nina->id]])->assertOk()->assertJsonCount(3, 'members');
        $this->deleteJson("/v1/chat/messages/$msg")->assertOk();
        $this->getJson("/v1/chat/conversations/$g/messages")->assertJsonPath('data.1.deleted', true)->assertJsonPath('data.1.file', null);

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
}
