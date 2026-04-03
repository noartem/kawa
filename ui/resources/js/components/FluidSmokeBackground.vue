<script setup lang="ts">
import type { PreparedTextWithSegments } from '@chenglou/pretext';
import { onMounted, onUnmounted, ref } from 'vue';

type FontStyle = 'normal' | 'italic';
type FontWeight = 300 | 500 | 800;

type PaletteEntry = {
    brightness: number;
    char: string;
    style: FontStyle;
    weight: FontWeight;
    width: number;
};

type PrepareWithSegments = (
    text: string,
    font: string,
) => PreparedTextWithSegments;

type Emitter = {
    cx: number;
    cy: number;
    freq: number;
    orbitR: number;
    phase: number;
    strength: number;
};

const rootElement = ref<HTMLDivElement | null>(null);

const FONT_SIZE = 14;
const LINE_HEIGHT = 17;
const FONT_FAMILY = 'Georgia, Palatino, "Times New Roman", serif';
const CHARSET = ` ${Array.from({ length: 94 }, (_, index) => String.fromCharCode(index + 33)).join('')}`;
const FONT_WEIGHTS: FontWeight[] = [300, 500, 800];
const FONT_STYLES: FontStyle[] = ['normal', 'italic'];
const FRAME_INTERVAL = 1000 / 30;

const emitters: Emitter[] = [
    { cx: 0.25, cy: 0.4, orbitR: 0.14, freq: 0.3, phase: 0, strength: 0.18 },
    { cx: 0.7, cy: 0.35, orbitR: 0.1, freq: 0.25, phase: 2.1, strength: 0.15 },
    { cx: 0.45, cy: 0.65, orbitR: 0.16, freq: 0.35, phase: 4.2, strength: 0.2 },
    { cx: 0.8, cy: 0.6, orbitR: 0.08, freq: 0.4, phase: 1, strength: 0.14 },
];

let prepareWithSegments: PrepareWithSegments | null = null;
let brightnessCanvas: HTMLCanvasElement | null = null;
let brightnessContext: CanvasRenderingContext2D | null = null;
let palette: PaletteEntry[] = [];
let rowElements: HTMLDivElement[] = [];
let density = new Float32Array(0);
let tempDensity = new Float32Array(0);
let animationFrameId: number | null = null;
let resizeTimerId: number | null = null;
let mutationObserver: MutationObserver | null = null;
let lastRenderedAt = 0;
let columns = 0;
let rows = 0;
let averageCharacterWidth = FONT_SIZE * 0.6;
let spaceWidth = FONT_SIZE * 0.27;
let aspectRatio = averageCharacterWidth / LINE_HEIGHT;
let squaredAspectRatio = aspectRatio * aspectRatio;
let prefersReducedMotion = false;
let motionQuery: MediaQueryList | null = null;
let isUnmounted = false;

function createFont(weight: FontWeight, style: FontStyle): string {
    const italicPrefix = style === 'italic' ? 'italic ' : '';

    return `${italicPrefix}${weight} ${FONT_SIZE}px ${FONT_FAMILY}`;
}

function estimateBrightness(character: string, font: string): number {
    if (!brightnessContext) {
        return 0;
    }

    brightnessContext.clearRect(0, 0, 28, 28);
    brightnessContext.font = font;
    brightnessContext.fillStyle = '#fff';
    brightnessContext.textBaseline = 'middle';
    brightnessContext.fillText(character, 1, 14);

    const imageData = brightnessContext.getImageData(0, 0, 28, 28).data;
    let sum = 0;

    for (let index = 3; index < imageData.length; index += 4) {
        sum += imageData[index] ?? 0;
    }

    return sum / (255 * 784);
}

function buildPalette(): void {
    if (!prepareWithSegments) {
        return;
    }

    const nextPalette: PaletteEntry[] = [];

    for (const style of FONT_STYLES) {
        for (const weight of FONT_WEIGHTS) {
            const font = createFont(weight, style);

            for (const char of CHARSET) {
                if (char === ' ') {
                    continue;
                }

                const prepared = prepareWithSegments(char, font);
                const width = prepared.widths[0] ?? 0;

                if (width <= 0) {
                    continue;
                }

                nextPalette.push({
                    brightness: estimateBrightness(char, font),
                    char,
                    style,
                    weight,
                    width,
                });
            }
        }
    }

    const maxBrightness = Math.max(
        ...nextPalette.map((entry) => entry.brightness),
    );

    if (maxBrightness > 0) {
        for (const entry of nextPalette) {
            entry.brightness /= maxBrightness;
        }
    }

    nextPalette.sort((left, right) => left.brightness - right.brightness);
    palette = nextPalette;

    if (palette.length === 0) {
        return;
    }

    averageCharacterWidth =
        palette.reduce((sum, entry) => sum + entry.width, 0) / palette.length;
    aspectRatio = averageCharacterWidth / LINE_HEIGHT;
    squaredAspectRatio = aspectRatio * aspectRatio;
    spaceWidth = FONT_SIZE * 0.27;
}

function getMaxColumns(): number {
    return window.innerWidth < 768 ? 96 : 180;
}

function getMaxRows(): number {
    return window.innerWidth < 768 ? 52 : 72;
}

function initializeGrid(): void {
    if (!rootElement.value || palette.length === 0) {
        return;
    }

    columns = Math.max(
        1,
        Math.min(
            getMaxColumns(),
            Math.floor(window.innerWidth / averageCharacterWidth),
        ),
    );
    rows = Math.max(
        1,
        Math.min(getMaxRows(), Math.floor(window.innerHeight / LINE_HEIGHT)),
    );
    density = new Float32Array(columns * rows);
    tempDensity = new Float32Array(columns * rows);
    rowElements = [];
    rootElement.value.innerHTML = '';

    for (let rowIndex = 0; rowIndex < rows; rowIndex++) {
        const row = document.createElement('div');

        row.className = 'fluid-smoke__row';
        row.style.height = `${LINE_HEIGHT}px`;
        row.style.lineHeight = `${LINE_HEIGHT}px`;
        rootElement.value.append(row);
        rowElements.push(row);
    }

    updateThemeMode();
}

function getVelocity(
    column: number,
    row: number,
    time: number,
): [number, number] {
    const normalizedX = column / columns;
    const normalizedY = row / rows;

    const velocityX =
        Math.sin(normalizedY * 6.28 + time * 0.3) * 2 +
        Math.cos((normalizedX + normalizedY) * 12.5 + time * 0.55) * 0.7 +
        Math.sin(normalizedX * 25 + normalizedY * 18 + time * 0.8) * 0.25;
    let velocityY =
        Math.cos(normalizedX * 5 + time * 0.4) * 1.5 +
        Math.sin((normalizedX - normalizedY) * 10 + time * 0.4) * 0.8 +
        Math.cos(normalizedX * 18 - normalizedY * 25 + time * 0.7) * 0.25;

    velocityY *= aspectRatio;

    return [velocityX, velocityY];
}

function updateSimulation(time: number): void {
    for (let row = 0; row < rows; row++) {
        for (let column = 0; column < columns; column++) {
            const [velocityX, velocityY] = getVelocity(column, row, time);
            const sampleX = Math.max(
                0,
                Math.min(columns - 1.001, column - velocityX),
            );
            const sampleY = Math.max(
                0,
                Math.min(rows - 1.001, row - velocityY),
            );
            const x0 = sampleX | 0;
            const y0 = sampleY | 0;
            const x1 = Math.min(x0 + 1, columns - 1);
            const y1 = Math.min(y0 + 1, rows - 1);
            const fractionalX = sampleX - x0;
            const fractionalY = sampleY - y0;

            tempDensity[row * columns + column] =
                density[y0 * columns + x0] *
                    (1 - fractionalX) *
                    (1 - fractionalY) +
                density[y0 * columns + x1] * fractionalX * (1 - fractionalY) +
                density[y1 * columns + x0] * (1 - fractionalX) * fractionalY +
                density[y1 * columns + x1] * fractionalX * fractionalY;
        }
    }

    [density, tempDensity] = [tempDensity, density];

    for (let row = 1; row < rows - 1; row++) {
        for (let column = 1; column < columns - 1; column++) {
            const index = row * columns + column;
            const average =
                (density[index - 1] +
                    density[index + 1] +
                    (density[index - columns] + density[index + columns]) *
                        squaredAspectRatio) /
                (2 + 2 * squaredAspectRatio);

            tempDensity[index] = density[index] * 0.92 + average * 0.08;
        }
    }

    [density, tempDensity] = [tempDensity, density];

    const spread = 4;

    for (const emitter of emitters) {
        const emitterX =
            (emitter.cx +
                Math.cos(time * emitter.freq + emitter.phase) *
                    emitter.orbitR) *
            columns;
        const emitterY =
            (emitter.cy +
                Math.sin(time * emitter.freq * 0.7 + emitter.phase) *
                    emitter.orbitR *
                    0.8) *
            rows;
        const emitterColumn = emitterX | 0;
        const emitterRow = emitterY | 0;

        for (let rowOffset = -spread; rowOffset <= spread; rowOffset++) {
            for (
                let columnOffset = -spread;
                columnOffset <= spread;
                columnOffset++
            ) {
                const row = emitterRow + rowOffset;
                const column = emitterColumn + columnOffset;

                if (row < 0 || row >= rows || column < 0 || column >= columns) {
                    continue;
                }

                const scaledRowOffset = rowOffset / aspectRatio;
                const distance = Math.sqrt(
                    scaledRowOffset * scaledRowOffset +
                        columnOffset * columnOffset,
                );
                const strength = Math.max(0, 1 - distance / (spread + 1));
                const index = row * columns + column;

                density[index] = Math.min(
                    1,
                    density[index] + strength * emitter.strength,
                );
            }
        }
    }

    for (let index = 0; index < columns * rows; index++) {
        density[index] *= 0.984;
    }
}

function findBestMatch(
    targetBrightness: number,
    targetWidth: number,
    column: number,
    row: number,
    time: number,
): PaletteEntry {
    let lowerBound = 0;
    let upperBound = palette.length - 1;

    while (lowerBound < upperBound) {
        const middle = (lowerBound + upperBound) >> 1;

        if (palette[middle]?.brightness ?? 0 < targetBrightness) {
            lowerBound = middle + 1;
        } else {
            upperBound = middle;
        }
    }

    let bestEntry = palette[lowerBound] ?? palette[0]!;
    let bestScore = Number.POSITIVE_INFINITY;
    const candidates: Array<{ entry: PaletteEntry; score: number }> = [];

    for (
        let index = Math.max(0, lowerBound - 40);
        index < Math.min(palette.length, lowerBound + 40);
        index++
    ) {
        const entry = palette[index]!;
        const score =
            Math.abs(entry.brightness - targetBrightness) * 2.5 +
            Math.abs(entry.width - targetWidth) / targetWidth;

        candidates.push({ entry, score });

        if (score < bestScore) {
            bestEntry = entry;
            bestScore = score;
        }
    }

    candidates.sort((left, right) => left.score - right.score);

    const variedMatchCount = Math.min(24, candidates.length);

    if (variedMatchCount > 1) {
        const timeBucket = Math.floor(time * 5);
        const variedIndex =
            Math.abs(
                (column * 73856093) ^
                    (row * 19349663) ^
                    (timeBucket * 83492791),
            ) % variedMatchCount;

        return candidates[variedIndex]?.entry ?? bestEntry;
    }

    return bestEntry;
}

function escapeCharacter(character: string): string {
    if (character === '&') {
        return '&amp;';
    }

    if (character === '<') {
        return '&lt;';
    }

    if (character === '>') {
        return '&gt;';
    }

    return character;
}

function getGlyphClass(weight: FontWeight, style: FontStyle): string {
    const weightClass =
        weight === 300
            ? 'fluid-smoke__weight-300'
            : weight === 500
              ? 'fluid-smoke__weight-500'
              : 'fluid-smoke__weight-800';

    if (style === 'italic') {
        return `${weightClass} fluid-smoke__italic`;
    }

    return weightClass;
}

function renderFrame(now: number): void {
    if (
        !rootElement.value ||
        palette.length === 0 ||
        columns === 0 ||
        rows === 0
    ) {
        return;
    }

    if (now - lastRenderedAt < FRAME_INTERVAL) {
        animationFrameId = window.requestAnimationFrame(renderFrame);

        return;
    }

    lastRenderedAt = now;

    const time = now / 1000;

    updateSimulation(time);

    const targetCellWidth = window.innerWidth / columns;
    const rowWidths = new Array<number>(rows).fill(0);

    for (let row = 0; row < rows; row++) {
        let html = '';
        let width = 0;

        for (let column = 0; column < columns; column++) {
            const brightness = density[row * columns + column] ?? 0;

            if (brightness < 0.025) {
                html += ' ';
                width += spaceWidth;

                continue;
            }

            const match = findBestMatch(
                brightness,
                targetCellWidth,
                column,
                row,
                time,
            );
            const alphaIndex = Math.max(
                1,
                Math.min(10, Math.round(brightness * 10)),
            );

            html += `<span class="${getGlyphClass(match.weight, match.style)} fluid-smoke__alpha-${alphaIndex}">${escapeCharacter(match.char)}</span>`;
            width += match.width;
        }

        rowElements[row]!.innerHTML = html;
        rowWidths[row] = width;
    }

    const maxRowWidth = Math.max(...rowWidths);
    const blockOffset = Math.max(0, (window.innerWidth - maxRowWidth) / 2);

    for (let row = 0; row < rows; row++) {
        rowElements[row]!.style.paddingLeft =
            `${blockOffset + (maxRowWidth - rowWidths[row]!) / 2}px`;
    }

    animationFrameId = window.requestAnimationFrame(renderFrame);
}

function startAnimation(): void {
    if (prefersReducedMotion || animationFrameId !== null || document.hidden) {
        return;
    }

    animationFrameId = window.requestAnimationFrame(renderFrame);
}

function stopAnimation(): void {
    if (animationFrameId === null) {
        return;
    }

    window.cancelAnimationFrame(animationFrameId);
    animationFrameId = null;
}

function handleVisibilityChange(): void {
    if (document.hidden) {
        stopAnimation();

        return;
    }

    startAnimation();
}

function renderStaticFrame(): void {
    renderFrame(1000);
    stopAnimation();
}

function updateThemeMode(): void {
    if (!rootElement.value) {
        return;
    }

    rootElement.value.dataset.theme =
        document.documentElement.classList.contains('dark') ? 'dark' : 'light';
}

function handleResize(): void {
    if (resizeTimerId !== null) {
        window.clearTimeout(resizeTimerId);
    }

    resizeTimerId = window.setTimeout(() => {
        initializeGrid();

        if (prefersReducedMotion) {
            renderStaticFrame();
        }
    }, 150);
}

function handleMotionPreferenceChange(event: MediaQueryListEvent): void {
    prefersReducedMotion = event.matches;

    if (prefersReducedMotion) {
        renderStaticFrame();

        return;
    }

    startAnimation();
}

onMounted(async () => {
    isUnmounted = false;
    motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
    prefersReducedMotion = motionQuery.matches;

    const module = await import('@chenglou/pretext');

    if (isUnmounted) {
        return;
    }

    prepareWithSegments = module.prepareWithSegments;
    brightnessCanvas = document.createElement('canvas');
    brightnessCanvas.width = 28;
    brightnessCanvas.height = 28;
    brightnessContext = brightnessCanvas.getContext('2d', {
        willReadFrequently: true,
    });

    if (!brightnessContext) {
        return;
    }

    if ('fonts' in document) {
        await document.fonts.ready;

        if (isUnmounted) {
            return;
        }
    }

    buildPalette();
    initializeGrid();
    updateThemeMode();

    if (prefersReducedMotion) {
        renderStaticFrame();
    } else {
        startAnimation();
    }

    window.addEventListener('resize', handleResize);
    document.addEventListener('visibilitychange', handleVisibilityChange);
    motionQuery.addEventListener('change', handleMotionPreferenceChange);

    mutationObserver = new MutationObserver(() => {
        updateThemeMode();
    });
    mutationObserver.observe(document.documentElement, {
        attributeFilter: ['class'],
        attributes: true,
    });
});

onUnmounted(() => {
    isUnmounted = true;
    stopAnimation();

    if (resizeTimerId !== null) {
        window.clearTimeout(resizeTimerId);
    }

    mutationObserver?.disconnect();
    motionQuery?.removeEventListener('change', handleMotionPreferenceChange);
    window.removeEventListener('resize', handleResize);
    document.removeEventListener('visibilitychange', handleVisibilityChange);
});
</script>

<template>
    <div ref="rootElement" aria-hidden="true" class="fluid-smoke" />
</template>

<style>
.fluid-smoke {
    position: absolute;
    inset: 0;
    overflow: hidden;
    pointer-events: none;
    user-select: none;
    background:
        radial-gradient(
            circle at 20% 20%,
            rgb(var(--fluid-smoke-rgb) / 0.12),
            transparent 32%
        ),
        radial-gradient(
            circle at 78% 24%,
            rgb(var(--fluid-smoke-rgb) / 0.08),
            transparent 28%
        ),
        radial-gradient(
            circle at 52% 78%,
            rgb(var(--fluid-smoke-rgb) / 0.1),
            transparent 36%
        ),
        var(--fluid-smoke-bg);
}

.fluid-smoke[data-theme='dark'] {
    --fluid-smoke-bg: #05060a;
    --fluid-smoke-rgb: 196 163 90;
}

.fluid-smoke[data-theme='light'] {
    --fluid-smoke-bg: #f8f4ed;
    --fluid-smoke-rgb: 70 62 55;
}

.fluid-smoke::after {
    content: '';
    position: absolute;
    inset: 0;
    background:
        linear-gradient(
            to bottom,
            rgb(255 255 255 / 0.04),
            transparent 25%,
            transparent 72%,
            rgb(0 0 0 / 0.08)
        ),
        linear-gradient(
            to right,
            rgb(0 0 0 / 0.06),
            transparent 16%,
            transparent 84%,
            rgb(0 0 0 / 0.08)
        );
}

.fluid-smoke__row {
    position: relative;
    overflow: hidden;
    white-space: pre;
    font-family: Georgia, Palatino, 'Times New Roman', serif;
    font-size: 14px;
    letter-spacing: -0.01em;
}

.fluid-smoke__weight-300 {
    font-weight: 300;
}

.fluid-smoke__weight-500 {
    font-weight: 500;
}

.fluid-smoke__weight-800 {
    font-weight: 800;
}

.fluid-smoke__italic {
    font-style: italic;
}

.fluid-smoke__alpha-1 {
    color: rgb(var(--fluid-smoke-rgb) / 0.08);
}

.fluid-smoke__alpha-2 {
    color: rgb(var(--fluid-smoke-rgb) / 0.14);
}

.fluid-smoke__alpha-3 {
    color: rgb(var(--fluid-smoke-rgb) / 0.2);
}

.fluid-smoke__alpha-4 {
    color: rgb(var(--fluid-smoke-rgb) / 0.28);
}

.fluid-smoke__alpha-5 {
    color: rgb(var(--fluid-smoke-rgb) / 0.38);
}

.fluid-smoke__alpha-6 {
    color: rgb(var(--fluid-smoke-rgb) / 0.5);
}

.fluid-smoke__alpha-7 {
    color: rgb(var(--fluid-smoke-rgb) / 0.62);
}

.fluid-smoke__alpha-8 {
    color: rgb(var(--fluid-smoke-rgb) / 0.74);
}

.fluid-smoke__alpha-9 {
    color: rgb(var(--fluid-smoke-rgb) / 0.86);
}

.fluid-smoke__alpha-10 {
    color: rgb(var(--fluid-smoke-rgb) / 0.96);
}
</style>
