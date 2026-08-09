#!/usr/bin/env node
// Builds dist/bento-pronto.php from template.php by fetching the CURRENT
// state of the bento fork (branch below), building the actual app, and
// splicing the result in — the exact same technique bento-moodle-tools'
// own build.mjs already uses for its pptx-to-bento.html, just producing a
// .php file instead: this project needs a real PHP-capable server (the
// whole point is the built-in image proxy that a static host can't run),
// so there's no GitHub Pages deploy step here — the CI workflow attaches
// the built file to a GitHub Release instead; whoever wants it downloads
// that one file and drops it on their own server.
//
// Fails loudly (non-zero exit) on any error along the way, deliberately:
// the release step only runs on success, so a broken bento build here just
// means "nothing gets released this run" — the previous release stays put.

import { execSync } from 'node:child_process'
import { mkdtempSync, readFileSync, writeFileSync, rmSync, mkdirSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

const BENTO_REPO = process.env.BENTO_REPO || 'https://github.com/lasjoh-toho/bento.git'
const BENTO_BRANCH = process.env.BENTO_BRANCH || 'moodle-and-editor-enhancements'

function run(cmd, cwd) {
  console.log(`$ ${cmd}`)
  execSync(cmd, { cwd, stdio: 'inherit', shell: '/bin/bash' })
}

function runCapture(cmd, cwd) {
  return execSync(cmd, { cwd, shell: '/bin/bash' }).toString()
}

const work = mkdtempSync(join(tmpdir(), 'bento-pronto-build-'))
console.log('Working dir:', work)

try {
  // ---- 1. fetch the current bento fork ----
  run(`git clone --depth 1 --branch ${BENTO_BRANCH} ${BENTO_REPO} bento`, work)
  const bentoDir = join(work, 'bento')
  const shortSha = runCapture('git rev-parse --short HEAD', bentoDir).trim()

  // ---- 2. build slides ----
  const slidesDir = join(bentoDir, 'slides')
  run('npm install --no-audit --no-fund', slidesDir)
  run('npm run build:single', slidesDir) // runs tsc -b internally — a type error fails here, non-zero exit
  const shellHtml = readFileSync(join(slidesDir, 'dist-single', 'Bento_Slides.bento.html'), 'utf8')
  if (!shellHtml.includes('id="bento-doc"')) {
    throw new Error('Built shell is missing the #bento-doc anchor — refusing to release something this template could not splice into later.')
  }

  // ---- 3. extract the starter/demo deck straight from the built-from source ----
  const dumpScript = join(import.meta.dirname, 'scripts', 'dump-starter.mjs')
  const srcDir = join(bentoDir, 'slides', 'src')
  run(`npx --yes tsx "${dumpScript}" "${srcDir}"`, work)
  const demoJson = readFileSync(join(srcDir, '__starter-dump.json'), 'utf8')
  const demoDoc = JSON.parse(demoJson) // throws if dump-starter.mjs produced anything malformed
  if (demoDoc.format !== 'bento/slides' || !Array.isArray(demoDoc.slides) || !demoDoc.slides.length) {
    throw new Error('Extracted starter deck does not look like a valid bento/slides document — refusing to release.')
  }

  // ---- 4. splice into the PHP template ----
  const templatePath = join(import.meta.dirname, 'template.php')
  let php = readFileSync(templatePath, 'utf8')

  const shellB64 = Buffer.from(shellHtml, 'utf8').toString('base64')
  const demoB64 = Buffer.from(JSON.stringify(demoDoc), 'utf8').toString('base64')
  const buildDate = new Date().toISOString().slice(0, 10)

  const replacements = [
    ['__BENTO_SHELL_B64__', shellB64],
    ['__BENTO_DEMO_B64__', demoB64],
    ['__BENTO_VERSION__', `${BENTO_BRANCH}@${shortSha}`],
    ['__BENTO_BUILD_DATE__', buildDate],
  ]
  for (const [needle, value] of replacements) {
    if (!php.includes(needle)) throw new Error(`Template is missing placeholder ${needle} — check template.php wasn't edited out of sync with this script.`)
    php = php.split(needle).join(value)
  }

  mkdirSync(join(import.meta.dirname, 'dist'), { recursive: true })
  writeFileSync(join(import.meta.dirname, 'dist', 'bento-pronto.php'), php)
  console.log(`\nBuilt dist/bento-pronto.php from ${BENTO_BRANCH}@${shortSha} (${(php.length / 1024 / 1024).toFixed(2)} MB)`)
} finally {
  rmSync(work, { recursive: true, force: true })
}
