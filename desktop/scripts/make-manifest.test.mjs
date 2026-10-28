/**
 * What a release is allowed to tell the website about itself.
 *
 * The download page puts a button on whatever this file describes, so a
 * mistake here is a broken download or, worse, a working download of the
 * wrong thing. These cover the two jobs: naming a build so it can be a URL,
 * and refusing to describe a release that does not add up.
 *
 * Run with `npm run test:scripts` (node's own runner — vitest in this package
 * only looks at the window's sources, and this is a build script).
 */

import assert from 'node:assert/strict'
import { createHash } from 'node:crypto'
import { mkdtemp, readdir, readFile, writeFile } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { after, describe, it } from 'node:test'

import { canonicalName, classify, labelFor, makeManifest, parseSums, peIsSigned } from './make-manifest.mjs'

const made = []

async function release(files) {
  const dir = await mkdtemp(join(tmpdir(), 'aic-release-'))
  made.push(dir)
  for (const [name, body] of Object.entries(files)) {
    await writeFile(join(dir, name), body)
  }
  return dir
}

const sha = (body) => createHash('sha256').update(body).digest('hex')

after(async () => {
  const { rm } = await import('node:fs/promises')
  await Promise.all(made.map((dir) => rm(dir, { recursive: true, force: true })))
})

/**
 * A PE just real enough to be read: DOS stub, PE signature, an optional
 * header of the given flavour, and a certificate-table entry whose size is
 * the only thing under test.
 */
function fakePe({ certSize = 0, plus = true } = {}) {
  const buf = Buffer.alloc(512)
  buf.write('MZ', 0, 'ascii')
  buf.writeUInt32LE(0x80, 0x3c)
  buf.writeUInt32LE(0x00004550, 0x80) // "PE\0\0"
  const optional = 0x80 + 24
  buf.writeUInt16LE(plus ? 0x20b : 0x10b, optional)
  const directories = optional + (plus ? 112 : 96)
  const entry = directories + 4 * 8 // entry 4: the certificate table
  buf.writeUInt32LE(certSize === 0 ? 0 : 0x1000, entry) // address
  buf.writeUInt32LE(certSize, entry + 4) // size
  return buf
}

describe('peIsSigned', () => {
  it('reads a signature out of the certificate table, in either PE flavour', () => {
    for (const plus of [true, false]) {
      assert.equal(peIsSigned(fakePe({ certSize: 9000, plus })), true, `plus=${plus}`)
      assert.equal(peIsSigned(fakePe({ certSize: 0, plus })), false, `plus=${plus}`)
    }
  })

  /**
   * null rather than false: a .dmg is not unsigned just because it is not a
   * PE, and the caller has to be able to tell the two apart.
   */
  it('says nothing about bytes that are not a PE', () => {
    assert.equal(peIsSigned(Buffer.from('not an executable at all, really')), null)
    assert.equal(peIsSigned(Buffer.alloc(0)), null)
    assert.equal(peIsSigned(Buffer.from('MZ')), null, 'a DOS header and nothing else')

    const truncated = fakePe({ certSize: 9000 }).subarray(0, 0x90)
    assert.equal(peIsSigned(truncated), null, 'headers that stop early')

    const notPe = fakePe()
    notPe.writeUInt32LE(0xdeadbeef, 0x80)
    assert.equal(peIsSigned(notPe), null, 'MZ but no PE signature')
  })
})

describe('classify', () => {
  it('reads the platform, architecture and kind out of a build name', () => {
    assert.deepEqual(classify('AICOUNTLY Remote_1.0.0_x64-setup.exe'), {
      platform: 'windows',
      arch: 'x64',
      kind: 'installer',
    })
    assert.deepEqual(classify('AICOUNTLY.Remote_1.0.0_arm64-setup.exe'), {
      platform: 'windows',
      arch: 'arm64',
      kind: 'installer',
    })
    assert.deepEqual(classify('AICOUNTLY Remote_1.0.0_aarch64.dmg'), {
      platform: 'macos',
      arch: 'arm64',
      kind: 'disk-image',
    })
    assert.deepEqual(classify('Connect-0.1.0-mac-x64.zip'), {
      platform: 'macos',
      arch: 'x64',
      kind: 'archive',
    })
  })

  it('ignores what belongs to a release without being part of it', () => {
    for (const name of [
      'SHA256SUMS.txt',
      'manifest.json',
      'UNSIGNED-DEVELOPMENT-BUILD.txt',
      'INSTALL.txt',
      'Remote_1.0.0_x64-setup.exe.blockmap',
      'Remote.exe.sig',
      'AICOUNTLY Remote_1.0.0_x64.__uninstaller.exe',
      'notes.md',
    ]) {
      assert.equal(classify(name), null, `${name} is not a build`)
    }
  })
})

describe('canonicalName', () => {
  it('derives a name that is safe in a URL and the same every release', () => {
    assert.equal(
      canonicalName({ product: 'remote', version: '1.0.0', platform: 'windows', arch: 'x64', kind: 'installer' }),
      'Aicountly-Remote-1.0.0-x64-Setup.exe',
    )
    assert.equal(
      canonicalName({ product: 'remote', version: '1.2.0-beta.1', platform: 'macos', arch: 'arm64', kind: 'disk-image' }),
      'Aicountly-Remote-1.2.0-beta.1-arm64.dmg',
    )
  })

  /**
   * The website refuses a file name that is not a single safe path segment,
   * which is what makes these names the point rather than a nicety.
   */
  it('produces only names the website will accept', () => {
    for (const arch of ['x64', 'arm64']) {
      for (const [platform, kind] of [
        ['windows', 'installer'],
        ['macos', 'disk-image'],
      ]) {
        const name = canonicalName({ product: 'remote', version: '1.0.0', platform, arch, kind })
        assert.match(name, /^[A-Za-z0-9][A-Za-z0-9._+-]*$/, `${name} must be one safe segment`)
      }
    }
  })

  it('refuses a build whose architecture it cannot read', () => {
    assert.throws(
      () => canonicalName({ product: 'remote', version: '1.0.0', platform: 'windows', arch: '', kind: 'installer' }),
      /architecture/,
    )
  })

  it('refuses a product it has no naming for', () => {
    assert.throws(
      () => canonicalName({ product: 'ledger', version: '1.0.0', platform: 'windows', arch: 'x64', kind: 'installer' }),
      /naming is defined/,
    )
  })
})

describe('labelFor', () => {
  it('names a build the way somebody choosing between two would', () => {
    assert.equal(labelFor('windows', 'x64'), '64-bit (most PCs)')
    assert.equal(labelFor('windows', 'arm64'), 'ARM (Snapdragon, Surface Pro X)')
    assert.equal(labelFor('macos', 'arm64'), 'Apple Silicon (M1 and later)')
    assert.equal(labelFor('macos', 'x64'), 'Intel')
    assert.equal(labelFor('linux', 'x64'), 'x64', 'an unlabelled pair falls back to the architecture')
  })
})

describe('parseSums', () => {
  it('reads both the plain and the binary-marked forms', () => {
    const sums = parseSums(`${'a'.repeat(64)}  one.exe\n${'b'.repeat(64)} *two.dmg\nrubbish\n`)
    assert.equal(sums.get('one.exe'), 'a'.repeat(64))
    assert.equal(sums.get('two.dmg'), 'b'.repeat(64))
    assert.equal(sums.size, 2)
  })

  it('keeps only the file name, so a path in the sums file cannot travel', () => {
    const sums = parseSums(`${'c'.repeat(64)}  release/nested/one.exe\n`)
    assert.deepEqual([...sums.keys()], ['one.exe'])
  })
})

describe('makeManifest', () => {
  it('renames the builds, hashes them, and describes what is now there', async () => {
    const body = 'installer bytes'
    const dir = await release({
      'AICOUNTLY Remote_1.0.0_x64-setup.exe': body,
      'SHA256SUMS.txt': `${sha(body)}  AICOUNTLY Remote_1.0.0_x64-setup.exe\n`,
    })

    const manifest = await makeManifest({
      dir,
      product: 'remote',
      version: '1.0.0',
      channel: 'production',
      signed: true,
    })

    assert.equal(manifest.product, 'remote')
    assert.equal(manifest.version, '1.0.0')
    assert.equal(manifest.channel, 'production')
    assert.match(manifest.generated, /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/)

    assert.deepEqual(manifest.files, [
      {
        platform: 'windows',
        arch: 'x64',
        kind: 'installer',
        label: '64-bit (most PCs)',
        file: 'Aicountly-Remote-1.0.0-x64-Setup.exe',
        size: body.length,
        sha256: sha(body),
        signed: true,
      },
    ])

    // The file really was renamed, and the checksum file now names the
    // installer somebody will actually have downloaded.
    const present = await readdir(dir)
    assert.ok(present.includes('Aicountly-Remote-1.0.0-x64-Setup.exe'))
    assert.ok(!present.includes('AICOUNTLY Remote_1.0.0_x64-setup.exe'))
    assert.equal(
      await readFile(join(dir, 'SHA256SUMS.txt'), 'utf8'),
      `${sha(body)}  Aicountly-Remote-1.0.0-x64-Setup.exe\n`,
    )
  })

  it('orders Windows before macOS and x64 before arm64, whatever the directory said', async () => {
    const dir = await release({
      'Remote_1.0.0_arm64.dmg': 'a',
      'Remote_1.0.0_x64-setup.exe': 'b',
      'Remote_1.0.0_arm64-setup.exe': 'c',
      'Remote_1.0.0_x64.dmg': 'd',
    })

    const manifest = await makeManifest({
      dir,
      product: 'remote',
      version: '1.0.0',
      channel: 'beta',
      signed: true,
    })

    assert.deepEqual(
      manifest.files.map((file) => `${file.platform}-${file.arch}`),
      ['windows-x64', 'windows-arm64', 'macos-x64', 'macos-arm64'],
    )
  })

  /**
   * The release workflow writes SHA256SUMS.txt immediately after it builds.
   * If it disagrees with the files by the time they are published, something
   * replaced a file in between, and which of the two is right is exactly what
   * nobody can tell from here.
   */
  it('refuses to publish when SHA256SUMS.txt disagrees with the file', async () => {
    const dir = await release({
      'Remote_1.0.0_x64-setup.exe': 'the real bytes',
      'SHA256SUMS.txt': `${'f'.repeat(64)}  Remote_1.0.0_x64-setup.exe\n`,
    })

    await assert.rejects(
      makeManifest({ dir, product: 'remote', version: '1.0.0', channel: 'production', signed: true }),
      /refusing to publish/i,
    )
  })

  it('refuses to publish when two builds would take the same name', async () => {
    const dir = await release({
      'AICOUNTLY Remote_1.0.0_x64-setup.exe': 'one',
      'aicountly-remote-x64.exe': 'another',
    })

    await assert.rejects(
      makeManifest({ dir, product: 'remote', version: '1.0.0', channel: 'production', signed: true }),
      /both be published as/,
    )
  })

  /**
   * Windows and macOS are built by separate workflows that attach to the same
   * GitHub release, so each brings its own sums file. Both are read, and both
   * are replaced by one written under the published names.
   */
  it('reads every checksum file, and leaves one behind under the published names', async () => {
    const win = 'windows installer bytes'
    const mac = 'mac disk image bytes'
    const dir = await release({
      'AICOUNTLY Remote_1.0.0_x64-setup.exe': win,
      'AICOUNTLY Remote_1.0.0_aarch64.dmg': mac,
      'SHA256SUMS.txt': `${sha(win)}  AICOUNTLY Remote_1.0.0_x64-setup.exe\n`,
      'SHA256SUMS-macos.txt': `${sha(mac)}  AICOUNTLY Remote_1.0.0_aarch64.dmg\n`,
    })

    const manifest = await makeManifest({
      dir,
      product: 'remote',
      version: '1.0.0',
      channel: 'production',
      signed: true,
    })

    assert.deepEqual(
      manifest.files.map((file) => file.file),
      ['Aicountly-Remote-1.0.0-x64-Setup.exe', 'Aicountly-Remote-1.0.0-arm64.dmg'],
    )

    const present = await readdir(dir)
    assert.ok(!present.includes('SHA256SUMS-macos.txt'), 'the per-platform sums file is gone')
    assert.equal(
      await readFile(join(dir, 'SHA256SUMS.txt'), 'utf8'),
      `${sha(win)}  Aicountly-Remote-1.0.0-x64-Setup.exe\n` +
        `${sha(mac)}  Aicountly-Remote-1.0.0-arm64.dmg\n`,
    )
  })

  it('refuses to publish when two checksum files disagree about one file', async () => {
    const body = 'bytes'
    const dir = await release({
      'Remote_1.0.0_x64-setup.exe': body,
      'SHA256SUMS.txt': `${sha(body)}  Remote_1.0.0_x64-setup.exe\n`,
      'SHA256SUMS-macos.txt': `${'e'.repeat(64)}  Remote_1.0.0_x64-setup.exe\n`,
    })

    await assert.rejects(
      makeManifest({ dir, product: 'remote', version: '1.0.0', channel: 'production', signed: true }),
      /disagree about/,
    )
  })

  /**
   * The field that decides whether the website offers a build at all, and
   * whether it warns beside it. A caller that is wrong about it must not be
   * able to make an unsigned remote-access agent look signed.
   */
  it('reads signing off the Windows binary, not off what it was told', async () => {
    const dir = await release({})
    await writeFile(join(dir, 'Remote_1.0.0_x64-setup.exe'), fakePe({ certSize: 0 }))

    const manifest = await makeManifest({
      dir,
      product: 'remote',
      version: '1.0.0',
      channel: 'production',
      signed: true,
    })

    assert.equal(manifest.files[0].signed, false, 'the file has no certificate table')
  })

  it('believes a signed binary for the same reason', async () => {
    const dir = await release({})
    await writeFile(join(dir, 'Remote_1.0.0_x64-setup.exe'), fakePe({ certSize: 9000 }))

    const manifest = await makeManifest({
      dir,
      product: 'remote',
      version: '1.0.0',
      channel: 'sandbox',
      signed: false,
    })

    assert.equal(manifest.files[0].signed, true)
  })

  /** A .dmg cannot answer for itself, so the caller's word stands. */
  it('takes the caller at their word for a disk image', async () => {
    const dir = await release({ 'Remote_1.0.0_aarch64.dmg': 'disk image bytes' })

    const manifest = await makeManifest({
      dir,
      product: 'remote',
      version: '1.0.0',
      channel: 'production',
      signed: true,
    })

    assert.equal(manifest.files[0].signed, true)
  })

  it('refuses a directory with nothing installable in it', async () => {
    const dir = await release({ 'SHA256SUMS.txt': '', 'INSTALL.txt': 'read me' })

    await assert.rejects(
      makeManifest({ dir, product: 'remote', version: '1.0.0', channel: 'production', signed: true }),
      /nothing to publish/,
    )
  })

  /** A sandbox build is not signed, and must not be able to say it is. */
  it('records signing as it is told, not as it would prefer', async () => {
    const dir = await release({ 'Remote_1.0.0_x64-setup.exe': 'bytes' })

    const manifest = await makeManifest({
      dir,
      product: 'remote',
      version: '1.0.0',
      channel: 'sandbox',
      signed: false,
    })

    assert.equal(manifest.files[0].signed, false)
  })
})
