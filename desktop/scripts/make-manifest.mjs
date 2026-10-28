#!/usr/bin/env node
/**
 * Describe a release so the website can offer it.
 *
 * The download page on aicountly.com reads one file —
 * `https://downloads.aicountly.com/<product>/manifest.json` — and will not
 * put a button on anything it cannot fully account for. This writes that
 * file, and renames the installers to names that can safely become URLs on
 * the way.
 *
 * The renaming is not tidying. Tauri's NSIS bundle is called
 * `AICOUNTLY Remote_1.0.0_x64-setup.exe`, with a space in it; GitHub rewrites
 * spaces in release asset names, so what comes back out of a release is not
 * reliably what went in. The website refuses a file name that is not a single
 * safe path segment, which is the correct rule and would refuse both. So the
 * published name is derived here from the product, version and architecture
 * rather than carried over from the build, and is the same every release.
 * Authenticode signs a file's contents, not its name, so a rename costs
 * nothing.
 *
 * Hashes are computed from the files themselves rather than read out of a
 * `SHA256SUMS` file. If one is present it is cross-checked and a
 * disagreement is fatal: one of the two is describing a file that is not
 * there any more, and publishing either would be guessing which.
 *
 * There can be more than one. Windows and macOS are built by separate
 * workflows that attach their artefacts to the same GitHub release, so each
 * brings its own sums file under its own name -- if they shared one, the
 * second upload would replace the first and every file it described would
 * look like a mismatch. All of them are read, and all are then replaced by a
 * single `SHA256SUMS.txt` written under the published names, because that is
 * the one a person downloads beside an installer.
 *
 *     node scripts/make-manifest.mjs --dir release --product remote \
 *       --version 1.0.0 --channel production --out release/manifest.json
 */

import { createHash } from 'node:crypto'
import { createReadStream } from 'node:fs'
import { readdir, readFile, rename, rm, stat, writeFile } from 'node:fs/promises'
import { basename, join } from 'node:path'

/** What a person downloads, and what is only ever for an updater. */
const INSTALLABLE = new Set(['installer', 'disk-image'])

/** Names that belong to a release but are not part of it. */
const NOT_A_BUILD = [
  /^SHA256SUMS.*\.txt$/i,
  /^manifest\.json$/i,
  /^UNSIGNED-/i,
  /^INSTALL\.txt$/i,
  /\.blockmap$/i,
  /\.sig$/i,
  /\.pdb$/i,
  /__uninstaller/i,
]

/** How a product's files are named once published. */
const PRODUCT_PREFIX = {
  remote: 'Aicountly-Remote',
  connect: 'Aicountly-Connect',
}

/**
 * What a build artefact is, from its name alone.
 *
 * @param {string} name
 * @returns {{platform: string, arch: string, kind: string} | null}
 */
export function classify(name) {
  if (NOT_A_BUILD.some((pattern) => pattern.test(name))) {
    return null
  }

  const lower = name.toLowerCase()

  const kind = lower.endsWith('.exe')
    ? 'installer'
    : lower.endsWith('.msi')
      ? 'installer'
      : lower.endsWith('.dmg')
        ? 'disk-image'
        : lower.endsWith('.zip') || lower.endsWith('.tar.gz')
          ? 'archive'
          : null

  if (kind === null) {
    return null
  }

  const platform = kind === 'disk-image' || /mac|darwin|osx/.test(lower) ? 'macos' : 'windows'

  // Tauri, electron-builder and Rust target triples all spell the two
  // architectures differently, and a release directory can hold any of them.
  const arch = /arm64|aarch64/.test(lower)
    ? 'arm64'
    : /x64|x86_64|amd64|win64/.test(lower)
      ? 'x64'
      : ''

  return { platform, arch, kind }
}

/**
 * How a build is described on the page, once it has a button.
 *
 * The same words Connect uses (`scripts/make-manifest.mjs` there), because
 * both products' cards are rendered by the same component on the same site
 * and somebody comparing them should not have to work out whether "64-bit"
 * and "64-bit (most PCs)" mean the same machine.
 */
export const ARCH_LABELS = Object.freeze({
  'windows:x64': '64-bit (most PCs)',
  'windows:arm64': 'ARM (Snapdragon, Surface Pro X)',
  'macos:arm64': 'Apple Silicon (M1 and later)',
  'macos:x64': 'Intel',
})

export function labelFor(platform, arch) {
  return ARCH_LABELS[`${platform}:${arch}`] ?? arch
}

/**
 * The name a build is published under: safe in a URL, and the same every
 * release, so a link can be written down.
 */
export function canonicalName({ product, version, platform, arch, kind }) {
  const prefix = PRODUCT_PREFIX[product]
  if (prefix === undefined) {
    throw new Error(`No published naming is defined for product '${product}'.`)
  }
  if (arch === '') {
    throw new Error('A build whose architecture cannot be read from its name cannot be published.')
  }

  const stem = `${prefix}-${version}-${arch}`

  if (kind === 'installer' && platform === 'windows') {
    return `${stem}-Setup.exe`
  }
  if (kind === 'disk-image') {
    return `${stem}.dmg`
  }
  if (kind === 'archive') {
    return `${stem}.zip`
  }

  throw new Error(`No published naming is defined for a ${platform} ${kind}.`)
}

/** SHA-256 of a file, streamed, because these are over a hundred megabytes. */
async function sha256(path) {
  const hash = createHash('sha256')
  for await (const chunk of createReadStream(path)) {
    hash.update(chunk)
  }
  return hash.digest('hex')
}

/** `SHA256SUMS.txt` as a map of name to hash, or an empty map. */
export function parseSums(text) {
  const sums = new Map()
  for (const line of text.split(/\r?\n/)) {
    const match = /^([0-9a-f]{64})\s+\*?(.+?)\s*$/i.exec(line)
    if (match !== null) {
      sums.set(basename(match[2]), match[1].toLowerCase())
    }
  }
  return sums
}

/** Windows before macOS, x64 before arm64: the order the page renders in. */
function compare(a, b) {
  const platform = { windows: 0, macos: 1 }
  const arch = { x64: 0, arm64: 1 }
  return (
    (platform[a.platform] ?? 9) - (platform[b.platform] ?? 9) ||
    (arch[a.arch] ?? 9) - (arch[b.arch] ?? 9) ||
    a.file.localeCompare(b.file)
  )
}

/**
 * Rename every build in `dir` to its published name, hash it, and return the
 * manifest describing what is now there.
 */
export async function makeManifest({ dir, product, version, channel, signed }) {
  const names = await readdir(dir)

  const sumsFiles = names.filter((name) => /^SHA256SUMS.*\.txt$/i.test(name))

  const claimed = new Map()
  for (const name of sumsFiles) {
    for (const [file, hash] of parseSums(await readFile(join(dir, name), 'utf8'))) {
      const existing = claimed.get(file)
      if (existing !== undefined && existing !== hash) {
        throw new Error(
          `Two checksum files disagree about ${file}: ${existing} and ${hash}. ` +
            'Refusing to publish either.',
        )
      }
      claimed.set(file, hash)
    }
  }

  const files = []

  for (const name of names.sort()) {
    const what = classify(name)
    if (what === null) {
      continue
    }

    const from = join(dir, name)
    if (!(await stat(from)).isFile()) {
      continue
    }

    const published = canonicalName({ product, version, ...what })
    const to = join(dir, published)

    // Two builds that would publish under one name means one of them is
    // about to be silently overwritten, and the manifest would describe
    // whichever won. Neither is publishable until somebody looks.
    const clash = files.find((file) => file.file === published)
    if (clash !== undefined) {
      throw new Error(
        `${name} and ${clash.source} would both be published as ${published}. ` +
          'Refusing to overwrite one with the other.',
      )
    }

    const digest = await sha256(from)

    const claim = claimed.get(name)
    if (claim !== undefined && claim !== digest) {
      throw new Error(
        `SHA256SUMS.txt says ${name} is ${claim} but it is ${digest}. ` +
          'One of them is describing a file that is no longer there; refusing to publish either.',
      )
    }

    if (to !== from) {
      await rename(from, to)
    }

    files.push({
      source: name,
      platform: what.platform,
      arch: what.arch,
      kind: what.kind,
      label: labelFor(what.platform, what.arch),
      file: published,
      size: (await stat(to)).size,
      sha256: digest,
      signed,
    })
  }

  if (!files.some((file) => INSTALLABLE.has(file.kind))) {
    throw new Error(`No installer or disk image was found in ${dir}; there is nothing to publish.`)
  }

  files.sort(compare)

  // `source` was only ever for the collision message above; the manifest
  // describes what is published, not what the build called it.
  for (const file of files) {
    delete file.source
  }

  // The per-platform ones go, so what is uploaded is one checksum file under
  // the published names: a person downloading an installer gets a sums file
  // that names the installer they downloaded.
  for (const name of sumsFiles) {
    if (name !== 'SHA256SUMS.txt') {
      await rm(join(dir, name))
    }
  }

  // Rewritten for the published names, so a checksum file downloaded beside
  // an installer names the installer that was downloaded.
  await writeFile(
    join(dir, 'SHA256SUMS.txt'),
    files.map((file) => `${file.sha256}  ${file.file}`).join('\n') + '\n',
    'utf8',
  )

  return {
    product,
    version,
    channel,
    generated: new Date().toISOString().replace(/\.\d{3}Z$/, 'Z'),
    files,
  }
}

function arg(name, fallback = undefined) {
  const index = process.argv.indexOf(`--${name}`)
  if (index !== -1 && index + 1 < process.argv.length) {
    return process.argv[index + 1]
  }
  if (fallback === undefined) {
    throw new Error(`--${name} is required.`)
  }
  return fallback
}

// `import.meta.main` is Node 24; this is the form that works on 22 as well.
if (process.argv[1] !== undefined && process.argv[1].endsWith('make-manifest.mjs')) {
  const dir = arg('dir')
  const product = arg('product')
  const version = arg('version')
  const channel = arg('channel', 'production')
  const out = arg('out', join(dir, 'manifest.json'))

  // Only a channel that signs may claim to be signed. The release workflow
  // refuses to produce an unsigned build on these channels at all, so this is
  // the second lock rather than the first.
  const signed = channel !== 'sandbox'

  const manifest = await makeManifest({ dir, product, version, channel, signed })

  await writeFile(out, JSON.stringify(manifest, null, 2) + '\n', 'utf8')

  console.log(`${out}:`)
  console.log(JSON.stringify(manifest, null, 2))
}
