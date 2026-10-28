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

import { canonicalName, classify, labelFor, makeManifest, parseSums } from './make-manifest.mjs'

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
    assert.equal(labelFor('windows', 'x64'), 'Windows 10 and 11, 64-bit')
    assert.equal(labelFor('windows', 'arm64'), 'Windows on Arm')
    assert.equal(labelFor('macos', 'arm64'), 'Apple Silicon')
    assert.equal(labelFor('macos', 'x64'), 'Intel')
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
        label: 'Windows 10 and 11, 64-bit',
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
