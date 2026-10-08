// Fenêtres demi-ouvertes et sans chevauchement pour paginer /api/klines.
// Chaque page couvre `maxCandles` bougies : [from, from + maxCandles * step),
// soit `to = from + (maxCandles - 1) * step` inclus ; la suivante démarre à `to + step`.
export const klinesWindows = (startMs, endMs, stepMs, maxCandles) => {
    const windows = [];
    for (let from = startMs; from <= endMs; from += maxCandles * stepMs) {
        windows.push({ from, to: Math.min(from + (maxCandles - 1) * stepMs, endMs) });
    }
    return windows;
};
