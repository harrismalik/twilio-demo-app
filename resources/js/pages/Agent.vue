<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Device, Call } from '@twilio/voice-sdk';
import { ref, computed, watch } from 'vue';

const agents = ['agent_1', 'agent_2'] as const;

const identity = ref('agent_1');
const phoneNumber = ref('');
const status = ref('Idle');
const error = ref('');
const callerNumber = ref('');

let device: Device | null = null;
let activeCall: Call | null = null;
let incomingCall: Call | null = null;

const isConnected = ref(false);
const hasIncomingCall = ref(false);
const isInCall = ref(false);

// Transfer state — default to the other agent
const transferTarget = ref('agent_2');
const otherAgents = computed(() => agents.filter((a) => a !== identity.value));

// Keep transfer target in sync when identity changes
watch(identity, () => {
    transferTarget.value = otherAgents.value[0] ?? '';
});
const parentCallSid = ref('');
const consultCallSid = ref('');
const isConsulting = ref(false);

const canTransfer = computed(() => isInCall.value && !isConsulting.value && transferTarget.value.trim() !== '');
const canCompleteTransfer = computed(() => isConsulting.value && consultCallSid.value !== '');

/**
 * Extract the parent (customer-side) call SID from the active call.
 *
 * For inbound calls the browser leg is a child of the original inbound call,
 * so `call.parameters.ParentCallSid` gives us the customer leg.
 *
 * For outbound calls initiated from the browser, the browser leg IS the
 * parent call, so we fall back to `CallSid`.
 */
function getParentCallSid(call: Call): string {
    return call.parameters.ParentCallSid || call.parameters.CallSid || '';
}

async function connect() {
    error.value = '';
    status.value = 'Connecting...';

    try {
        const response = await fetch(`/api/twilio/token?identity=${encodeURIComponent(identity.value)}`);
        const data = await response.json();

        device = new Device(data.token, {
            logLevel: 1,
            codecPreferences: [Call.Codec.Opus, Call.Codec.PCMU],
        });

        device.on('registered', () => {
            status.value = 'Ready';
            isConnected.value = true;
        });

        device.on('error', (err) => {
            error.value = `Device error: ${err.message}`;
            status.value = 'Error';
        });

        device.on('incoming', (call: Call) => {
            incomingCall = call;
            callerNumber.value = call.parameters.From || 'Unknown';
            hasIncomingCall.value = true;
            status.value = 'Ringing';

            call.on('cancel', () => {
                hasIncomingCall.value = false;
                incomingCall = null;
                status.value = isConsulting.value ? 'Consulting' : 'Ready';
            });

            call.on('disconnect', () => {
                hasIncomingCall.value = false;
                isInCall.value = false;
                incomingCall = null;
                activeCall = null;
                parentCallSid.value = '';
                status.value = isConsulting.value ? 'Consulting' : 'Ready';
            });
        });

        device.on('tokenWillExpire', async () => {
            const response = await fetch(`/api/twilio/token?identity=${encodeURIComponent(identity.value)}`);
            const data = await response.json();
            device?.updateToken(data.token);
        });

        await device.register();
    } catch (err) {
        error.value = `Connection failed: ${err instanceof Error ? err.message : String(err)}`;
        status.value = 'Error';
    }
}

function acceptCall() {
    if (!incomingCall) {
        return;
    }

    incomingCall.accept();
    activeCall = incomingCall;
    parentCallSid.value = getParentCallSid(incomingCall);
    incomingCall = null;
    hasIncomingCall.value = false;
    isInCall.value = true;
    status.value = 'Connected';
}

function rejectCall() {
    if (!incomingCall) {
        return;
    }

    incomingCall.reject();
    incomingCall = null;
    hasIncomingCall.value = false;
    status.value = 'Ready';
}

async function makeCall() {
    if (!device || !phoneNumber.value) {
        return;
    }

    error.value = '';
    status.value = 'Calling...';

    try {
        const call = await device.connect({
            params: { To: phoneNumber.value },
        });

        activeCall = call;

        call.on('accept', () => {
            parentCallSid.value = getParentCallSid(call);
            isInCall.value = true;
            status.value = 'Connected';
        });

        call.on('disconnect', () => {
            isInCall.value = false;
            activeCall = null;
            parentCallSid.value = '';
            status.value = 'Ready';
        });

        call.on('error', (err) => {
            error.value = `Call error: ${err.message}`;
            isInCall.value = false;
            activeCall = null;
            parentCallSid.value = '';
            status.value = 'Ready';
        });
    } catch (err) {
        error.value = `Call failed: ${err instanceof Error ? err.message : String(err)}`;
        status.value = 'Ready';
    }
}

function hangUp() {
    if (activeCall) {
        activeCall.disconnect();
        activeCall = null;
        isInCall.value = false;
        parentCallSid.value = '';
        status.value = 'Ready';
    }
}

function disconnect() {
    if (device) {
        device.unregister();
        device.destroy();
        device = null;
    }

    activeCall = null;
    incomingCall = null;
    isConnected.value = false;
    hasIncomingCall.value = false;
    isInCall.value = false;
    isConsulting.value = false;
    parentCallSid.value = '';
    consultCallSid.value = '';
    status.value = 'Idle';
}

// ── Transfers ──────────────────────────────────────────────────

async function blindTransfer() {
    if (!parentCallSid.value || !transferTarget.value) {
        return;
    }

    error.value = '';

    try {
        const response = await fetch('/api/twilio/transfer/blind', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                callSid: parentCallSid.value,
                targetIdentity: transferTarget.value,
            }),
        });

        const data = await response.json();

        if (!data.success) {
            throw new Error('Transfer API returned failure');
        }

        // Agent A's browser leg will disconnect on its own once the caller
        // leg is redirected away from <Dial><Client>agent_1</Client></Dial>.
        status.value = 'Transferred (blind)';
    } catch (err) {
        error.value = `Blind transfer failed: ${err instanceof Error ? err.message : String(err)}`;
    }
}

async function startWarmTransfer() {
    if (!parentCallSid.value || !transferTarget.value) {
        return;
    }

    error.value = '';

    try {
        const response = await fetch('/api/twilio/transfer/start', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                callSid: parentCallSid.value,
                targetIdentity: transferTarget.value,
            }),
        });

        const data = await response.json();

        if (!data.success) {
            throw new Error('Warm transfer start API returned failure');
        }

        consultCallSid.value = data.consultCallSid;
        isConsulting.value = true;
        status.value = 'Consulting';
    } catch (err) {
        error.value = `Warm transfer start failed: ${err instanceof Error ? err.message : String(err)}`;
    }
}

async function completeWarmTransfer() {
    if (!parentCallSid.value || !consultCallSid.value) {
        return;
    }

    error.value = '';

    try {
        const response = await fetch('/api/twilio/transfer/complete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                parentCallSid: parentCallSid.value,
                consultCallSid: consultCallSid.value,
                targetIdentity: transferTarget.value,
            }),
        });

        const data = await response.json();

        if (!data.success) {
            throw new Error('Warm transfer complete API returned failure');
        }

        isConsulting.value = false;
        consultCallSid.value = '';
        status.value = 'Transferred (warm)';
    } catch (err) {
        error.value = `Warm transfer complete failed: ${err instanceof Error ? err.message : String(err)}`;
    }
}
</script>

<template>
    <Head title="Agent Console" />

    <div style="padding: 20px; font-family: monospace">
        <h1>Twilio Agent Console</h1>

        <p><strong>Status:</strong> {{ status }}</p>
        <p v-if="error" style="color: red"><strong>Error:</strong> {{ error }}</p>
        <p v-if="parentCallSid">
            <small>Parent Call SID: {{ parentCallSid }}</small>
        </p>

        <hr />

        <!-- Connection -->
        <div>
            <h2>Connection</h2>
            <div v-if="!isConnected">
                <label>
                    Identity:
                    <select v-model="identity">
                        <option v-for="agent in agents" :key="agent" :value="agent">{{ agent }}</option>
                    </select>
                </label>
                <button @click="connect">Connect</button>
            </div>
            <div v-else>
                <p>Connected as: {{ identity }}</p>
                <button @click="disconnect">Disconnect</button>
            </div>
        </div>

        <hr />

        <!-- Incoming Calls -->
        <div v-if="hasIncomingCall">
            <h2>Incoming Call</h2>
            <p>From: {{ callerNumber }}</p>
            <button @click="acceptCall">Accept</button>
            <button @click="rejectCall">Reject</button>
        </div>

        <!-- Outbound Calls -->
        <div v-if="isConnected && !isInCall && !hasIncomingCall && !isConsulting">
            <h2>Make a Call</h2>
            <label>
                Phone Number:
                <input v-model="phoneNumber" type="text" placeholder="+1234567890" />
            </label>
            <button @click="makeCall">Call</button>
        </div>

        <!-- Active Call -->
        <div v-if="isInCall">
            <h2>Active Call</h2>
            <p>In Call</p>
            <button @click="hangUp">Hang Up</button>
        </div>

        <!-- Transfer Controls -->
        <div v-if="isInCall || isConsulting">
            <hr />
            <h2>Transfer</h2>
            <div>
                <label>
                    Target Agent:
                    <select v-model="transferTarget">
                        <option v-for="agent in otherAgents" :key="agent" :value="agent">{{ agent }}</option>
                    </select>
                </label>
            </div>
            <div style="margin-top: 8px">
                <button :disabled="!canTransfer" @click="blindTransfer">Blind Transfer</button>
                <button :disabled="!canTransfer" @click="startWarmTransfer">Start Warm Transfer</button>
                <button :disabled="!canCompleteTransfer" @click="completeWarmTransfer">Complete Transfer</button>
            </div>
            <p v-if="isConsulting">
                <strong>Consulting with {{ transferTarget }}...</strong>
                <br />
                <small>Consult Call SID: {{ consultCallSid }}</small>
            </p>
        </div>
    </div>
</template>
