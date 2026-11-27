import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'

const projectRoot = path.resolve(process.cwd())

test('ComicCard: Clean Eye Views on Right & View Formatting (1k, 1m, 1b)', async (t) => {
  const cardFilePath = path.join(projectRoot, 'frontend', 'src', 'components', 'ComicCard.vue')
  assert.ok(fs.existsSync(cardFilePath), 'ComicCard.vue must exist')

  const cardContent = fs.readFileSync(cardFilePath, 'utf8')

  await t.test('1. formattedViews converts 1000 -> 1k, 1000000 -> 1m, 1 billion -> 1b', () => {
    // Check regex pattern for billion formatting
    assert.match(
      cardContent,
      /1_000_000_000/,
      'Must check threshold for 1 billion'
    )
    assert.match(
      cardContent,
      /`\$\{val\}b`/,
      'Must format billion as lowercase "b"'
    )

    // Check regex pattern for million formatting
    assert.match(
      cardContent,
      /1_000_000/,
      'Must check threshold for 1 million'
    )
    assert.match(
      cardContent,
      /`\$\{val\}m`/,
      'Must format million as lowercase "m"'
    )

    // Check regex pattern for thousand formatting
    assert.match(
      cardContent,
      /1_000/,
      'Must check threshold for 1 thousand'
    )
    assert.match(
      cardContent,
      /`\$\{val\}k`/,
      'Must format thousand as lowercase "k"'
    )
  })

  await t.test('2. Chapter is cleanly displayed on cover badge (badge-chapters) without cluttering bottom stats', () => {
    assert.match(
      cardContent,
      /class="badge-chapters"/,
      'Cover must display badge-chapters'
    )
    assert.match(
      cardContent,
      /chaptersLabel/,
      'Must compute chaptersLabel'
    )
    // Bottom comic-stats does not duplicate chapter tag on the left
    assert.doesNotMatch(
      cardContent,
      /class="stat-latest-chapter"/,
      'Must not render stat-latest-chapter on bottom row'
    )
  })

  await t.test('3. Views container is aligned cleanly to the left', () => {
    assert.match(
      cardContent,
      /justify-content:\s*flex-start;/,
      'comic-stats must use justify-content: flex-start'
    )
    assert.match(
      cardContent,
      /stat-icon-svg/,
      'Must render SVG eye icon'
    )
  })

  await t.test('4. Height remains stable at 22px even with empty views', () => {
    assert.match(cardContent, /height:\s*22px/)
    assert.match(cardContent, /min-height:\s*22px/)
    assert.match(cardContent, /class="stat-item-empty"/)
  })
})
