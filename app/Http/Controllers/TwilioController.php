<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Twilio\Jwt\AccessToken;
use Twilio\Jwt\Grants\VoiceGrant;
use Twilio\Rest\Client;

class TwilioController extends Controller
{
    /**
     * Generate a Twilio Voice access token for the given identity.
     */
    public function token(Request $request): JsonResponse
    {
        $identity = $request->query('identity', 'agent_1');

        $token = new AccessToken(
            config('services.twilio.account_sid'),
            config('services.twilio.api_key'),
            config('services.twilio.api_secret'),
            3600,
            $identity,
        );

        $voiceGrant = new VoiceGrant;
        $voiceGrant->setIncomingAllow(true);

        $twimlAppSid = config('services.twilio.twiml_app_sid');

        if ($twimlAppSid) {
            $voiceGrant->setOutgoingApplicationSid($twimlAppSid);
        }

        $token->addGrant($voiceGrant);

        return response()->json([
            'token' => $token->toJWT(),
            'identity' => $identity,
        ]);
    }

    /**
     * Inbound calls: return TwiML to connect to agent_1.
     */
    public function incoming(Request $request): Response
    {
        $twiml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Response>
    <Say>Connecting you to an agent.</Say>
    <Dial>
        <Client>agent_1</Client>
    </Dial>
</Response>
XML;

        return response($twiml, 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * Outbound calls: return TwiML to dial the given number.
     */
    public function outgoing(Request $request): Response
    {
        $to = $request->input('To', '');
        $callerId = config('services.twilio.from_number');

        $twiml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Response>
    <Dial callerId="{$callerId}">
        <Number>{$to}</Number>
    </Dial>
</Response>
XML;

        return response($twiml, 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * Handle status callbacks: log call status updates.
     */
    public function status(Request $request): Response
    {
        Log::info('Twilio status callback', [
            'callSid' => $request->input('CallSid'),
            'status' => $request->input('CallStatus'),
            'from' => $request->input('From'),
            'to' => $request->input('To'),
            'timestamp' => now()->toIso8601String(),
        ]);

        return response('', 204);
    }

    /**
     * Blind transfer: redirect the caller's call leg to dial the target agent.
     */
    public function blindTransfer(Request $request): JsonResponse
    {
        $callSid = $request->input('callSid');
        $targetIdentity = $request->input('targetIdentity');
        $client = $this->twilioClient();
        $twiml = '<Response><Dial><Client>'.$targetIdentity.'</Client></Dial></Response>';
        $client->calls($callSid)->update(['twiml' => $twiml]);
        Log::info('Blind transfer initiated', [
            'callSid' => $callSid,
            'targetIdentity' => $targetIdentity,
        ]);
        return response()->json(['success' => true]);
    }

    /**
     * Warm transfer start: put the caller on hold, then create a consult call to the target agent.
     */
    public function warmTransferStart(Request $request): JsonResponse
    {
        $callSid = $request->input('callSid');
        $targetIdentity = $request->input('targetIdentity');
        $client = $this->twilioClient();
        // this is putting the caller on hold with music
        $holdTwiml = '<Response><Play loop="0">http://com.twilio.sounds.music.s3.amazonaws.com/MARKOVICHAMP-B7.mp3</Play></Response>';
        $client->calls($callSid)->update(['twiml' => $holdTwiml]);

        // Create a consult call to the target agent via their browser client.
        // We use the TwiML App approach: dial the target client identity directly via inline TwiML.
        $consultTwiml = '<Response><Dial><Client>'.$targetIdentity.'</Client></Dial></Response>';
        $fromNumber = config('services.twilio.from_number', '+15551234567');
        $consultCall = $client->calls->create(
            'client:'.$targetIdentity,
            $fromNumber,
            ['twiml' => $consultTwiml],
        );

        Log::info('Warm transfer started', [
            'originalCallSid' => $callSid,
            'consultCallSid' => $consultCall->sid,
            'targetIdentity' => $targetIdentity,
        ]);

        return response()->json([
            'success' => true,
            'consultCallSid' => $consultCall->sid,
        ]);
    }

    /**
     * Warm transfer complete: connect the original caller to the target agent, end the consult call.
     */
    public function warmTransferComplete(Request $request): JsonResponse
    {
        $parentCallSid = $request->input('parentCallSid');
        $consultCallSid = $request->input('consultCallSid');
        $targetIdentity = $request->input('targetIdentity');

        $client = $this->twilioClient();

        // Redirect the on-hold caller to dial the target agent
        $twiml = '<Response><Dial><Client>'.$targetIdentity.'</Client></Dial></Response>';
        $client->calls($parentCallSid)->update(['twiml' => $twiml]);

        // End the consult call between Agent A and Agent B
        $client->calls($consultCallSid)->update(['status' => 'completed']);

        Log::info('Warm transfer completed', [
            'parentCallSid' => $parentCallSid,
            'consultCallSid' => $consultCallSid,
            'targetIdentity' => $targetIdentity,
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * TwiML webhook for transfer: dials the target client identity passed as a query parameter.
     */
    public function transferDial(Request $request): Response
    {
        $target = $request->query('target', '');

        $twiml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Response>
    <Dial>
        <Client>{$target}</Client>
    </Dial>
</Response>
XML;

        return response($twiml, 200, ['Content-Type' => 'application/xml']);
    }

    private function twilioClient(): Client
    {
        return new Client(
            config('services.twilio.account_sid'),
            config('services.twilio.auth_token'),
        );
    }
}
