// Genera un "ding" corto con Web Audio API (sin depender de un archivo .mp3).
// Coalescencia: si llegan varios mensajes casi al mismo tiempo, solo suena una
// vez; si hay una separación real entre mensajes, vuelve a sonar.
const BURST_WINDOW_MS = 1500;

let audioContext = null;
let lastPlayedAt = 0;

function getAudioContext() {
    if (audioContext) return audioContext;
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return null;
    audioContext = new Ctx();
    return audioContext;
}

function tone(ctx, freq, startTime, duration, peakGain) {
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = 'sine';
    osc.frequency.value = freq;
    gain.gain.setValueAtTime(0.0001, startTime);
    gain.gain.linearRampToValueAtTime(peakGain, startTime + 0.01);
    gain.gain.exponentialRampToValueAtTime(0.0001, startTime + duration);
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.start(startTime);
    osc.stop(startTime + duration);
}

export function playNotificationSound() {
    const now = Date.now();
    if (now - lastPlayedAt < BURST_WINDOW_MS) return;
    lastPlayedAt = now;

    const ctx = getAudioContext();
    if (!ctx) return;
    if (ctx.state === 'suspended') ctx.resume().catch(() => {});

    const t0 = ctx.currentTime;
    tone(ctx, 880, t0, 0.12, 0.18);
    tone(ctx, 1175, t0 + 0.09, 0.16, 0.18);
}
