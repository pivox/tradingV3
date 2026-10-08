import { klinesWindows } from './klinesWindows';

const STEP = 60000;

test('windows are contiguous, non overlapping and hold at most maxCandles candles', () => {
    const windows = klinesWindows(0, 1199 * STEP, STEP, 500);
    expect(windows).toEqual([
        { from: 0, to: 499 * STEP },
        { from: 500 * STEP, to: 999 * STEP },
        { from: 1000 * STEP, to: 1199 * STEP },
    ]);
    windows.forEach((w) => expect((w.to - w.from) / STEP + 1).toBeLessThanOrEqual(500));
});

test('every aligned candle belongs to exactly one window', () => {
    const windows = klinesWindows(0, 1234 * STEP, STEP, 500);
    for (let t = 0; t <= 1234 * STEP; t += STEP) {
        expect(windows.filter((w) => t >= w.from && t <= w.to)).toHaveLength(1);
    }
});
