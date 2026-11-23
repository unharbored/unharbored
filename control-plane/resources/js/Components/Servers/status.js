import { tone } from '../ui/tone.js';

/** The colour of an operation's status in a list. */
export const statusClass = (s) => (s === 'failed' ? tone.bad : s === 'queued' || s === 'running' ? tone.warn : s === 'succeeded' ? tone.faint : '');
