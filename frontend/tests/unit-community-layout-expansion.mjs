/**
 * Unit Test Suite for CAI-COMMUNITY-02:
 * Mở rộng chiều ngang trang Cộng đồng (BlueToon / ComicAI)
 */

import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'

const projectRoot = path.resolve(import.meta.dirname, '..')

describe('CAI-COMMUNITY-02: Mở rộng chiều ngang trang Cộng đồng Suite', () => {
  const communityPageFile = fs.readFileSync(path.join(projectRoot, 'src/views/CommunityPage.vue'), 'utf8')

  it('1. CommunityPage does not use restrictive global .container class', () => {
    // Should NOT have class="container community-container" which restricts to 1200px
    assert.doesNotMatch(
      communityPageFile,
      /class="container community-container"/,
      'CommunityPage must not use global .container class which limits max-width to 1200px'
    )
    assert.match(
      communityPageFile,
      /class="community-container"/,
      'CommunityPage must use standalone .community-container class'
    )
  })

  it('2. .community-container uses fluid responsive max-width across viewports up to 2560px', () => {
    assert.match(
      communityPageFile,
      /\.community-container\s*\{[^}]*max-width:\s*95vw/,
      '.community-container must have fluid default max-width (e.g. 95vw)'
    )
    assert.match(
      communityPageFile,
      /@media\s*\(\s*min-width:\s*1440px\s*\)\s*\{[^}]*\.community-container\s*\{[^}]*max-width:/,
      '.community-container must expand on 1440px viewport'
    )
    assert.match(
      communityPageFile,
      /@media\s*\(\s*min-width:\s*1920px\s*\)\s*\{[^}]*\.community-container\s*\{[^}]*max-width:/,
      '.community-container must expand on 1920px viewport'
    )
    assert.match(
      communityPageFile,
      /@media\s*\(\s*min-width:\s*2560px\s*\)\s*\{[^}]*\.community-container\s*\{[^}]*max-width:/,
      '.community-container must expand on 2560px viewport'
    )
    assert.match(
      communityPageFile,
      /margin-inline:\s*auto/,
      '.community-container must center horizontally with margin-inline: auto'
    )
    assert.match(
      communityPageFile,
      /padding-inline:\s*clamp\(/,
      '.community-container must use responsive padding-inline'
    )
  })

  it('3. .community-layout-grid allows center feed column to expand dynamically with minmax(0, 1fr)', () => {
    assert.match(
      communityPageFile,
      /grid-template-columns:\s*310px\s+minmax\(0,\s*1fr\)\s+300px/,
      'Grid template must define left sidebar, flexible center column, and right sidebar'
    )
  })

  it('4. Preserves 3-column architecture, components, and vertical spacing', () => {
    assert.match(communityPageFile, /<CommunityRankingsSidebar\s*\/>/, 'Must preserve CommunityRankingsSidebar in left column')
    assert.match(communityPageFile, /<CommunityConnectionsSidebar\s*\/>/, 'Must preserve CommunityConnectionsSidebar in right column')
    assert.match(communityPageFile, /class="controls-bar"/, 'Must preserve controls-bar')
    assert.match(communityPageFile, /<PostCard/, 'Must preserve PostCard list')
    assert.match(communityPageFile, /gap:\s*var\(--spacing-xl\)/, 'Must preserve vertical gap between banner and content')
  })

  it('5. Prevents horizontal overflow without hardcoded 100vw', () => {
    assert.match(communityPageFile, /overflow-x:\s*clip/, 'Community page must clip horizontal overflow')
    assert.doesNotMatch(communityPageFile, /width:\s*100vw\s*;/, 'Must not use unconstrained width: 100vw which causes scrollbars')
  })
})
