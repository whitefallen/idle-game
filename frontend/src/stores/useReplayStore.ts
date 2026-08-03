import { create } from 'zustand';

/**
 * Combat replay playback position.
 *
 * Genuinely browser-only state: the server has no opinion about where a player
 * has scrubbed to. This is the kind of state Zustand is for — no server data is
 * mirrored here.
 */
interface ReplayState {
  frameIndex: number;
  playing: boolean;
  speed: number;

  setFrame: (index: number) => void;
  play: () => void;
  pause: () => void;
  toggle: () => void;
  restart: () => void;
  skipTo: (index: number) => void;
  setSpeed: (speed: number) => void;
  reset: () => void;
}

export const REPLAY_SPEEDS = [1, 2, 4] as const;

/** Milliseconds per frame at 1x. */
export const BASE_FRAME_DELAY_MS = 420;

export const useReplayStore = create<ReplayState>((set) => ({
  frameIndex: 0,
  playing: true,
  speed: 1,

  setFrame: (index) => set({ frameIndex: index }),
  play: () => set({ playing: true }),
  pause: () => set({ playing: false }),
  toggle: () => set((state) => ({ playing: !state.playing })),
  restart: () => set({ frameIndex: 0, playing: true }),

  // Skipping stops playback: a player who jumps to the end wants the result,
  // not to be dropped back into an animation.
  skipTo: (index) => set({ frameIndex: index, playing: false }),

  setSpeed: (speed) => set({ speed }),
  reset: () => set({ frameIndex: 0, playing: true, speed: 1 }),
}));
