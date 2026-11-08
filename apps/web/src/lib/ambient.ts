"use client";

/**
 * Milestone 6 (PRD 4.9): per-user ambient sound — rain, brown noise, cafe
 * and fireplace, all synthesized live with the Web Audio API. No audio
 * files means no licensing to track (docs/credits.md stays empty of assets)
 * and nothing to download; each "scene" is a noise buffer plus a filter
 * chain, with randomized events (cafe clinks, fireplace crackles) layered
 * on top. Local only: kind and volume persist in localStorage.
 */

export type AmbientKind = "off" | "rain" | "brown" | "cafe" | "fireplace";

export const AMBIENT_CHOICES: { kind: AmbientKind; label: string }[] = [
  { kind: "off", label: "Off" },
  { kind: "rain", label: "Rain" },
  { kind: "brown", label: "Brown noise" },
  { kind: "cafe", label: "Cafe" },
  { kind: "fireplace", label: "Fireplace" },
];

const KIND_KEY = "deskmates.ambient.kind";
const VOLUME_KEY = "deskmates.ambient.volume";

function readKind(): AmbientKind {
  try {
    const stored = localStorage.getItem(KIND_KEY);
    return AMBIENT_CHOICES.some((choice) => choice.kind === stored)
      ? (stored as AmbientKind)
      : "off";
  } catch {
    return "off";
  }
}

function readVolume(): number {
  try {
    const stored = Number(localStorage.getItem(VOLUME_KEY));
    return Number.isFinite(stored) && stored > 0 ? Math.min(stored, 1) : 0.5;
  } catch {
    return 0.5;
  }
}

/** Shared noise buffers are per-context, so they live on the engine. */
function whiteNoise(ctx: AudioContext): AudioBuffer {
  const buffer = ctx.createBuffer(1, ctx.sampleRate * 4, ctx.sampleRate);
  const data = buffer.getChannelData(0);
  for (let i = 0; i < data.length; i++) {
    data[i] = (Math.random() * 2 - 1) * 0.6;
  }
  return buffer;
}

function brownNoise(ctx: AudioContext): AudioBuffer {
  const buffer = ctx.createBuffer(1, ctx.sampleRate * 4, ctx.sampleRate);
  const data = buffer.getChannelData(0);
  let last = 0;
  for (let i = 0; i < data.length; i++) {
    const white = Math.random() * 2 - 1;
    last = (last + 0.02 * white) / 1.02;
    data[i] = Math.max(-1, Math.min(1, last * 3.5));
  }
  return buffer;
}

function noiseSource(ctx: AudioContext, buffer: AudioBuffer): AudioBufferSourceNode {
  const source = ctx.createBufferSource();
  source.buffer = buffer;
  source.loop = true;
  return source;
}

class AmbientEngine {
  private ctx: AudioContext | null = null;
  private master: GainNode | null = null;
  private white: AudioBuffer | null = null;
  private brown: AudioBuffer | null = null;
  /** Everything currently sounding, so switching scenes can tear down. */
  private live: { stop: () => void }[] = [];
  private kind: AmbientKind = readKind();
  private volume: number = readVolume();

  current(): AmbientKind {
    return this.kind;
  }

  currentVolume(): number {
    return this.volume;
  }

  setVolume(volume: number): void {
    this.volume = Math.max(0, Math.min(1, volume));
    if (this.master && this.ctx) {
      this.master.gain.setTargetAtTime(this.volume, this.ctx.currentTime, 0.05);
    }
    try {
      localStorage.setItem(VOLUME_KEY, String(this.volume));
    } catch {
      // Storage unavailable — the slider still works for this session.
    }
    notify();
  }

  /** Switch scenes (or "off"); tears down whatever was playing. */
  setKind(kind: AmbientKind): void {
    this.kind = kind;
    try {
      localStorage.setItem(KIND_KEY, kind);
    } catch {
      // Same as above: session-local is fine.
    }
    notify();

    this.teardown();

    if (kind === "off") return;

    const ctx = this.ensure();
    if (!ctx) return;

    this.master = ctx.createGain();
    this.master.gain.value = this.volume;
    this.master.connect(ctx.destination);

    switch (kind) {
      case "rain":
        this.startRain(ctx);
        break;
      case "brown":
        this.startBrown(ctx);
        break;
      case "cafe":
        this.startCafe(ctx);
        break;
      case "fireplace":
        this.startFireplace(ctx);
        break;
    }
  }

  private ensure(): AudioContext | null {
    try {
      this.ctx ??= new AudioContext();
      if (this.ctx.state === "suspended") {
        void this.ctx.resume();
      }
      return this.ctx;
    } catch {
      return null;
    }
  }

  private teardown(): void {
    for (const entry of this.live) {
      try {
        entry.stop();
      } catch {
        // Already stopped — teardown is best-effort.
      }
    }
    this.live = [];

    if (this.master) {
      try {
        this.master.disconnect();
      } catch {
        // Detached already.
      }
      this.master = null;
    }
  }

  /** White noise shaped into a steady downpour. */
  private startRain(ctx: AudioContext): void {
    const source = noiseSource(ctx, this.white ??= whiteNoise(ctx));
    const highpass = ctx.createBiquadFilter();
    highpass.type = "highpass";
    highpass.frequency.value = 320;
    const lowpass = ctx.createBiquadFilter();
    lowpass.type = "lowpass";
    lowpass.frequency.value = 6200;

    source.connect(highpass).connect(lowpass).connect(this.master!);
    source.start();
    this.live.push({ stop: () => source.stop() });
  }

  /** The PRD's brown noise: deep, low-frequency wash. */
  private startBrown(ctx: AudioContext): void {
    const source = noiseSource(ctx, this.brown ??= brownNoise(ctx));
    const lowpass = ctx.createBiquadFilter();
    lowpass.type = "lowpass";
    lowpass.frequency.value = 900;

    source.connect(lowpass).connect(this.master!);
    source.start();
    this.live.push({ stop: () => source.stop() });
  }

  /** Murmur swell (bandpassed brown noise) plus random cup clinks. */
  private startCafe(ctx: AudioContext): void {
    const source = noiseSource(ctx, this.brown ??= brownNoise(ctx));
    const bandpass = ctx.createBiquadFilter();
    bandpass.type = "bandpass";
    bandpass.frequency.value = 550;
    bandpass.Q.value = 0.6;

    const swell = ctx.createGain();
    swell.gain.value = 0.8;
    // Slow amplitude wander so the murmur breathes like a room does.
    const lfo = ctx.createOscillator();
    const lfoDepth = ctx.createGain();
    lfo.frequency.value = 0.13;
    lfoDepth.gain.value = 0.35;
    lfo.connect(lfoDepth).connect(swell.gain);

    source.connect(bandpass).connect(swell).connect(this.master!);
    source.start();
    lfo.start();
    this.live.push({ stop: () => { source.stop(); lfo.stop(); } });

    this.scheduleRandom(ctx, 1800, 5500, () => this.clink(ctx));
  }

  /** Low wash with irregular crackling pops. */
  private startFireplace(ctx: AudioContext): void {
    const source = noiseSource(ctx, this.brown ??= brownNoise(ctx));
    const lowpass = ctx.createBiquadFilter();
    lowpass.type = "lowpass";
    lowpass.frequency.value = 700;

    source.connect(lowpass).connect(this.master!);
    source.start();
    this.live.push({ stop: () => source.stop() });

    this.scheduleRandom(ctx, 250, 1400, () => this.crackle(ctx));
  }

  /** A short high sine — a cup meeting a saucer. */
  private clink(ctx: AudioContext): void {
    const at = ctx.currentTime;
    const oscillator = ctx.createOscillator();
    const gain = ctx.createGain();
    oscillator.type = "sine";
    oscillator.frequency.value = 2300 + Math.random() * 700;
    gain.gain.setValueAtTime(0.0001, at);
    gain.gain.exponentialRampToValueAtTime(0.08, at + 0.005);
    gain.gain.exponentialRampToValueAtTime(0.0001, at + 0.18);
    oscillator.connect(gain).connect(this.master!);
    oscillator.start(at);
    oscillator.stop(at + 0.25);
  }

  /** A brief filtered-noise pop — a log giving way. */
  private crackle(ctx: AudioContext): void {
    const at = ctx.currentTime;
    const source = noiseSource(ctx, this.white ??= whiteNoise(ctx));
    const highpass = ctx.createBiquadFilter();
    highpass.type = "highpass";
    highpass.frequency.value = 1400;
    const gain = ctx.createGain();
    const duration = 0.03 + Math.random() * 0.05;

    gain.gain.setValueAtTime(0.0001, at);
    gain.gain.exponentialRampToValueAtTime(0.12 + Math.random() * 0.2, at + 0.004);
    gain.gain.exponentialRampToValueAtTime(0.0001, at + duration);

    source.connect(highpass).connect(gain).connect(this.master!);
    source.start(at, Math.random() * 3);
    source.stop(at + duration + 0.05);
  }

  /** Recursively schedule events at random intervals until torn down. */
  private scheduleRandom(
    ctx: AudioContext,
    minMs: number,
    maxMs: number,
    fire: () => void,
  ): void {
    let timer: ReturnType<typeof setTimeout> | null = null;
    let cancelled = false;

    const tick = () => {
      if (cancelled) return;
      fire();
      timer = setTimeout(tick, minMs + Math.random() * (maxMs - minMs));
    };
    timer = setTimeout(tick, minMs);

    this.live.push({
      stop: () => {
        cancelled = true;
        if (timer !== null) clearTimeout(timer);
      },
    });
  }
}

type Listener = () => void;

const listeners = new Set<Listener>();

function notify(): void {
  for (const listener of listeners) {
    listener();
  }
}

/**
 * React-facing subscription (useSyncExternalStore): notifies on this tab's
 * writes and on other tabs' `storage` events.
 */
export function subscribeAmbient(onStoreChange: () => void): () => void {
  listeners.add(onStoreChange);

  const onStorage = (event: StorageEvent) => {
    if (event.key === KIND_KEY || event.key === VOLUME_KEY || event.key === null) {
      onStoreChange();
    }
  };
  window.addEventListener("storage", onStorage);

  return () => {
    listeners.delete(onStoreChange);
    window.removeEventListener("storage", onStorage);
  };
}

/** One engine per page: the room's ambient button drives this singleton. */
export const ambient = new AmbientEngine();
