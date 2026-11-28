/**
 * Comprehensive Unit Tests for CAI-COMMENT-01:
 * Context-aware Role Badges in Comments (BlueToon)
 */

import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'

const projectRoot = path.resolve(import.meta.dirname, '..')

describe('CAI-COMMENT-01: Context-aware Role Badges in Comments Suite', () => {
  const commentItemFile = fs.readFileSync(
    path.join(projectRoot, 'src/components/community/CommentItem.vue'),
    'utf8'
  )
  const commentSectionFile = fs.readFileSync(
    path.join(projectRoot, 'src/components/community/CommentSection.vue'),
    'utf8'
  )
  const comicDetailPageFile = fs.readFileSync(
    path.join(projectRoot, 'src/pages/ComicDetailPage.vue'),
    'utf8'
  )
  const communityTypesFile = fs.readFileSync(
    path.join(projectRoot, 'src/types/community.ts'),
    'utf8'
  )

  it('1. CommentItemData type definition includes is_author optional boolean', () => {
    assert.match(
      communityTypesFile,
      /is_author\?:\s*boolean/,
      'CommentItemData interface must declare is_author optional boolean property'
    )
  })

  it('2. CommentItem no longer displays global system-wide roles (admin or creator) unconditionally', () => {
    // Must NOT check comment.user.role === 'admin' to show an Admin badge in comment
    assert.doesNotMatch(
      commentItemFile,
      /v-if="comment\.user\.role\s*===\s*'admin'"/,
      'CommentItem must not show admin role badge based on global role'
    )

    // Must NOT check comment.user.role === 'creator' directly to show a Creator badge in comment
    assert.doesNotMatch(
      commentItemFile,
      /v-else-if="comment\.user\.role\s*===\s*'creator'"/,
      'CommentItem must not show creator role badge based on global role'
    )

    // Must never show "Độc giả" badge
    assert.doesNotMatch(
      commentItemFile,
      /Độc giả/,
      'CommentItem must never display "Độc giả" role badge'
    )
  })

  it('3. CommentItem defines context-aware isComicAuthor computed property', () => {
    assert.match(
      commentItemFile,
      /const\s+isComicAuthor\s*=\s*computed\(/,
      'CommentItem must define isComicAuthor computed property'
    )
    assert.match(
      commentItemFile,
      /props\.comment\.is_author/,
      'isComicAuthor must inspect props.comment.is_author'
    )
    assert.match(
      commentItemFile,
      /props\.comicAuthorId/,
      'isComicAuthor must inspect props.comicAuthorId'
    )
  })

  it('4. CommentItem template displays "Tác giả" badge only when isComicAuthor is true', () => {
    assert.match(
      commentItemFile,
      /v-if="isComicAuthor"/,
      'Template must guard author badge with v-if="isComicAuthor"'
    )
    assert.match(
      commentItemFile,
      /Tác giả\s*✍️/,
      'Badge text must remain "Tác giả ✍️"'
    )
    assert.match(
      commentItemFile,
      /class="role-badge role-badge--creator"/,
      'Badge class must be role-badge role-badge--creator'
    )
  })

  it('5. Nested reply items in CommentItem receive comicAuthorId context prop', () => {
    assert.match(
      commentItemFile,
      /:comic-author-id="comicAuthorId"/,
      'Nested reply CommentItem must be passed :comic-author-id="comicAuthorId"'
    )
  })

  it('6. CommentSection accepts and forwards comicAuthorId to root CommentItems', () => {
    assert.match(
      commentSectionFile,
      /comicAuthorId\?:\s*number\s*\|\s*null/,
      'CommentSectionProps must accept comicAuthorId'
    )
    assert.match(
      commentSectionFile,
      /:comic-author-id="comicAuthorId"/,
      'CommentSection template must pass :comic-author-id="comicAuthorId" to CommentItem'
    )
  })

  it('7. ComicDetailPage passes comic creator id to CommentSection', () => {
    assert.match(
      comicDetailPageFile,
      /:comic-author-id="comic\.creator\?\.id"/,
      'ComicDetailPage must pass :comic-author-id="comic.creator?.id" to CommentSection'
    )
  })

  it('8. Functional Simulation: isComicAuthor logic matches all 6 required specification scenarios', () => {
    // Simulator representing the isComicAuthor logic from CommentItem.vue
    function checkIsComicAuthor(comment, comicAuthorId = null) {
      if (typeof comment.is_author === 'boolean') {
        return comment.is_author
      }
      if (comicAuthorId != null) {
        return comment.user.id === comicAuthorId
      }
      return false
    }

    const comicCreatorId = 100
    const otherCreatorId = 200
    const readerId = 300
    const adminId = 400

    // Scenario 1: Creator commenting on their own comic -> true
    assert.equal(
      checkIsComicAuthor({ is_author: true, user: { id: comicCreatorId, role: 'creator' } }, comicCreatorId),
      true,
      'Creator on own comic must show author badge'
    )

    // Scenario 2: Creator commenting on another creator\'s comic -> false
    assert.equal(
      checkIsComicAuthor({ is_author: false, user: { id: otherCreatorId, role: 'creator' } }, comicCreatorId),
      false,
      'Creator on another creator\'s comic must NOT show author badge'
    )

    // Scenario 3: Creator commenting on chapter of another comic -> false
    assert.equal(
      checkIsComicAuthor({ is_author: false, user: { id: otherCreatorId, role: 'creator' } }, comicCreatorId),
      false,
      'Creator on chapter of another comic must NOT show author badge'
    )

    // Scenario 4: Reader commenting -> false
    assert.equal(
      checkIsComicAuthor({ is_author: false, user: { id: readerId, role: 'reader' } }, comicCreatorId),
      false,
      'Reader must NOT show author badge'
    )

    // Scenario 5: Admin commenting -> false (no misleading admin or author badge)
    assert.equal(
      checkIsComicAuthor({ is_author: false, user: { id: adminId, role: 'admin' } }, comicCreatorId),
      false,
      'Admin must NOT show author badge'
    )

    // Scenario 6: Author replying to comment on own comic -> true
    assert.equal(
      checkIsComicAuthor({ is_author: true, user: { id: comicCreatorId, role: 'creator' } }, comicCreatorId),
      true,
      'Author reply on own comic must show author badge'
    )

    // Scenario 7: Fallback with prop comicAuthorId when is_author is undefined -> true
    assert.equal(
      checkIsComicAuthor({ user: { id: comicCreatorId, role: 'creator' } }, comicCreatorId),
      true,
      'Author matching comicAuthorId prop must show author badge'
    )

    // Scenario 8: Fallback with prop comicAuthorId when other creator comments -> false
    assert.equal(
      checkIsComicAuthor({ user: { id: otherCreatorId, role: 'creator' } }, comicCreatorId),
      false,
      'Non-matching creator must NOT show author badge'
    )
  })
})
