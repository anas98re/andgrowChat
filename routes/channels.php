<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

// A public channel that does not require user authentication
// Any visitor can listen to it as long as they have the conversation ID
Broadcast::channel('chat-session.{sessionId}', function () {
    return true;
});