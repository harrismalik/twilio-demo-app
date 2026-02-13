<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Device, Call } from '@twilio/voice-sdk';
import { ref, computed, watch } from 'vue';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';

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

const statusVariant = computed(() => {
    if (status.value === 'Error') {
        return 'destructive';
    }
    if (status.value === 'Connected' || status.value === 'Ready') {
        return 'default';
    }
    return 'secondary';
});

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

    <div class="bg-background min-h-screen">
        <div class="mx-auto max-w-lg px-4 py-10">
            <!-- Header -->
            <div class="mb-6 flex items-center justify-between">
                <div>
                    <h1 class="text-foreground text-2xl font-semibold tracking-tight">Agent Console</h1>
                    <p class="text-muted-foreground text-sm">Twilio Voice SDK</p>
                </div>
                <Badge :variant="statusVariant">{{ status }}</Badge>
            </div>

            <!-- Error -->
            <Alert v-if="error" variant="destructive" class="mb-4">
                <AlertDescription>{{ error }}</AlertDescription>
            </Alert>

            <!-- Connection -->
            <Card>
                <CardHeader>
                    <CardTitle>Connection</CardTitle>
                    <CardDescription v-if="isConnected">
                        Signed in as <span class="font-medium">{{ identity }}</span>
                    </CardDescription>
                    <CardDescription v-else>Select an identity and connect to start.</CardDescription>
                </CardHeader>
                <CardContent>
                    <div v-if="!isConnected" class="flex items-center gap-3">
                        <Select v-model="identity">
                            <SelectTrigger class="w-[160px]">
                                <SelectValue placeholder="Identity" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="agent in agents" :key="agent" :value="agent">
                                    {{ agent }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <Button @click="connect">Connect</Button>
                    </div>
                    <Button v-else variant="outline" @click="disconnect">Disconnect</Button>
                </CardContent>
            </Card>

            <!-- Incoming Call -->
            <Card v-if="hasIncomingCall" class="mt-4 border-ring">
                <CardHeader>
                    <CardTitle>Incoming Call</CardTitle>
                    <CardDescription>From {{ callerNumber }}</CardDescription>
                </CardHeader>
                <CardContent class="flex gap-3">
                    <Button @click="acceptCall">Accept</Button>
                    <Button variant="destructive" @click="rejectCall">Reject</Button>
                </CardContent>
            </Card>

            <!-- Dial Pad -->
            <Card v-if="isConnected && !isInCall && !hasIncomingCall && !isConsulting" class="mt-4">
                <CardHeader>
                    <CardTitle>Make a Call</CardTitle>
                    <CardDescription>Enter a phone number in E.164 format.</CardDescription>
                </CardHeader>
                <CardContent class="flex gap-3">
                    <Input v-model="phoneNumber" type="tel" placeholder="+1234567890" class="flex-1" />
                    <Button :disabled="!phoneNumber.trim()" @click="makeCall">Call</Button>
                </CardContent>
            </Card>

            <!-- Active Call -->
            <Card v-if="isInCall" class="mt-4">
                <CardHeader>
                    <CardTitle>Active Call</CardTitle>
                    <CardDescription v-if="parentCallSid" class="font-mono text-xs">
                        {{ parentCallSid }}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <Button variant="destructive" @click="hangUp">Hang Up</Button>
                </CardContent>
            </Card>

            <!-- Transfer -->
            <Card v-if="isInCall || isConsulting" class="mt-4">
                <CardHeader>
                    <CardTitle>Transfer</CardTitle>
                    <CardDescription v-if="isConsulting">
                        Consulting with <span class="font-medium">{{ transferTarget }}</span>
                    </CardDescription>
                    <CardDescription v-else>Transfer the active call to another agent.</CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <Select v-model="transferTarget">
                        <SelectTrigger>
                            <SelectValue placeholder="Target agent" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="agent in otherAgents" :key="agent" :value="agent">
                                {{ agent }}
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <Separator />

                    <div class="flex flex-wrap gap-3">
                        <Button variant="outline" :disabled="!canTransfer" @click="blindTransfer">
                            Blind Transfer
                        </Button>
                        <Button variant="outline" :disabled="!canTransfer" @click="startWarmTransfer">
                            Start Warm Transfer
                        </Button>
                        <Button :disabled="!canCompleteTransfer" @click="completeWarmTransfer">
                            Complete Transfer
                        </Button>
                    </div>

                    <p v-if="isConsulting" class="text-muted-foreground font-mono text-xs">
                        Consult SID: {{ consultCallSid }}
                    </p>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
