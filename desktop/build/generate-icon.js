const fs = require('fs');
const os = require('os');
const path = require('path');
const sharp = require('sharp');
const pngToIco = require('png-to-ico').default;

const sizes = [16, 32, 48, 256];
const svgPath = path.join(__dirname, 'icon-source.svg');

async function main() {
  const svg = fs.readFileSync(svgPath);
  const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), '360inmystore-icon-'));

  const files = await Promise.all(
    sizes.map(async (size) => {
      const file = path.join(tmpDir, `${size}.png`);
      await sharp(svg, { density: 384 }).resize(size, size).png().toFile(file);
      return file;
    })
  );

  const ico = await pngToIco(files);
  fs.writeFileSync(path.join(__dirname, 'icon.ico'), ico);

  await sharp(svg, { density: 384 }).resize(256, 256).png().toFile(path.join(__dirname, 'icon.png'));

  fs.rmSync(tmpDir, { recursive: true, force: true });
  console.log('icon.ico + icon.png générés dans desktop/build/');
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
