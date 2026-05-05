/**
 * Generate PNG icons (192, 512, maskable 512) from icon.svg.
 * Run once: `node deploy/generate-icons.mjs`
 */
import sharp from 'sharp';
import fs from 'node:fs';
import path from 'node:path';
import url from 'node:url';

const __dirname = path.dirname(url.fileURLToPath(import.meta.url));
const svgPath = path.resolve(__dirname, '../public/assets/icons/icon.svg');
const outDir  = path.resolve(__dirname, '../public/assets/icons');
const svg = fs.readFileSync(svgPath);

const targets = [
    { size: 192, name: 'icon-192.png' },
    { size: 512, name: 'icon-512.png' },
    { size: 512, name: 'icon-maskable-512.png', padding: 0.12 },
    { size: 32,  name: 'badge.svg' },
];

await fs.promises.mkdir(outDir, { recursive: true });

for (const t of targets) {
    if (t.name.endsWith('.svg')) continue;
    let pipeline = sharp(svg).resize(t.size, t.size);
    if (t.padding) {
        const innerSize = Math.round(t.size * (1 - t.padding * 2));
        pipeline = sharp(svg)
            .resize(innerSize, innerSize)
            .extend({
                top: Math.round((t.size - innerSize) / 2),
                bottom: Math.round((t.size - innerSize) / 2),
                left: Math.round((t.size - innerSize) / 2),
                right: Math.round((t.size - innerSize) / 2),
                background: { r: 124, g: 77, b: 255, alpha: 1 },
            });
    }
    await pipeline.png({ compressionLevel: 9 }).toFile(path.join(outDir, t.name));
    console.log('  generated', t.name);
}

console.log('done');
